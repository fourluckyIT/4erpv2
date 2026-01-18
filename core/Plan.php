<?php
/**
 * Plan Model Class
 * ERP v2 - Phase 5
 * 
 * Handles Plan CRUD with:
 * - Serial allocation
 * - People assignment
 * - Status management
 * - Job status integration
 */

require_once __DIR__ . '/DocumentNumber.php';

class Plan {
    private PDO $db;
    private AuditLog $audit;
    private DocumentNumber $docNum;
    
    public function __construct() {
        $this->db = getDB();
        $this->audit = new AuditLog();
        $this->docNum = new DocumentNumber();
    }
    
    /**
     * Create a new plan for an Approved job
     */
    public function create(int $jobId, array $data): array {
        try {
            // Validate job exists and is Approved
            $job = $this->getJob($jobId);
            if (!$job) {
                return ['success' => false, 'error' => 'Job not found'];
            }
            if ($job['status'] !== 'Approved') {
                return ['success' => false, 'error' => 'เฉพาะ Job ที่ Approved แล้วเท่านั้นที่สามารถวางแผนได้'];
            }
            
            // Check if job already has an active plan
            $existingPlan = $this->getActiveByJobId($jobId);
            if ($existingPlan) {
                return ['success' => false, 'error' => 'Job นี้มี Plan ที่ยังใช้งานอยู่แล้ว (Plan #' . $existingPlan['plan_number'] . ')'];
            }
            
            // Generate plan number
            $planNumber = $this->docNum->generate('PLAN');
            
            $this->db->beginTransaction();
            
            $stmt = $this->db->prepare("
                INSERT INTO plans (
                    plan_number, job_id, plan_date, status, notes, created_by
                ) VALUES (
                    :plan_number, :job_id, :plan_date, 'Draft', :notes, :created_by
                )
            ");
            
            $stmt->execute([
                'plan_number' => $planNumber,
                'job_id' => $jobId,
                'plan_date' => $data['plan_date'] ?? date('Y-m-d'),
                'notes' => $data['notes'] ?? null,
                'created_by' => $_SESSION['user_id']
            ]);
            
            $planId = (int) $this->db->lastInsertId();
            
            // Audit log
            $this->audit->log(
                AUDIT_ACTION_CREATE,
                'PLAN',
                $planId,
                null,
                ['plan_number' => $planNumber, 'job_id' => $jobId]
            );
            
            $this->db->commit();
            
            return ['success' => true, 'id' => $planId, 'plan_number' => $planNumber];
            
        } catch (Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Add serial assignment to plan
     */
    public function addSerial(int $planId, int $serialId, ?string $notes = null): array {
        try {
            $plan = $this->getById($planId);
            if (!$plan) {
                return ['success' => false, 'error' => 'Plan not found'];
            }
            if ($plan['status'] !== 'Draft') {
                return ['success' => false, 'error' => 'ไม่สามารถเพิ่ม Serial ใน Plan ที่ไม่ใช่ Draft'];
            }
            
            // Check serial is available
            $serial = $this->getSerial($serialId);
            if (!$serial) {
                return ['success' => false, 'error' => 'Serial not found'];
            }
            if ($serial['status'] !== 'Available') {
                return ['success' => false, 'error' => 'Serial นี้ไม่ว่าง (สถานะ: ' . $serial['status'] . ')'];
            }
            
            // Check not already assigned to this plan
            $stmt = $this->db->prepare("SELECT id FROM plan_assignments WHERE plan_id = ? AND serial_id = ?");
            $stmt->execute([$planId, $serialId]);
            if ($stmt->fetch()) {
                return ['success' => false, 'error' => 'Serial นี้ถูกจัดสรรใน Plan นี้แล้ว'];
            }
            
            $stmt = $this->db->prepare("
                INSERT INTO plan_assignments (plan_id, serial_id, assignment_type, notes)
                VALUES (:plan_id, :serial_id, 'Device', :notes)
            ");
            $stmt->execute([
                'plan_id' => $planId,
                'serial_id' => $serialId,
                'notes' => $notes
            ]);
            
            return ['success' => true, 'id' => (int) $this->db->lastInsertId()];
            
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Add people assignment to plan
     */
    public function addPeople(int $planId, int $peopleId, ?string $notes = null): array {
        try {
            $plan = $this->getById($planId);
            if (!$plan) {
                return ['success' => false, 'error' => 'Plan not found'];
            }
            if ($plan['status'] !== 'Draft') {
                return ['success' => false, 'error' => 'ไม่สามารถเพิ่มคนใน Plan ที่ไม่ใช่ Draft'];
            }
            
            // Check people exists and is active
            $people = $this->getPeople($peopleId);
            if (!$people) {
                return ['success' => false, 'error' => 'People not found'];
            }
            if (!$people['is_active']) {
                return ['success' => false, 'error' => 'บุคคลนี้ไม่ active'];
            }
            
            // Check not already assigned to this plan
            $stmt = $this->db->prepare("SELECT id FROM plan_assignments WHERE plan_id = ? AND people_id = ?");
            $stmt->execute([$planId, $peopleId]);
            if ($stmt->fetch()) {
                return ['success' => false, 'error' => 'บุคคลนี้ถูกจัดสรรใน Plan นี้แล้ว'];
            }
            
            $stmt = $this->db->prepare("
                INSERT INTO plan_assignments (plan_id, people_id, assignment_type, notes)
                VALUES (:plan_id, :people_id, 'Manpower', :notes)
            ");
            $stmt->execute([
                'plan_id' => $planId,
                'people_id' => $peopleId,
                'notes' => $notes
            ]);
            
            return ['success' => true, 'id' => (int) $this->db->lastInsertId()];
            
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Remove assignment from plan
     */
    public function removeAssignment(int $assignmentId): array {
        try {
            $stmt = $this->db->prepare("
                SELECT pa.*, p.status as plan_status
                FROM plan_assignments pa
                JOIN plans p ON pa.plan_id = p.id
                WHERE pa.id = ?
            ");
            $stmt->execute([$assignmentId]);
            $assignment = $stmt->fetch();
            
            if (!$assignment) {
                return ['success' => false, 'error' => 'Assignment not found'];
            }
            if ($assignment['plan_status'] !== 'Draft') {
                return ['success' => false, 'error' => 'ไม่สามารถลบ Assignment ใน Plan ที่ไม่ใช่ Draft'];
            }
            
            $stmt = $this->db->prepare("DELETE FROM plan_assignments WHERE id = ?");
            $stmt->execute([$assignmentId]);
            
            return ['success' => true];
            
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Confirm plan - allocates serials and updates job status
     */
    public function confirm(int $planId): array {
        try {
            $plan = $this->getById($planId);
            if (!$plan) {
                return ['success' => false, 'error' => 'Plan not found'];
            }
            if ($plan['status'] !== 'Draft') {
                return ['success' => false, 'error' => 'เฉพาะ Plan ที่เป็น Draft เท่านั้นที่สามารถ Confirm ได้'];
            }
            
            // Get all serial assignments
            $stmt = $this->db->prepare("
                SELECT pa.serial_id, s.serial_number, s.status
                FROM plan_assignments pa
                JOIN serials s ON pa.serial_id = s.id
                WHERE pa.plan_id = ? AND pa.serial_id IS NOT NULL
            ");
            $stmt->execute([$planId]);
            $serialAssignments = $stmt->fetchAll();
            
            // Validate all serials are still available
            foreach ($serialAssignments as $sa) {
                if ($sa['status'] !== 'Available') {
                    return ['success' => false, 'error' => "Serial {$sa['serial_number']} ไม่ว่างแล้ว (สถานะ: {$sa['status']})"];
                }
            }
            
            $this->db->beginTransaction();
            
            // Update plan status
            $stmt = $this->db->prepare("
                UPDATE plans 
                SET status = 'Confirmed', confirmed_at = NOW(), confirmed_by = ?
                WHERE id = ?
            ");
            $stmt->execute([$_SESSION['user_id'], $planId]);
            
            // Allocate serials
            foreach ($serialAssignments as $sa) {
                $stmt = $this->db->prepare("
                    UPDATE serials 
                    SET status = 'Allocated', current_job_id = ?
                    WHERE id = ?
                ");
                $stmt->execute([$plan['job_id'], $sa['serial_id']]);
            }
            
            // Update job status to Planned
            $stmt = $this->db->prepare("UPDATE jobs SET status = 'Planned' WHERE id = ?");
            $stmt->execute([$plan['job_id']]);
            
            // Log job status change
            $stmt = $this->db->prepare("
                INSERT INTO job_status_history (job_id, old_status, new_status, changed_by, reason)
                VALUES (?, 'Approved', 'Planned', ?, ?)
            ");
            $stmt->execute([$plan['job_id'], $_SESSION['user_id'], 'Plan #' . $plan['plan_number'] . ' confirmed']);
            
            // Audit log
            $this->audit->log(
                'confirm',
                'PLAN',
                $planId,
                ['status' => 'Draft'],
                ['status' => 'Confirmed']
            );
            
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
     * Cancel plan - releases allocated serials
     */
    public function cancel(int $planId, string $reason): array {
        try {
            if (empty(trim($reason))) {
                return ['success' => false, 'error' => 'กรุณาระบุเหตุผลในการยกเลิก'];
            }
            
            $plan = $this->getById($planId);
            if (!$plan) {
                return ['success' => false, 'error' => 'Plan not found'];
            }
            if ($plan['status'] === 'Cancelled') {
                return ['success' => false, 'error' => 'Plan นี้ถูกยกเลิกไปแล้ว'];
            }
            
            $this->db->beginTransaction();
            
            // If plan was confirmed, release serials
            if ($plan['status'] === 'Confirmed') {
                $stmt = $this->db->prepare("
                    SELECT serial_id FROM plan_assignments 
                    WHERE plan_id = ? AND serial_id IS NOT NULL
                ");
                $stmt->execute([$planId]);
                $serialIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
                
                foreach ($serialIds as $serialId) {
                    $stmt = $this->db->prepare("
                        UPDATE serials 
                        SET status = 'Available', current_job_id = NULL
                        WHERE id = ?
                    ");
                    $stmt->execute([$serialId]);
                }
                
                // Revert job status to Approved
                $stmt = $this->db->prepare("UPDATE jobs SET status = 'Approved' WHERE id = ?");
                $stmt->execute([$plan['job_id']]);
                
                // Log job status change
                $stmt = $this->db->prepare("
                    INSERT INTO job_status_history (job_id, old_status, new_status, changed_by, reason)
                    VALUES (?, 'Planned', 'Approved', ?, ?)
                ");
                $stmt->execute([$plan['job_id'], $_SESSION['user_id'], 'Plan cancelled: ' . $reason]);
            }
            
            // Update plan status
            $stmt = $this->db->prepare("
                UPDATE plans 
                SET status = 'Cancelled', cancelled_at = NOW(), cancelled_by = ?, cancel_reason = ?
                WHERE id = ?
            ");
            $stmt->execute([$_SESSION['user_id'], $reason, $planId]);
            
            // Audit log
            $this->audit->log(
                'cancel',
                'PLAN',
                $planId,
                ['status' => $plan['status']],
                ['status' => 'Cancelled'],
                $reason
            );
            
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
     * Get plan by ID with related data
     */
    public function getById(int $id): ?array {
        $stmt = $this->db->prepare("
            SELECT p.*, 
                   j.job_number, j.scope_short, j.customer_id,
                   c.name as customer_name,
                   u.full_name as created_by_name
            FROM plans p
            LEFT JOIN jobs j ON p.job_id = j.id
            LEFT JOIN customers c ON j.customer_id = c.id
            LEFT JOIN users u ON p.created_by = u.id
            WHERE p.id = ?
        ");
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }
    
    /**
     * Get active plan for a job
     */
    public function getActiveByJobId(int $jobId): ?array {
        $stmt = $this->db->prepare("
            SELECT * FROM plans 
            WHERE job_id = ? AND status IN ('Draft', 'Confirmed')
            ORDER BY id DESC LIMIT 1
        ");
        $stmt->execute([$jobId]);
        return $stmt->fetch() ?: null;
    }
    
    /**
     * Get plan assignments
     */
    public function getAssignments(int $planId): array {
        $stmt = $this->db->prepare("
            SELECT pa.*,
                   s.serial_number, s.status as serial_status,
                   i.name as item_name, i.code as item_code,
                   pe.full_name as people_name, pe.code as people_code, pe.position
            FROM plan_assignments pa
            LEFT JOIN serials s ON pa.serial_id = s.id
            LEFT JOIN items i ON s.item_id = i.id
            LEFT JOIN people pe ON pa.people_id = pe.id
            WHERE pa.plan_id = ?
            ORDER BY pa.id
        ");
        $stmt->execute([$planId]);
        return $stmt->fetchAll();
    }
    
    /**
     * Get list of plans
     */
    public function getList(array $filters = [], int $limit = 50, int $offset = 0): array {
        $where = ['1=1'];
        $params = [];
        
        if (!empty($filters['status'])) {
            $where[] = 'p.status = :status';
            $params['status'] = $filters['status'];
        }
        
        if (!empty($filters['job_id'])) {
            $where[] = 'p.job_id = :job_id';
            $params['job_id'] = $filters['job_id'];
        }
        
        $sql = "
            SELECT p.*, 
                   j.job_number, j.scope_short,
                   c.name as customer_name
            FROM plans p
            LEFT JOIN jobs j ON p.job_id = j.id
            LEFT JOIN customers c ON j.customer_id = c.id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY p.id DESC
            LIMIT $limit OFFSET $offset
        ";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
    
    /**
     * Get jobs awaiting planning
     */
    public function getJobsAwaitingPlan(): array {
        $stmt = $this->db->prepare("
            SELECT j.*, c.name as customer_name
            FROM jobs j
            LEFT JOIN customers c ON j.customer_id = c.id
            WHERE j.status = 'Approved'
            AND NOT EXISTS (
                SELECT 1 FROM plans p 
                WHERE p.job_id = j.id AND p.status IN ('Draft', 'Confirmed')
            )
            ORDER BY j.plan_start_date ASC
        ");
        $stmt->execute();
        return $stmt->fetchAll();
    }
    
    // Helper methods
    private function getJob(int $id): ?array {
        $stmt = $this->db->prepare("SELECT * FROM jobs WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }
    
    private function getSerial(int $id): ?array {
        $stmt = $this->db->prepare("SELECT * FROM serials WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }
    
    private function getPeople(int $id): ?array {
        $stmt = $this->db->prepare("SELECT * FROM people WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }
}
