<?php
/**
 * Overtime Calculator
 * ERP v2 - HR Module
 * 
 * Handles OT calculations, approval workflow, and payment tracking
 */

class OvertimeCalculator {
    private PDO $db;
    private SalaryCalculator $salaryCalc;
    private AuditLog $audit;
    
    public function __construct() {
        $this->db = getDB();
        $this->salaryCalc = new SalaryCalculator();
        $this->audit = new AuditLog();
    }
    
    /**
     * Calculate OT amount based on hours and type
     */
    public function calculateOTAmount(int $peopleId, float $hours, string $otType = 'Weekday'): array {
        $salary = $this->salaryCalc->getCurrentSalary($peopleId);
        if (!$salary) {
            return ['success' => false, 'error' => 'No salary record found'];
        }
        
        $hourlyRate = $this->salaryCalc->getHourlyRate($peopleId);
        
        // Get multiplier based on OT type
        $multiplier = match($otType) {
            'Holiday' => (float) $salary['holiday_rate_multiplier'],
            'Weekend' => (float) $salary['ot_rate_multiplier'],
            'Weekday' => (float) $salary['ot_rate_multiplier'],
            default => 1.5
        };
        
        $otAmount = $hourlyRate * $hours * $multiplier;
        
        return [
            'success' => true,
            'hourly_rate' => $hourlyRate,
            'multiplier' => $multiplier,
            'ot_amount' => $otAmount,
            'hours' => $hours
        ];
    }
    
