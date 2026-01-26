<?php
/**
 * Costing Model Class
 * 4ERP - M10: Rate Cards & Job Costing
 * 
 * Handles:
 * - Rate card management
 * - Job cost calculation
 * - Margin analysis (estimated vs actual)
 * 
 * Following blueprint.md §13
 */

class Costing {
    private PDO $db;
    
    const COST_MATERIAL = 'Material';
    const COST_MANPOWER = 'Manpower';
    const COST_TRANSPORT = 'Transport';
    const COST_OUTSOURCE = 'Outsource';
    const COST_OTHER = 'Other';
    
    public function __construct() {
        $this->db = getDB();
    }
    
    // ==================== RATE CARDS ====================
    
    /**
     * Get rate card by ID
     */
    public function getRateCardById(int $id): ?array {
        $stmt = $this->db->prepare("SELECT * FROM rate_cards WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }
    
    /**
     * Get rate cards by job type
     */
    public function getRateCardsByType(string $jobType): array {
        $stmt = $this->db->prepare("
            SELECT * FROM rate_cards 
            WHERE job_type = ? AND is_active = 1
            ORDER BY name
        ");
        $stmt->execute([$jobType]);
        return $stmt->fetchAll();
    }
    
    /**
     * Get all active rate cards
     */
    public function getAllRateCards(): array {
        $stmt = $this->db->query("
            SELECT * FROM rate_cards WHERE is_active = 1 ORDER BY job_type, name
        ");
        return $stmt->fetchAll();
    }
    
    /**
     * Calculate price based on rate card and parameters
     */
    public function calculatePrice(int $rateCardId, array $params): array {
        $card = $this->getRateCardById($rateCardId);
        if (!$card) {
            return ['success' => false, 'error' => 'Rate card not found'];
        }
        
        $baseAmount = 0;
        $breakdown = [];
        
        switch ($card['job_type']) {
            case 'Lumpsum':
                $baseAmount = (float)$card['base_rate'];
                $breakdown[] = ['desc' => 'Fixed Price', 'amount' => $baseAmount];
                break;
                
            case 'Dayrent':
                $days = max((int)($params['days'] ?? 1), (int)$card['minimum_days']);
                $halfDays = (int)($params['half_days'] ?? 0);
                
                $dayAmount = $days * (float)$card['base_rate'];
                $halfDayAmount = $halfDays * (float)($card['half_day_rate'] ?? $card['base_rate'] * 0.6);
                
                $baseAmount = $dayAmount + $halfDayAmount;
                $breakdown[] = ['desc' => "{$days} วัน x " . number_format($card['base_rate'], 2), 'amount' => $dayAmount];
                if ($halfDays > 0) {
                    $breakdown[] = ['desc' => "{$halfDays} ครึ่งวัน x " . number_format($card['half_day_rate'], 2), 'amount' => $halfDayAmount];
                }
                break;
                
            case 'Manpower':
                $hours = (float)($params['hours'] ?? 8);
                $otHours = (float)($params['ot_hours'] ?? 0);
                $workers = (int)($params['workers'] ?? 1);
                
                $hourlyRate = (float)$card['hourly_rate'];
                $otRate = $hourlyRate * (float)$card['ot_multiplier'];
                
                $normalAmount = $hours * $hourlyRate * $workers;
                $otAmount = $otHours * $otRate * $workers;
                
                $baseAmount = $normalAmount + $otAmount;
                $breakdown[] = ['desc' => "{$workers} คน x {$hours} ชม. x " . number_format($hourlyRate, 2), 'amount' => $normalAmount];
                if ($otHours > 0) {
                    $breakdown[] = ['desc' => "OT: {$workers} คน x {$otHours} ชม. x " . number_format($otRate, 2), 'amount' => $otAmount];
                }
                break;
        }
        
        // Apply VAT if included
        $vatAmount = 0;
        if ($card['include_vat']) {
            $vatAmount = $baseAmount * ((float)$card['vat_rate'] / 100);
            $breakdown[] = ['desc' => "VAT {$card['vat_rate']}%", 'amount' => $vatAmount];
        }
        
        // Calculate withholding
        $withholdingAmount = 0;
        if ((float)$card['withholding_rate'] > 0) {
            $withholdingAmount = $baseAmount * ((float)$card['withholding_rate'] / 100);
            $breakdown[] = ['desc' => "หัก ณ ที่จ่าย {$card['withholding_rate']}%", 'amount' => -$withholdingAmount];
        }
        
        $totalAmount = $baseAmount + $vatAmount - $withholdingAmount;
        
        return [
            'success' => true,
            'base_amount' => $baseAmount,
            'vat_amount' => $vatAmount,
            'withholding_amount' => $withholdingAmount,
            'total_amount' => $totalAmount,
            'breakdown' => $breakdown
        ];
    }
    
    // ==================== JOB COSTING ====================
    
    /**
     * Initialize or get job costs record
     */
    public function initJobCosts(int $jobId): int {
        $stmt = $this->db->prepare("SELECT id FROM job_costs WHERE job_id = ?");
        $stmt->execute([$jobId]);
        $existing = $stmt->fetchColumn();
        
        if ($existing) {
            return (int)$existing;
        }
        
        // Get estimated from job
        $stmt = $this->db->prepare("SELECT contract_value, budget FROM jobs WHERE id = ?");
        $stmt->execute([$jobId]);
        $job = $stmt->fetch();
        
        $stmt = $this->db->prepare("
            INSERT INTO job_costs (job_id, estimated_revenue) VALUES (?, ?)
        ");
        $stmt->execute([$jobId, $job['contract_value'] ?? 0]);
        
        return (int) $this->db->lastInsertId();
    }
    
    /**
     * Set estimated costs for a job
     */
    public function setEstimatedCosts(int $jobId, array $costs): array {
        try {
            $this->initJobCosts($jobId);
            
            $stmt = $this->db->prepare("
                UPDATE job_costs SET
                    estimated_revenue = COALESCE(:revenue, estimated_revenue),
                    estimated_material_cost = COALESCE(:material, estimated_material_cost),
                    estimated_manpower_cost = COALESCE(:manpower, estimated_manpower_cost),
                    estimated_transport_cost = COALESCE(:transport, estimated_transport_cost),
                    estimated_other_cost = COALESCE(:other, estimated_other_cost)
                WHERE job_id = :job_id
            ");
            
            $stmt->execute([
                'revenue' => $costs['revenue'] ?? null,
                'material' => $costs['material'] ?? null,
                'manpower' => $costs['manpower'] ?? null,
                'transport' => $costs['transport'] ?? null,
                'other' => $costs['other'] ?? null,
                'job_id' => $jobId
            ]);
            
            return ['success' => true];
            
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Add a cost line item
     */
    public function addCostLine(int $jobId, string $costType, string $description, float $quantity, 
                                 float $unitCost, string $costDate, ?string $unit = null,
                                 ?string $sourceType = null, ?int $sourceId = null, ?string $notes = null): array {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO job_cost_lines (
                    job_id, cost_type, description, quantity, unit, unit_cost,
                    cost_date, source_type, source_id, notes, created_by
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $jobId, $costType, $description, $quantity, $unit, $unitCost,
                $costDate, $sourceType, $sourceId, $notes, $_SESSION['user_id'] ?? null
            ]);
            
            $lineId = (int) $this->db->lastInsertId();
            
            // Trigger recalculation
            $this->recalculateActualCosts($jobId);
            
            return ['success' => true, 'id' => $lineId];
            
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Recalculate actual costs from various sources
     */
    public function recalculateActualCosts(int $jobId): array {
        try {
            $this->initJobCosts($jobId);
            
            // Calculate material cost from job_cost_lines
            $stmt = $this->db->prepare("
                SELECT COALESCE(SUM(quantity * unit_cost), 0) as total
                FROM job_cost_lines
                WHERE job_id = ? AND cost_type = 'Material'
            ");
            $stmt->execute([$jobId]);
            $materialCost = (float)$stmt->fetchColumn();
            
            // Calculate manpower cost from timesheets
            $stmt = $this->db->prepare("
                SELECT COALESCE(SUM(te.total_cost), 0) as total
                FROM timesheet_entries te
                JOIN timesheets t ON te.timesheet_id = t.id
                WHERE t.job_id = ? AND t.status IN ('PayrollReady', 'Confirmed', 'Submitted')
            ");
            $stmt->execute([$jobId]);
            $manpowerCost = (float)$stmt->fetchColumn();
            
            // Also include manpower from job_cost_lines
            $stmt = $this->db->prepare("
                SELECT COALESCE(SUM(quantity * unit_cost), 0) as total
                FROM job_cost_lines
                WHERE job_id = ? AND cost_type = 'Manpower'
            ");
            $stmt->execute([$jobId]);
            $manpowerCost += (float)$stmt->fetchColumn();
            
            // Calculate transport cost from job_cost_lines
            $stmt = $this->db->prepare("
                SELECT COALESCE(SUM(quantity * unit_cost), 0) as total
                FROM job_cost_lines
                WHERE job_id = ? AND cost_type IN ('Transport', 'Outsource')
            ");
            $stmt->execute([$jobId]);
            $transportCost = (float)$stmt->fetchColumn();
            
            // Calculate other costs
            $stmt = $this->db->prepare("
                SELECT COALESCE(SUM(quantity * unit_cost), 0) as total
                FROM job_cost_lines
                WHERE job_id = ? AND cost_type = 'Other'
            ");
            $stmt->execute([$jobId]);
            $otherCost = (float)$stmt->fetchColumn();
            
            // Calculate revenue from invoices
            $stmt = $this->db->prepare("
                SELECT COALESCE(SUM(total_amount), 0) as total
                FROM ar_invoices
                WHERE job_id = ? AND status IN ('Issued', 'Partial', 'Paid')
            ");
            $stmt->execute([$jobId]);
            $revenue = (float)$stmt->fetchColumn();
            
            // Update job_costs
            $stmt = $this->db->prepare("
                UPDATE job_costs SET
                    actual_revenue = ?,
                    actual_material_cost = ?,
                    actual_manpower_cost = ?,
                    actual_transport_cost = ?,
                    actual_other_cost = ?,
                    last_calculated_at = NOW()
                WHERE job_id = ?
            ");
            $stmt->execute([
                $revenue, $materialCost, $manpowerCost, $transportCost, $otherCost, $jobId
            ]);
            
            return [
                'success' => true,
                'costs' => [
                    'revenue' => $revenue,
                    'material' => $materialCost,
                    'manpower' => $manpowerCost,
                    'transport' => $transportCost,
                    'other' => $otherCost,
                    'total' => $materialCost + $manpowerCost + $transportCost + $otherCost
                ]
            ];
            
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Record cost from timesheet (called when timesheet is confirmed)
     */
    public function recordTimesheetCost(int $timesheetId): array {
        try {
            $stmt = $this->db->prepare("
                SELECT t.job_id, t.work_date, SUM(te.total_cost) as total_cost
                FROM timesheets t
                JOIN timesheet_entries te ON t.id = te.timesheet_id
                WHERE t.id = ? AND te.is_present = 1
                GROUP BY t.job_id, t.work_date
            ");
            $stmt->execute([$timesheetId]);
            $ts = $stmt->fetch();
            
            if (!$ts || $ts['total_cost'] <= 0) {
                return ['success' => true, 'cost' => 0];
            }
            
            // Check if already recorded
            $stmt = $this->db->prepare("
                SELECT id FROM job_cost_lines 
                WHERE source_type = 'TIMESHEET' AND source_id = ?
            ");
            $stmt->execute([$timesheetId]);
            if ($stmt->fetch()) {
                // Already recorded, update instead
                $stmt = $this->db->prepare("
                    UPDATE job_cost_lines SET unit_cost = ? 
                    WHERE source_type = 'TIMESHEET' AND source_id = ?
                ");
                $stmt->execute([$ts['total_cost'], $timesheetId]);
            } else {
                $this->addCostLine(
                    $ts['job_id'],
                    self::COST_MANPOWER,
                    'Timesheet ' . $ts['work_date'],
                    1,
                    $ts['total_cost'],
                    $ts['work_date'],
                    'day',
                    'TIMESHEET',
                    $timesheetId
                );
            }
            
            return ['success' => true, 'cost' => $ts['total_cost']];
            
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    // ==================== GETTERS ====================
    
    public function getJobCosts(int $jobId): ?array {
        $this->initJobCosts($jobId);
        
        $stmt = $this->db->prepare("
            SELECT jc.*, j.job_number, j.contract_value
            FROM job_costs jc
            JOIN jobs j ON jc.job_id = j.id
            WHERE jc.job_id = ?
        ");
        $stmt->execute([$jobId]);
        return $stmt->fetch() ?: null;
    }
    
    public function getJobCostLines(int $jobId, ?string $costType = null): array {
        $where = 'job_id = ?';
        $params = [$jobId];
        
        if ($costType) {
            $where .= ' AND cost_type = ?';
            $params[] = $costType;
        }
        
        $stmt = $this->db->prepare("
            SELECT * FROM job_cost_lines 
            WHERE $where 
            ORDER BY cost_date DESC, created_at DESC
        ");
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
    
    public function getCostSummaryByType(int $jobId): array {
        $stmt = $this->db->prepare("
            SELECT cost_type, COUNT(*) as line_count, SUM(quantity * unit_cost) as total
            FROM job_cost_lines
            WHERE job_id = ?
            GROUP BY cost_type
        ");
        $stmt->execute([$jobId]);
        return $stmt->fetchAll();
    }
    
    /**
     * Get jobs with margin issues (actual margin < estimated)
     */
    public function getJobsWithMarginIssues(float $thresholdPct = 10): array {
        $stmt = $this->db->prepare("
            SELECT jc.*, j.job_number, j.scope_short, j.status
            FROM job_costs jc
            JOIN jobs j ON jc.job_id = j.id
            WHERE j.status NOT IN ('Voided', 'Closed')
              AND jc.actual_margin_pct < (jc.estimated_margin_pct - ?)
            ORDER BY (jc.estimated_margin_pct - jc.actual_margin_pct) DESC
        ");
        $stmt->execute([$thresholdPct]);
        return $stmt->fetchAll();
    }
    
    /**
     * Get profitability report
     */
    public function getProfitabilityReport(array $filters = []): array {
        $where = ['j.status NOT IN ("Voided", "Draft")'];
        $params = [];
        
        if (!empty($filters['date_from'])) {
            $where[] = 'j.plan_start_date >= ?';
            $params[] = $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $where[] = 'j.plan_end_date <= ?';
            $params[] = $filters['date_to'];
        }
        if (!empty($filters['job_type'])) {
            $where[] = 'j.job_type = ?';
            $params[] = $filters['job_type'];
        }
        
        $sql = "
            SELECT 
                j.id, j.job_number, j.job_type, j.scope_short, j.status,
                j.contract_value, j.plan_start_date, j.plan_end_date,
                jc.estimated_revenue, jc.estimated_total_cost, jc.estimated_margin, jc.estimated_margin_pct,
                jc.actual_revenue, jc.actual_total_cost, jc.actual_margin, jc.actual_margin_pct,
                c.name as customer_name
            FROM jobs j
            LEFT JOIN job_costs jc ON j.id = jc.job_id
            JOIN customers c ON j.customer_id = c.id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY j.plan_start_date DESC
        ";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
}
