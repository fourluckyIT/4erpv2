<?php
/**
 * Dispatch Model Class
 * ERP v2 - Phase 5
 * 
 * Handles Dispatch Note CRUD with:
 * - Serial dispatch tracking
 * - Status management
 * - Job status integration
 */

require_once __DIR__ . '/DocumentNumber.php';

class Dispatch {
    private PDO $db;
    private AuditLog $audit;
    private DocumentNumber $docNum;
    
    public function __construct() {
        $this->db = getDB();
        $this->audit = new AuditLog();
        $this->docNum = new DocumentNumber();
    }
    
    /**
     * Create a new dispatch note from a confirmed plan
     */
    public function create(int $planId, array $data): array {
        try {
            // Validate plan exists and is confirmed
            $plan = $this->getPlan($planId);
            if (!$plan) {
                return ['success' => false, 'error' => 'Plan not found'];
            }
            if ($plan['status'] !== 'Confirmed') {
                return ['success' => false, 'error' => 'เฉพาะ Plan ที่ Confirmed แล้วเท่านั้นที่สามารถสร้าง Dispatch Note ได้'];
            }
            
            // Check job status
            $job = $this->getJob($plan['job_id']);
            if (!$job || $job['status'] !== 'Planned') {
                return ['success' => false, 'error' => 'Job ต้องอยู่ในสถานะ Planned'];
            }
            
            // Generate DO number
            $doNumber = $this->docNum->generate('DO');
            
            $this->db->beginTransaction();
            
            $stmt = $this->db->prepare("
                INSERT INTO dispatch_notes (
                    do_number, plan_id, dispatch_date, status,
                    vehicle_info, driver_name, driver_phone, notes, created_by
                ) VALUES (
                    :do_number, :plan_id, :dispatch_date, 'Draft',
                    :vehicle_info, :driver_name, :driver_phone, :notes, :created_by
                )
            ");
            
            $stmt->execute([
                'do_number' => $doNumber,
                'plan_id' => $planId,
                'dispatch_date' => $data['dispatch_date'] ?? date('Y-m-d'),
                'vehicle_info' => $data['vehicle_info'] ?? null,
                'driver_name' => $data['driver_name'] ?? null,
                'driver_phone' => $data['driver_phone'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by' => $_SESSION['user_id']
            ]);
            
            $dispatchId = (int) $this->db->lastInsertId();
            
            // Audit log
            $this->audit->log(
                AUDIT_ACTION_CREATE,
                'DISPATCH',
                $dispatchId,
                null,
                ['do_number' => $doNumber, 'plan_id' => $planId]
            );
            
            $this->db->commit();
            
            return ['success' => true, 'id' => $dispatchId, 'do_number' => $doNumber];
            
        } catch (Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Add serial to dispatch items
     */
    public function addItem(int $dispatchId, int $serialId, string $conditionOut = 'Good', ?string $notes = null): array {
        try {
            $dispatch = $this->getById($dispatchId);
            if (!$dispatch) {
                return ['success' => false, 'error' => 'Dispatch not found'];
            }
            if ($dispatch['status'] !== 'Draft') {
                return ['success' => false, 'error' => 'ไม่สามารถเพิ่มรายการใน Dispatch ที่ไม่ใช่ Draft'];
            }
            
            // Validate serial is allocated to this plan
            $stmt = $this->db->prepare("
                SELECT s.*, pa.id as assignment_id, s.current_job_id
                FROM serials s
                JOIN plan_assignments pa ON pa.serial_id = s.id
                JOIN plans p ON pa.plan_id = p.id
                JOIN dispatch_notes dn ON dn.plan_id = p.id
                WHERE s.id = ? AND dn.id = ?
            ");
            $stmt->execute([$serialId, $dispatchId]);
            $serial = $stmt->fetch();
            
            if (!$serial) {
                return ['success' => false, 'error' => 'Serial นี้ไม่ได้ถูกจัดสรรใน Plan ที่เกี่ยวข้อง'];
            }
            if ($serial['status'] !== 'Allocated') {
                return ['success' => false, 'error' => 'Serial ต้องอยู่ในสถานะ Allocated (ปัจจุบัน: ' . $serial['status'] . ')'];
            }
            
            // Check not already in this dispatch
            $stmt = $this->db->prepare("SELECT id FROM dispatch_items WHERE dispatch_id = ? AND serial_id = ?");
            $stmt->execute([$dispatchId, $serialId]);
            if ($stmt->fetch()) {
                return ['success' => false, 'error' => 'Serial นี้อยู่ในรายการส่งของแล้ว'];
            }
            
            $stmt = $this->db->prepare("
                INSERT INTO dispatch_items (dispatch_id, serial_id, condition_out, notes)
                VALUES (:dispatch_id, :serial_id, :condition_out, :notes)
            ");
            $stmt->execute([
                'dispatch_id' => $dispatchId,
                'serial_id' => $serialId,
                'condition_out' => $conditionOut,
                'notes' => $notes
            ]);
            
            return ['success' => true, 'id' => (int) $this->db->lastInsertId()];
            
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Remove item from dispatch
     */
    public function removeItem(int $itemId): array {
        try {
            $stmt = $this->db->prepare("
                SELECT di.*, dn.status as dispatch_status
                FROM dispatch_items di
                JOIN dispatch_notes dn ON di.dispatch_id = dn.id
                WHERE di.id = ?
            ");
            $stmt->execute([$itemId]);
            $item = $stmt->fetch();
            
            if (!$item) {
                return ['success' => false, 'error' => 'Item not found'];
            }
            if ($item['dispatch_status'] !== 'Draft') {
                return ['success' => false, 'error' => 'ไม่สามารถลบรายการใน Dispatch ที่ไม่ใช่ Draft'];
            }
            
            $stmt = $this->db->prepare("DELETE FROM dispatch_items WHERE id = ?");
            $stmt->execute([$itemId]);
            
            return ['success' => true];
            
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Confirm dispatch - updates serial status and job status
     */
    public function confirm(int $dispatchId): array {
        try {
            $dispatch = $this->getById($dispatchId);
            if (!$dispatch) {
                return ['success' => false, 'error' => 'Dispatch not found'];
            }
            if ($dispatch['status'] !== 'Draft') {
                return ['success' => false, 'error' => 'เฉพาะ Dispatch ที่เป็น Draft เท่านั้นที่สามารถ Confirm ได้'];
            }
            
            // Get dispatch items
            $items = $this->getItems($dispatchId);
            if (empty($items)) {
                return ['success' => false, 'error' => 'กรุณาเพิ่มรายการ Serial ก่อน Dispatch'];
            }
            
            // Validate all serials are still allocated
            foreach ($items as $item) {
                $stmt = $this->db->prepare("SELECT status FROM serials WHERE id = ?");
                $stmt->execute([$item['serial_id']]);
                $serial = $stmt->fetch();
                if ($serial['status'] !== 'Allocated') {
                    return ['success' => false, 'error' => "Serial {$item['serial_number']} ไม่อยู่ในสถานะ Allocated"];
                }
            }
            
            $this->db->beginTransaction();
            
            // Update dispatch status
            $stmt = $this->db->prepare("
                UPDATE dispatch_notes 
                SET status = 'Dispatched', dispatched_at = NOW(), dispatched_by = ?
                WHERE id = ?
            ");
            $stmt->execute([$_SESSION['user_id'], $dispatchId]);
            
            // Update serial status
            foreach ($items as $item) {
                $stmt = $this->db->prepare("UPDATE serials SET status = 'Dispatched' WHERE id = ?");
                $stmt->execute([$item['serial_id']]);
            }
            
            // Get plan to find job
            $plan = $this->getPlan($dispatch['plan_id']);
            
            // Update job status to Dispatched
            $stmt = $this->db->prepare("UPDATE jobs SET status = 'Dispatched' WHERE id = ?");
            $stmt->execute([$plan['job_id']]);
            
            // Log job status change
            $stmt = $this->db->prepare("
                INSERT INTO job_status_history (job_id, old_status, new_status, changed_by, reason)
                VALUES (?, 'Planned', 'Dispatched', ?, ?)
            ");
            $stmt->execute([$plan['job_id'], $_SESSION['user_id'], 'Dispatch #' . $dispatch['do_number'] . ' confirmed']);
            
            // Audit log
            $this->audit->log(
                'dispatch',
                'DISPATCH',
                $dispatchId,
                ['status' => 'Draft'],
                ['status' => 'Dispatched']
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
     * Mark as delivered
     */
    public function markDelivered(int $dispatchId): array {
        try {
            $dispatch = $this->getById($dispatchId);
            if (!$dispatch) {
                return ['success' => false, 'error' => 'Dispatch not found'];
            }
            if ($dispatch['status'] !== 'Dispatched') {
                return ['success' => false, 'error' => 'เฉพาะ Dispatch ที่ถูกส่งออกแล้วเท่านั้นที่สามารถทำเครื่องหมายว่าส่งถึงแล้ว'];
            }
            
            $this->db->beginTransaction();
            
            $stmt = $this->db->prepare("
                UPDATE dispatch_notes 
                SET status = 'Delivered', delivered_at = NOW(), delivered_by = ?
                WHERE id = ?
            ");
            $stmt->execute([$_SESSION['user_id'], $dispatchId]);
            
            // Update serial status to InUse
            $items = $this->getItems($dispatchId);
            foreach ($items as $item) {
                $stmt = $this->db->prepare("UPDATE serials SET status = 'InUse' WHERE id = ?");
                $stmt->execute([$item['serial_id']]);
            }
            
            // Get plan to find job
            $plan = $this->getPlan($dispatch['plan_id']);
            
            // Update job status to In Progress
            $stmt = $this->db->prepare("UPDATE jobs SET status = 'In Progress' WHERE id = ?");
            $stmt->execute([$plan['job_id']]);
            
            // Log job status change
            $stmt = $this->db->prepare("
                INSERT INTO job_status_history (job_id, old_status, new_status, changed_by, reason)
                VALUES (?, 'Dispatched', 'In Progress', ?, ?)
            ");
            $stmt->execute([$plan['job_id'], $_SESSION['user_id'], 'Dispatch #' . $dispatch['do_number'] . ' delivered']);
            
            // Audit log
            $this->audit->log(
                'deliver',
                'DISPATCH',
                $dispatchId,
                ['status' => 'Dispatched'],
                ['status' => 'Delivered']
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
     * Cancel dispatch
     */
    public function cancel(int $dispatchId, string $reason): array {
        try {
            if (empty(trim($reason))) {
                return ['success' => false, 'error' => 'กรุณาระบุเหตุผลในการยกเลิก'];
            }
            
            $dispatch = $this->getById($dispatchId);
            if (!$dispatch) {
                return ['success' => false, 'error' => 'Dispatch not found'];
            }
            if ($dispatch['status'] === 'Cancelled') {
                return ['success' => false, 'error' => 'Dispatch นี้ถูกยกเลิกไปแล้ว'];
            }
            if ($dispatch['status'] === 'Delivered') {
                return ['success' => false, 'error' => 'ไม่สามารถยกเลิก Dispatch ที่ส่งถึงแล้ว'];
            }
            
            $this->db->beginTransaction();
            
            // If dispatched, revert serial status to Allocated
            if ($dispatch['status'] === 'Dispatched') {
                $items = $this->getItems($dispatchId);
                foreach ($items as $item) {
                    $stmt = $this->db->prepare("UPDATE serials SET status = 'Allocated' WHERE id = ?");
                    $stmt->execute([$item['serial_id']]);
                }
                
                // Revert job status to Planned
                $plan = $this->getPlan($dispatch['plan_id']);
                $stmt = $this->db->prepare("UPDATE jobs SET status = 'Planned' WHERE id = ?");
                $stmt->execute([$plan['job_id']]);
                
                // Log job status change
                $stmt = $this->db->prepare("
                    INSERT INTO job_status_history (job_id, old_status, new_status, changed_by, reason)
                    VALUES (?, 'Dispatched', 'Planned', ?, ?)
                ");
                $stmt->execute([$plan['job_id'], $_SESSION['user_id'], 'Dispatch cancelled: ' . $reason]);
            }
            
            // Update dispatch status
            $stmt = $this->db->prepare("
                UPDATE dispatch_notes 
                SET status = 'Cancelled', cancelled_at = NOW(), cancelled_by = ?, cancel_reason = ?
                WHERE id = ?
            ");
            $stmt->execute([$_SESSION['user_id'], $reason, $dispatchId]);
            
            // Audit log
            $this->audit->log(
                'cancel',
                'DISPATCH',
                $dispatchId,
                ['status' => $dispatch['status']],
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
     * Get dispatch by ID
     */
    public function getById(int $id): ?array {
        $stmt = $this->db->prepare("
            SELECT dn.*, 
                   p.plan_number, p.job_id,
                   j.job_number, j.scope_short,
                   c.name as customer_name,
                   u.full_name as created_by_name
            FROM dispatch_notes dn
            LEFT JOIN plans p ON dn.plan_id = p.id
            LEFT JOIN jobs j ON p.job_id = j.id
            LEFT JOIN customers c ON j.customer_id = c.id
            LEFT JOIN users u ON dn.created_by = u.id
            WHERE dn.id = ?
        ");
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }
    
    /**
     * Get dispatch items
     */
    public function getItems(int $dispatchId): array {
        $stmt = $this->db->prepare("
            SELECT di.*,
                   s.serial_number, s.status as serial_status,
                   i.name as item_name, i.code as item_code
            FROM dispatch_items di
            LEFT JOIN serials s ON di.serial_id = s.id
            LEFT JOIN items i ON s.item_id = i.id
            WHERE di.dispatch_id = ?
            ORDER BY di.id
        ");
        $stmt->execute([$dispatchId]);
        return $stmt->fetchAll();
    }
    
    /**
     * Get list of dispatch notes
     */
    public function getList(array $filters = [], int $limit = 50, int $offset = 0): array {
        $where = ['1=1'];
        $params = [];
        
        if (!empty($filters['status'])) {
            $where[] = 'dn.status = :status';
            $params['status'] = $filters['status'];
        }
        
        if (!empty($filters['plan_id'])) {
            $where[] = 'dn.plan_id = :plan_id';
            $params['plan_id'] = $filters['plan_id'];
        }
        
        $sql = "
            SELECT dn.*, 
                   p.plan_number,
                   j.job_number, j.scope_short,
                   c.name as customer_name
            FROM dispatch_notes dn
            LEFT JOIN plans p ON dn.plan_id = p.id
            LEFT JOIN jobs j ON p.job_id = j.id
            LEFT JOIN customers c ON j.customer_id = c.id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY dn.id DESC
            LIMIT $limit OFFSET $offset
        ";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
    
    /**
     * Get plans ready for dispatch
     */
    public function getPlansReadyForDispatch(): array {
        $stmt = $this->db->prepare("
            SELECT p.*, j.job_number, j.scope_short, c.name as customer_name
            FROM plans p
            LEFT JOIN jobs j ON p.job_id = j.id
            LEFT JOIN customers c ON j.customer_id = c.id
            WHERE p.status = 'Confirmed'
            AND j.status = 'Planned'
            AND NOT EXISTS (
                SELECT 1 FROM dispatch_notes dn 
                WHERE dn.plan_id = p.id AND dn.status IN ('Draft', 'Dispatched')
            )
            ORDER BY j.plan_start_date ASC
        ");
        $stmt->execute();
        return $stmt->fetchAll();
    }
    
    // Helper methods
    private function getPlan(int $id): ?array {
        $stmt = $this->db->prepare("SELECT * FROM plans WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }
    
    private function getJob(int $id): ?array {
        $stmt = $this->db->prepare("SELECT * FROM jobs WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }
}
