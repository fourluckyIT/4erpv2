<?php
/**
 * Reservation Model Class
 * 4ERP - M7: Reservation System
 * 
 * Handles:
 * - Serial reservation (1 serial = 1 allocation only)
 * - Quantity reservation for consumables
 * - Concurrent allocation prevention with FOR UPDATE locking
 * - Stock level calculation (Available = OnHand - Reserved)
 * 
 * Following blueprint.md §9 and agents.md §1.6
 */

class Reservation {
    private PDO $db;
    private AuditLog $audit;
    
    const STATUS_RESERVED = 'Reserved';
    const STATUS_ALLOCATED = 'Allocated';
    const STATUS_RELEASED = 'Released';
    const STATUS_CANCELLED = 'Cancelled';
    
    const TYPE_SERIAL = 'Serial';
    const TYPE_QTY = 'Qty';
    
    const SOURCE_PLAN = 'Plan';
    const SOURCE_ROUTE = 'Route';
    const SOURCE_PACKAGE = 'Package';
    
    public function __construct() {
        $this->db = getDB();
        $this->audit = new AuditLog();
    }
    
    /**
     * Reserve a specific serial for a plan/route
     * Uses SELECT FOR UPDATE to prevent concurrent double-booking
     */
    public function reserveSerial(int $serialId, string $sourceType, int $sourceId, int $jobId, ?string $notes = null): array {
        try {
            $this->db->beginTransaction();
            
            // Lock the serial row to prevent concurrent reservation
            $stmt = $this->db->prepare("
                SELECT s.id, s.item_id, s.status, s.serial_number, s.current_job_id
                FROM serials s
                WHERE s.id = :serial_id
                FOR UPDATE
            ");
            $stmt->execute(['serial_id' => $serialId]);
            $serial = $stmt->fetch();
            
            if (!$serial) {
                $this->db->rollBack();
                return ['success' => false, 'error' => 'Serial not found'];
            }
            
            // Check serial is available
            if ($serial['status'] !== 'Available') {
                $this->db->rollBack();
                return ['success' => false, 'error' => "Serial {$serial['serial_number']} ไม่ว่าง (สถานะ: {$serial['status']})"];
            }
            
            // Check not already reserved (active reservation exists)
            $stmt = $this->db->prepare("
                SELECT id FROM reservations 
                WHERE serial_id = :serial_id AND status IN ('Reserved', 'Allocated')
            ");
            $stmt->execute(['serial_id' => $serialId]);
            if ($stmt->fetch()) {
                $this->db->rollBack();
                return ['success' => false, 'error' => "Serial {$serial['serial_number']} ถูกจองไว้แล้ว"];
            }
            
            // Create reservation
            $stmt = $this->db->prepare("
                INSERT INTO reservations (
                    reservation_type, item_id, serial_id, qty, location,
                    source_type, source_id, job_id, status, reserved_by
                ) VALUES (
                    'Serial', :item_id, :serial_id, 1, 'WH',
                    :source_type, :source_id, :job_id, 'Reserved', :user_id
                )
            ");
            $stmt->execute([
                'item_id' => $serial['item_id'],
                'serial_id' => $serialId,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'job_id' => $jobId,
                'user_id' => $_SESSION['user_id']
            ]);
            
            $reservationId = (int) $this->db->lastInsertId();
            
            // Update serial status to Allocated
            $stmt = $this->db->prepare("
                UPDATE serials 
                SET status = 'Allocated', current_job_id = ?
                WHERE id = ?
            ");
            $stmt->execute([$jobId, $serialId]);
            
            // Log reservation
            $this->logReservation($reservationId, 'create', null, self::STATUS_RESERVED, 1, $notes);
            
            // Update stock levels
            $this->updateStockLevel($serial['item_id'], 'WH');
            
            $this->db->commit();
            
            $this->audit->log(
                'reserve',
                'RESERVATION',
                $reservationId,
                null,
                ['serial_id' => $serialId, 'serial_number' => $serial['serial_number'], 'job_id' => $jobId]
            );
            
            return ['success' => true, 'id' => $reservationId];
            
        } catch (Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Reserve a quantity of an item (for consumables)
     */
    public function reserveQty(int $itemId, float $qty, string $location, string $sourceType, int $sourceId, int $jobId, ?string $notes = null): array {
        try {
            $this->db->beginTransaction();
            
            // Lock stock level row
            $stmt = $this->db->prepare("
                SELECT isl.*, i.name as item_name
                FROM item_stock_levels isl
                JOIN items i ON isl.item_id = i.id
                WHERE isl.item_id = :item_id AND isl.location = :location
                FOR UPDATE
            ");
            $stmt->execute(['item_id' => $itemId, 'location' => $location]);
            $stock = $stmt->fetch();
            
            if (!$stock) {
                // Create stock level record if not exists
                $stmt = $this->db->prepare("
                    INSERT INTO item_stock_levels (item_id, location, on_hand, reserved)
                    VALUES (:item_id, :location, 0, 0)
                ");
                $stmt->execute(['item_id' => $itemId, 'location' => $location]);
                $stock = ['on_hand' => 0, 'reserved' => 0, 'available' => 0];
            }
            
            // Check available quantity
            $available = (float)$stock['on_hand'] - (float)$stock['reserved'];
            if ($available < $qty) {
                $this->db->rollBack();
                return ['success' => false, 'error' => "ไม่มีสินค้าเพียงพอ (ต้องการ: {$qty}, มี: {$available})"];
            }
            
            // Create reservation
            $stmt = $this->db->prepare("
                INSERT INTO reservations (
                    reservation_type, item_id, serial_id, qty, location,
                    source_type, source_id, job_id, status, reserved_by
                ) VALUES (
                    'Qty', :item_id, NULL, :qty, :location,
                    :source_type, :source_id, :job_id, 'Reserved', :user_id
                )
            ");
            $stmt->execute([
                'item_id' => $itemId,
                'qty' => $qty,
                'location' => $location,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'job_id' => $jobId,
                'user_id' => $_SESSION['user_id']
            ]);
            
            $reservationId = (int) $this->db->lastInsertId();
            
            // Update reserved quantity
            $stmt = $this->db->prepare("
                UPDATE item_stock_levels 
                SET reserved = reserved + ?
                WHERE item_id = ? AND location = ?
            ");
            $stmt->execute([$qty, $itemId, $location]);
            
            // Log reservation
            $this->logReservation($reservationId, 'create', null, self::STATUS_RESERVED, $qty, $notes);
            
            $this->db->commit();
            
            return ['success' => true, 'id' => $reservationId];
            
        } catch (Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Mark reservation as allocated (actually picked/dispatched)
     */
    public function allocate(int $reservationId): array {
        try {
            $res = $this->getById($reservationId);
            if (!$res) {
                return ['success' => false, 'error' => 'Reservation not found'];
            }
            if ($res['status'] !== self::STATUS_RESERVED) {
                return ['success' => false, 'error' => 'Reservation ไม่อยู่ในสถานะ Reserved'];
            }
            
            $stmt = $this->db->prepare("
                UPDATE reservations 
                SET status = 'Allocated', allocated_at = NOW(), allocated_by = ?
                WHERE id = ?
            ");
            $stmt->execute([$_SESSION['user_id'], $reservationId]);
            
            // Update serial status if serial reservation
            if ($res['serial_id']) {
                $stmt = $this->db->prepare("UPDATE serials SET status = 'Dispatched' WHERE id = ?");
                $stmt->execute([$res['serial_id']]);
            }
            
            $this->logReservation($reservationId, 'allocate', self::STATUS_RESERVED, self::STATUS_ALLOCATED);
            
            return ['success' => true];
            
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Release reservation (when returned or no longer needed)
     */
    public function release(int $reservationId, string $reason): array {
        try {
            $this->db->beginTransaction();
            
            $res = $this->getById($reservationId);
            if (!$res) {
                $this->db->rollBack();
                return ['success' => false, 'error' => 'Reservation not found'];
            }
            if (!in_array($res['status'], [self::STATUS_RESERVED, self::STATUS_ALLOCATED])) {
                $this->db->rollBack();
                return ['success' => false, 'error' => 'Reservation ไม่สามารถ release ได้'];
            }
            
            $oldStatus = $res['status'];
            
            $stmt = $this->db->prepare("
                UPDATE reservations 
                SET status = 'Released', released_at = NOW(), released_by = ?, release_reason = ?
                WHERE id = ?
            ");
            $stmt->execute([$_SESSION['user_id'], $reason, $reservationId]);
            
            // Update serial status if serial reservation
            if ($res['serial_id']) {
                $stmt = $this->db->prepare("
                    UPDATE serials SET status = 'Available', current_job_id = NULL WHERE id = ?
                ");
                $stmt->execute([$res['serial_id']]);
            }
            
            // Update stock level (reduce reserved)
            if ($res['reservation_type'] === self::TYPE_QTY) {
                $stmt = $this->db->prepare("
                    UPDATE item_stock_levels 
                    SET reserved = GREATEST(0, reserved - ?)
                    WHERE item_id = ? AND location = ?
                ");
                $stmt->execute([$res['qty'], $res['item_id'], $res['location']]);
            }
            
            $this->updateStockLevel($res['item_id'], $res['location']);
            
            $this->logReservation($reservationId, 'release', $oldStatus, self::STATUS_RELEASED, null, $reason);
            
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
     * Cancel reservation (before allocation)
     */
    public function cancel(int $reservationId, string $reason): array {
        try {
            $res = $this->getById($reservationId);
            if (!$res) {
                return ['success' => false, 'error' => 'Reservation not found'];
            }
            if ($res['status'] !== self::STATUS_RESERVED) {
                return ['success' => false, 'error' => 'เฉพาะ Reservation ที่ยังไม่ Allocate เท่านั้นที่สามารถยกเลิกได้'];
            }
            
            return $this->release($reservationId, 'Cancelled: ' . $reason);
            
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Reserve all serials and people from a plan
     */
    public function reserveFromPlan(int $planId): array {
        try {
            $stmt = $this->db->prepare("
                SELECT p.id, p.job_id FROM plans p WHERE p.id = ?
            ");
            $stmt->execute([$planId]);
            $plan = $stmt->fetch();
            
            if (!$plan) {
                return ['success' => false, 'error' => 'Plan not found'];
            }
            
            // Get all serial assignments from plan
            $stmt = $this->db->prepare("
                SELECT pa.serial_id
                FROM plan_assignments pa
                WHERE pa.plan_id = ? AND pa.serial_id IS NOT NULL
            ");
            $stmt->execute([$planId]);
            $serialAssignments = $stmt->fetchAll();
            
            $reserved = 0;
            $errors = [];
            
            foreach ($serialAssignments as $sa) {
                $result = $this->reserveSerial($sa['serial_id'], self::SOURCE_PLAN, $planId, $plan['job_id']);
                if ($result['success']) {
                    $reserved++;
                } else {
                    $errors[] = $result['error'];
                }
            }
            
            if (!empty($errors)) {
                return [
                    'success' => false,
                    'error' => 'บาง Serial ไม่สามารถจองได้: ' . implode('; ', $errors),
                    'reserved' => $reserved
                ];
            }
            
            return ['success' => true, 'reserved' => $reserved];
            
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Release all reservations for a source (plan/route)
     */
    public function releaseBySource(string $sourceType, int $sourceId, string $reason): array {
        $stmt = $this->db->prepare("
            SELECT id FROM reservations 
            WHERE source_type = ? AND source_id = ? AND status IN ('Reserved', 'Allocated')
        ");
        $stmt->execute([$sourceType, $sourceId]);
        $reservations = $stmt->fetchAll();
        
        $released = 0;
        foreach ($reservations as $res) {
            $result = $this->release($res['id'], $reason);
            if ($result['success']) {
                $released++;
            }
        }
        
        return ['success' => true, 'released' => $released];
    }
    
    /**
     * Check if serial is available for reservation
     */
    public function isSerialAvailable(int $serialId): bool {
        $stmt = $this->db->prepare("
            SELECT s.status FROM serials s WHERE s.id = ?
        ");
        $stmt->execute([$serialId]);
        $serial = $stmt->fetch();
        
        if (!$serial || $serial['status'] !== 'Available') {
            return false;
        }
        
        // Check no active reservation
        $stmt = $this->db->prepare("
            SELECT id FROM reservations 
            WHERE serial_id = ? AND status IN ('Reserved', 'Allocated')
        ");
        $stmt->execute([$serialId]);
        
        return !$stmt->fetch();
    }
    
    /**
     * Get available quantity for an item at a location
     */
    public function getAvailableQty(int $itemId, string $location = 'WH'): float {
        $stmt = $this->db->prepare("
            SELECT COALESCE(on_hand, 0) - COALESCE(reserved, 0) as available
            FROM item_stock_levels
            WHERE item_id = ? AND location = ?
        ");
        $stmt->execute([$itemId, $location]);
        $result = $stmt->fetch();
        
        return $result ? (float)$result['available'] : 0;
    }
    
    /**
     * Update stock level from stock_movements
     */
    public function updateStockLevel(int $itemId, string $location = 'WH'): void {
        // Calculate on_hand from stock_movements
        $stmt = $this->db->prepare("
            SELECT COALESCE(SUM(qty), 0) as on_hand
            FROM stock_movements
            WHERE item_id = ? AND to_location = ?
        ");
        $stmt->execute([$itemId, $location]);
        $onHand = (float)$stmt->fetchColumn();
        
        // Calculate reserved from active reservations
        $stmt = $this->db->prepare("
            SELECT COALESCE(SUM(qty), 0) as reserved
            FROM reservations
            WHERE item_id = ? AND location = ? AND status IN ('Reserved', 'Allocated')
        ");
        $stmt->execute([$itemId, $location]);
        $reserved = (float)$stmt->fetchColumn();
        
        // Upsert stock level
        $stmt = $this->db->prepare("
            INSERT INTO item_stock_levels (item_id, location, on_hand, reserved)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE on_hand = VALUES(on_hand), reserved = VALUES(reserved)
        ");
        $stmt->execute([$itemId, $location, $onHand, $reserved]);
    }
    
    /**
     * Log reservation action
     */
    private function logReservation(int $reservationId, string $action, ?string $oldStatus, string $newStatus, ?float $qty = null, ?string $reason = null): void {
        $stmt = $this->db->prepare("
            INSERT INTO reservation_log (reservation_id, action, old_status, new_status, qty, reason, performed_by)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$reservationId, $action, $oldStatus, $newStatus, $qty, $reason, $_SESSION['user_id']]);
    }
    
    // ==================== GETTERS ====================
    
    public function getById(int $id): ?array {
        $stmt = $this->db->prepare("
            SELECT r.*, i.name as item_name, i.code as item_code,
                   s.serial_number
            FROM reservations r
            JOIN items i ON r.item_id = i.id
            LEFT JOIN serials s ON r.serial_id = s.id
            WHERE r.id = ?
        ");
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }
    
    public function getBySource(string $sourceType, int $sourceId): array {
        $stmt = $this->db->prepare("
            SELECT r.*, i.name as item_name, s.serial_number
            FROM reservations r
            JOIN items i ON r.item_id = i.id
            LEFT JOIN serials s ON r.serial_id = s.id
            WHERE r.source_type = ? AND r.source_id = ?
            ORDER BY r.created_at
        ");
        $stmt->execute([$sourceType, $sourceId]);
        return $stmt->fetchAll();
    }
    
    public function getByJob(int $jobId): array {
        $stmt = $this->db->prepare("
            SELECT r.*, i.name as item_name, s.serial_number
            FROM reservations r
            JOIN items i ON r.item_id = i.id
            LEFT JOIN serials s ON r.serial_id = s.id
            WHERE r.job_id = ?
            ORDER BY r.created_at DESC
        ");
        $stmt->execute([$jobId]);
        return $stmt->fetchAll();
    }
    
    public function getActiveBySerial(int $serialId): ?array {
        $stmt = $this->db->prepare("
            SELECT * FROM reservations 
            WHERE serial_id = ? AND status IN ('Reserved', 'Allocated')
        ");
        $stmt->execute([$serialId]);
        return $stmt->fetch() ?: null;
    }
    
    public function getStockLevels(array $filters = []): array {
        $where = ['1=1'];
        $params = [];
        
        if (!empty($filters['item_id'])) {
            $where[] = 'isl.item_id = :item_id';
            $params['item_id'] = $filters['item_id'];
        }
        if (!empty($filters['location'])) {
            $where[] = 'isl.location = :location';
            $params['location'] = $filters['location'];
        }
        if (isset($filters['low_stock']) && $filters['low_stock']) {
            $where[] = 'isl.available <= i.min_stock';
        }
        
        $sql = "
            SELECT isl.*, i.code as item_code, i.name as item_name, i.item_type, i.min_stock
            FROM item_stock_levels isl
            JOIN items i ON isl.item_id = i.id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY i.name
        ";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
}
