<?php
/**
 * Plan Model Class
 * 4ERP - Phase 5
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
     * @param string $assignmentType 'Device' or 'Equipment'
     */
    public function addSerial(int $planId, int $serialId, string $assignmentType = 'Device', ?string $notes = null): array {
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
            
            // Validate assignment type
            if (!in_array($assignmentType, ['Device', 'Equipment'])) {
                $assignmentType = 'Device';
            }
            
            $stmt = $this->db->prepare("
                INSERT INTO plan_assignments (plan_id, serial_id, assignment_type, notes)
                VALUES (:plan_id, :serial_id, :assignment_type, :notes)
            ");
            $stmt->execute([
                'plan_id' => $planId,
                'serial_id' => $serialId,
                'assignment_type' => $assignmentType,
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
     * Add consumable item to plan (quantity-based, not serial)
     */
    public function addConsumable(int $planId, int $itemId, int $quantity, ?string $notes = null): array {
        try {
            $plan = $this->getById($planId);
            if (!$plan) {
                return ['success' => false, 'error' => 'Plan not found'];
            }
            if ($plan['status'] !== 'Draft') {
                return ['success' => false, 'error' => 'ไม่สามารถเพิ่มวัสดุใน Plan ที่ไม่ใช่ Draft'];
            }
            
            // Check item exists and is consumable
            $stmt = $this->db->prepare("SELECT * FROM items WHERE id = ? AND item_type = 'Consumable' AND is_active = 1");
            $stmt->execute([$itemId]);
            $item = $stmt->fetch();
            if (!$item) {
                return ['success' => false, 'error' => 'ไม่พบวัสดุสิ้นเปลืองนี้'];
            }
            
            // Check not already assigned to this plan
            $stmt = $this->db->prepare("SELECT id FROM plan_assignments WHERE plan_id = ? AND item_id = ?");
            $stmt->execute([$planId, $itemId]);
            if ($stmt->fetch()) {
                // Update existing quantity
                $stmt = $this->db->prepare("UPDATE plan_assignments SET quantity = ? WHERE plan_id = ? AND item_id = ?");
                $stmt->execute([$quantity, $planId, $itemId]);
                return ['success' => true, 'updated' => true];
            }
            
            $stmt = $this->db->prepare("
                INSERT INTO plan_assignments (plan_id, item_id, assignment_type, quantity, notes)
                VALUES (:plan_id, :item_id, 'Consumable', :quantity, :notes)
            ");
            $stmt->execute([
                'plan_id' => $planId,
                'item_id' => $itemId,
                'quantity' => $quantity,
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
     * Get all plans for a job
     */
    public function getByJobId(int $jobId): array {
        $stmt = $this->db->prepare("
            SELECT * FROM plans 
            WHERE job_id = ?
            ORDER BY id DESC
        ");
        $stmt->execute([$jobId]);
        return $stmt->fetchAll();
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
                   COALESCE(i.name, ci.name) as item_name, 
                   COALESCE(i.code, ci.code) as item_code,
                   ci.unit as consumable_unit,
                   pe.full_name as people_name, pe.code as people_code, pe.position
            FROM plan_assignments pa
            LEFT JOIN serials s ON pa.serial_id = s.id
            LEFT JOIN items i ON s.item_id = i.id
            LEFT JOIN items ci ON pa.item_id = ci.id
            LEFT JOIN people pe ON pa.people_id = pe.id
            WHERE pa.plan_id = ?
            ORDER BY pa.assignment_type, pa.id
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
    
    // ==================== CONFLICT DETECTION ====================
    
    /**
     * Check serial conflicts for a date range
     */
    public function checkSerialConflicts(int $serialId, string $startDate, string $endDate, ?int $excludePlanId = null): array {
        $conflicts = [];
        
        $sql = "
            SELECT pa.*, p.plan_number, p.plan_date, p.plan_end_date,
                   j.job_number, j.scope_short
            FROM plan_assignments pa
            JOIN plans p ON pa.plan_id = p.id
            JOIN jobs j ON p.job_id = j.id
            WHERE pa.serial_id = ?
              AND p.status IN ('Draft', 'Confirmed')
              AND p.plan_date <= ?
              AND p.plan_end_date >= ?
        ";
        $params = [$serialId, $endDate, $startDate];
        
        if ($excludePlanId) {
            $sql .= " AND p.id != ?";
            $params[] = $excludePlanId;
        }
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        
        return $stmt->fetchAll();
    }
    
    /**
     * Check people conflicts for a date range
     */
    public function checkPeopleConflicts(int $peopleId, string $startDate, string $endDate, ?int $excludePlanId = null): array {
        $sql = "
            SELECT pa.*, p.plan_number, p.plan_date, p.plan_end_date,
                   j.job_number, j.scope_short
            FROM plan_assignments pa
            JOIN plans p ON pa.plan_id = p.id
            JOIN jobs j ON p.job_id = j.id
            WHERE pa.people_id = ?
              AND p.status IN ('Draft', 'Confirmed')
              AND p.plan_date <= ?
              AND p.plan_end_date >= ?
        ";
        $params = [$peopleId, $endDate, $startDate];
        
        if ($excludePlanId) {
            $sql .= " AND p.id != ?";
            $params[] = $excludePlanId;
        }
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        
        return $stmt->fetchAll();
    }
    
    /**
     * Get all conflicts for a plan
     */
    public function getPlanConflicts(int $planId): array {
        $plan = $this->getById($planId);
        if (!$plan) {
            return [];
        }
        
        $assignments = $this->getAssignments($planId);
        $conflicts = [
            'serials' => [],
            'people' => []
        ];
        
        foreach ($assignments as $a) {
            if ($a['serial_id']) {
                $serialConflicts = $this->checkSerialConflicts(
                    $a['serial_id'], 
                    $plan['plan_date'], 
                    $plan['plan_end_date'],
                    $planId
                );
                if (!empty($serialConflicts)) {
                    $conflicts['serials'][] = [
                        'serial_id' => $a['serial_id'],
                        'serial_number' => $a['serial_number'],
                        'item_name' => $a['item_name'],
                        'conflicts' => $serialConflicts
                    ];
                }
            }
            
            if ($a['people_id']) {
                $peopleConflicts = $this->checkPeopleConflicts(
                    $a['people_id'], 
                    $plan['plan_date'], 
                    $plan['plan_end_date'],
                    $planId
                );
                if (!empty($peopleConflicts)) {
                    $conflicts['people'][] = [
                        'people_id' => $a['people_id'],
                        'people_name' => $a['people_name'],
                        'position' => $a['position'],
                        'conflicts' => $peopleConflicts
                    ];
                }
            }
        }
        
        return $conflicts;
    }
    
    /**
     * Get all booking conflicts across all active plans
     */
    public function getAllConflicts(): array {
        $allConflicts = [];
        
        // Serial conflicts
        $stmt = $this->db->query("
            SELECT 
                s.id as serial_id, s.serial_number, i.name as item_name,
                p1.id as plan1_id, p1.plan_number as plan1_number, 
                j1.job_number as job1_number, p1.plan_date as plan1_start, p1.plan_end_date as plan1_end,
                p2.id as plan2_id, p2.plan_number as plan2_number, 
                j2.job_number as job2_number, p2.plan_date as plan2_start, p2.plan_end_date as plan2_end
            FROM plan_assignments pa1
            JOIN plan_assignments pa2 ON pa1.serial_id = pa2.serial_id AND pa1.id < pa2.id
            JOIN plans p1 ON pa1.plan_id = p1.id
            JOIN plans p2 ON pa2.plan_id = p2.id
            JOIN jobs j1 ON p1.job_id = j1.id
            JOIN jobs j2 ON p2.job_id = j2.id
            JOIN serials s ON pa1.serial_id = s.id
            JOIN items i ON s.item_id = i.id
            WHERE p1.status IN ('Draft', 'Confirmed')
              AND p2.status IN ('Draft', 'Confirmed')
              AND p1.plan_date <= p2.plan_end_date
              AND p1.plan_end_date >= p2.plan_date
            ORDER BY s.serial_number
        ");
        $allConflicts['serials'] = $stmt->fetchAll();
        
        // People conflicts
        $stmt = $this->db->query("
            SELECT 
                pe.id as people_id, pe.full_name as people_name, pe.position,
                p1.id as plan1_id, p1.plan_number as plan1_number, 
                j1.job_number as job1_number, p1.plan_date as plan1_start, p1.plan_end_date as plan1_end,
                p2.id as plan2_id, p2.plan_number as plan2_number, 
                j2.job_number as job2_number, p2.plan_date as plan2_start, p2.plan_end_date as plan2_end
            FROM plan_assignments pa1
            JOIN plan_assignments pa2 ON pa1.people_id = pa2.people_id AND pa1.id < pa2.id
            JOIN plans p1 ON pa1.plan_id = p1.id
            JOIN plans p2 ON pa2.plan_id = p2.id
            JOIN jobs j1 ON p1.job_id = j1.id
            JOIN jobs j2 ON p2.job_id = j2.id
            JOIN people pe ON pa1.people_id = pe.id
            WHERE p1.status IN ('Draft', 'Confirmed')
              AND p2.status IN ('Draft', 'Confirmed')
              AND p1.plan_date <= p2.plan_end_date
              AND p1.plan_end_date >= p2.plan_date
            ORDER BY pe.full_name
        ");
        $allConflicts['people'] = $stmt->fetchAll();
        
        return $allConflicts;
    }
    
    /**
     * Get conflict count
     */
    public function getConflictCount(): int {
        $conflicts = $this->getAllConflicts();
        return count($conflicts['serials']) + count($conflicts['people']);
    }
}