    /**
     * Submit OT request
     */
    public function submitOT(array $data): array {
        try {
            $this->db->beginTransaction();
            
            // Calculate OT amount
            $calc = $this->calculateOTAmount(
                (int) $data['people_id'],
                (float) $data['total_hours'],
                $data['ot_type']
            );
            
            if (!$calc['success']) {
                throw new Exception($calc['error']);
            }
            
            $stmt = $this->db->prepare("
                INSERT INTO people_overtime (
                    people_id, work_date, ot_type,
                    start_time, end_time, break_minutes, total_hours,
                    base_rate, multiplier, ot_amount,
                    job_id, timesheet_id, notes,
                    status, created_by
                ) VALUES (
                    ?, ?, ?,
                    ?, ?, ?, ?,
                    ?, ?, ?,
                    ?, ?, ?,
                    'Pending', ?
                )
            ");
            
            $stmt->execute([
                $data['people_id'],
                $data['work_date'],
                $data['ot_type'],
                $data['start_time'],
                $data['end_time'],
                $data['break_minutes'] ?? 0,
                $calc['hours'],
                $calc['hourly_rate'],
                $calc['multiplier'],
                $calc['ot_amount'],
                $data['job_id'] ?? null,
                $data['timesheet_id'] ?? null,
                $data['notes'] ?? null,
                $_SESSION['user_id']
            ]);
            
            $otId = $this->db->lastInsertId();
            
            $this->audit->log('create', 'OVERTIME', $otId, null, $data);
            
            $this->db->commit();
            
            return ['success' => true, 'id' => $otId, 'ot_amount' => $calc['ot_amount']];
            
        } catch (Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Approve OT request
     */
    public function approveOT(int $otId, ?string $notes = null): array {
        try {
            $this->db->beginTransaction();
            
            $stmt = $this->db->prepare("
                UPDATE people_overtime 
                SET status = 'Approved', 
                    approved_by = ?, 
                    approved_at = NOW(),
                    notes = CONCAT(COALESCE(notes, ''), '\n[Approved] ', COALESCE(?, ''))
                WHERE id = ? AND status = 'Pending'
            ");
            
            $stmt->execute([$_SESSION['user_id'], $notes, $otId]);
            
            if ($stmt->rowCount() === 0) {
                throw new Exception('OT not found or already processed');
            }
            
            $this->audit->log('approve', 'OVERTIME', $otId, ['status' => 'Pending'], ['status' => 'Approved']);
            
            $this->db->commit();
            
            return ['success' => true];
            
        } catch (Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Reject OT request
     */
    public function rejectOT(int $otId, string $reason): array {
        try {
            $this->db->beginTransaction();
            
            $stmt = $this->db->prepare("
                UPDATE people_overtime 
                SET status = 'Rejected', 
                    approved_by = ?, 
                    approved_at = NOW(),
                    rejection_reason = ?
                WHERE id = ? AND status = 'Pending'
            ");
            
            $stmt->execute([$_SESSION['user_id'], $reason, $otId]);
            
            if ($stmt->rowCount() === 0) {
                throw new Exception('OT not found or already processed');
            }
            
            $this->audit->log('reject', 'OVERTIME', $otId, ['status' => 'Pending'], ['status' => 'Rejected', 'reason' => $reason]);
            
            $this->db->commit();
            
            return ['success' => true];
            
        } catch (Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Get OT records for approval
     */
    public function getPendingOT(?int $peopleId = null): array {
        $sql = "
            SELECT 
                ot.*,
                p.name as people_name, p.position,
                j.job_number,
                u.full_name as created_by_name
            FROM people_overtime ot
            JOIN people p ON ot.people_id = p.id
            LEFT JOIN jobs j ON ot.job_id = j.id
            LEFT JOIN users u ON ot.created_by = u.id
            WHERE ot.status = 'Pending'
        ";
        
        if ($peopleId) {
            $sql .= " AND ot.people_id = ?";
            $stmt = $this->db->prepare($sql . " ORDER BY ot.work_date DESC, ot.created_at DESC");
            $stmt->execute([$peopleId]);
        } else {
            $stmt = $this->db->query($sql . " ORDER BY ot.work_date DESC, ot.created_at DESC");
        }
        
        return $stmt->fetchAll();
    }
    
    /**
     * Get approved OT for payroll period
     */
    public function getApprovedOTForPeriod(int $peopleId, string $startDate, string $endDate): array {
        $stmt = $this->db->prepare("
            SELECT * FROM people_overtime
            WHERE people_id = ? 
            AND work_date BETWEEN ? AND ?
            AND status = 'Approved'
            ORDER BY work_date
        ");
        $stmt->execute([$peopleId, $startDate, $endDate]);
        return $stmt->fetchAll();
    }
    
    /**
     * Calculate total OT amount for period
     */
    public function getTotalOTAmount(int $peopleId, string $startDate, string $endDate): float {
        $stmt = $this->db->prepare("
            SELECT COALESCE(SUM(ot_amount), 0) as total
            FROM people_overtime
            WHERE people_id = ? 
            AND work_date BETWEEN ? AND ?
            AND status = 'Approved'
        ");
        $stmt->execute([$peopleId, $startDate, $endDate]);
        $result = $stmt->fetch();
        return (float) ($result['total'] ?? 0);
    }
    
    /**
     * Calculate total OT hours for period
     */
    public function getTotalOTHours(int $peopleId, string $startDate, string $endDate): float {
        $stmt = $this->db->prepare("
            SELECT COALESCE(SUM(total_hours), 0) as total
            FROM people_overtime
            WHERE people_id = ? 
            AND work_date BETWEEN ? AND ?
            AND status = 'Approved'
        ");
        $stmt->execute([$peopleId, $startDate, $endDate]);
        $result = $stmt->fetch();
        return (float) ($result['total'] ?? 0);
    }
    
    /**
     * Mark OT as paid (called from payroll)
     */
    public function markOTAsPaid(array $otIds): array {
        try {
            $this->db->beginTransaction();
            
            $placeholders = implode(',', array_fill(0, count($otIds), '?'));
            $stmt = $this->db->prepare("
                UPDATE people_overtime 
                SET status = 'Paid'
                WHERE id IN ($placeholders) AND status = 'Approved'
            ");
            $stmt->execute($otIds);
            
            $this->audit->log('update', 'OVERTIME', null, null, ['action' => 'mark_paid', 'ot_ids' => $otIds]);
            
            $this->db->commit();
            
            return ['success' => true];
            
        } catch (Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Get OT summary report
     */
    public function getOTSummary(string $startDate, string $endDate, ?int $peopleId = null): array {
        $sql = "
            SELECT 
                p.id, p.name, p.position,
                COUNT(ot.id) as ot_count,
                SUM(ot.total_hours) as total_hours,
                SUM(ot.ot_amount) as total_amount,
                SUM(CASE WHEN ot.ot_type = 'Weekday' THEN ot.total_hours ELSE 0 END) as weekday_hours,
                SUM(CASE WHEN ot.ot_type = 'Weekend' THEN ot.total_hours ELSE 0 END) as weekend_hours,
                SUM(CASE WHEN ot.ot_type = 'Holiday' THEN ot.total_hours ELSE 0 END) as holiday_hours
            FROM people p
            LEFT JOIN people_overtime ot ON p.id = ot.people_id 
                AND ot.work_date BETWEEN ? AND ?
                AND ot.status IN ('Approved', 'Paid')
            WHERE p.status = 'Active'
        ";
        
        if ($peopleId) {
            $sql .= " AND p.id = ?";
            $stmt = $this->db->prepare($sql . " GROUP BY p.id ORDER BY total_amount DESC");
            $stmt->execute([$startDate, $endDate, $peopleId]);
        } else {
            $stmt = $this->db->prepare($sql . " GROUP BY p.id ORDER BY total_amount DESC");
            $stmt->execute([$startDate, $endDate]);
        }
        
        return $stmt->fetchAll();
    }
}
