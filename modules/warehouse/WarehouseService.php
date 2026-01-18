<?php
/**
 * Warehouse Service
 * ERP v2 - Phase M4
 * 
 * Handles:
 * - Stock movement recording (append-only ledger)
 * - WH Receive after Return
 * - Reversal entries (no DELETE allowed)
 * 
 * agents.md compliance:
 * - §1.1: No DELETE on stock_movements
 * - §1.3: Reversal pattern for corrections
 * - §1.5: Audit trail for all actions
 * - §4.6: RBAC for WH actions
 */

require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../../core/AuditLog.php';

class WarehouseService {
    
    private PDO $db;
    private AuditLog $audit;
    
    // Movement types per agents.md
    const MOVE_GI_JOB = 'GI_JOB';        // Goods Issue to Job (Dispatch)
    const MOVE_GR_PO = 'GR_PO';          // Goods Receipt from PO
    const MOVE_RETURN_JOB = 'RETURN_JOB'; // Return from Job site
    const MOVE_WH_RECEIVE = 'WH_RECEIVE'; // WH confirms receipt
    const MOVE_ADJUSTMENT = 'ADJUSTMENT'; // Stock adjustment
    const MOVE_REVERSAL = 'REVERSAL';     // Reversal of prior movement
    
    // Locations
    const LOC_WH = 'WH';
    const LOC_SITE = 'SITE';
    const LOC_IN_TRANSIT = 'IN_TRANSIT';
    const LOC_SUPPLIER = 'SUPPLIER';
    const LOC_VOID = 'VOID';
    
    public function __construct() {
        $this->db = getDB();
        $this->audit = new AuditLog();
    }
    
    /**
     * Record a stock movement (APPEND-ONLY - no delete ever)
     * 
     * @param string $movementType One of MOVE_* constants
     * @param int $itemId
     * @param float $qty Positive=in, Negative=out
     * @param string|null $fromLocation
     * @param string|null $toLocation
     * @param string $referenceTable
     * @param int $referenceId
     * @param int|null $serialId For serialized items
     * @param int|null $reverseOfId If this is a reversal
     * @param string|null $notes
     * @return array ['success' => bool, 'id' => int, 'error' => string]
     */
    public function recordMovement(
        string $movementType,
        int $itemId,
        float $qty,
        ?string $fromLocation,
        ?string $toLocation,
        string $referenceTable,
        int $referenceId,
        ?int $serialId = null,
        ?int $reverseOfId = null,
        ?string $notes = null
    ): array {
        try {
            $this->db->beginTransaction();
            
            $userId = $_SESSION['user_id'] ?? 1;
            
            // Insert movement (APPEND-ONLY)
            $stmt = $this->db->prepare("
                INSERT INTO stock_movements (
                    movement_type, item_id, serial_id, qty,
                    from_location, to_location,
                    reference_table, reference_id, reverse_of_id,
                    notes, created_by
                ) VALUES (
                    :type, :item_id, :serial_id, :qty,
                    :from_loc, :to_loc,
                    :ref_table, :ref_id, :reverse_of,
                    :notes, :user_id
                )
            ");
            
            $stmt->execute([
                ':type' => $movementType,
                ':item_id' => $itemId,
                ':serial_id' => $serialId,
                ':qty' => $qty,
                ':from_loc' => $fromLocation,
                ':to_loc' => $toLocation,
                ':ref_table' => $referenceTable,
                ':ref_id' => $referenceId,
                ':reverse_of' => $reverseOfId,
                ':notes' => $notes,
                ':user_id' => $userId
            ]);
            
            $movementId = (int) $this->db->lastInsertId();
            
            // Update item quantity (running balance)
            $this->updateItemQuantity($itemId, $qty);
            
            // Audit log
            $this->audit->log(
                'stock_movement',
                'stock_movements',
                $movementId,
                null,
                [
                    'type' => $movementType,
                    'item_id' => $itemId,
                    'qty' => $qty,
                    'from' => $fromLocation,
                    'to' => $toLocation,
                    'ref' => "$referenceTable:$referenceId"
                ]
            );
            
            $this->db->commit();
            
            return ['success' => true, 'id' => $movementId];
            
        } catch (Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Record reversal of a movement (agents.md §1.3)
     */
    public function reverseMovement(int $originalMovementId, string $reason): array {
        try {
            // Get original movement
            $stmt = $this->db->prepare("SELECT * FROM stock_movements WHERE id = ?");
            $stmt->execute([$originalMovementId]);
            $original = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$original) {
                return ['success' => false, 'error' => 'Original movement not found'];
            }
            
            // Create reversal entry (negative of original)
            return $this->recordMovement(
                self::MOVE_REVERSAL,
                (int) $original['item_id'],
                -1 * (float) $original['qty'], // Opposite sign
                $original['to_location'],      // Swap from/to
                $original['from_location'],
                'stock_movements',
                $originalMovementId,
                $original['serial_id'] ? (int) $original['serial_id'] : null,
                $originalMovementId,           // reverse_of_id
                "REVERSAL: $reason"
            );
            
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Process WH Receive after Route Return
     * 
     * @param int $routeId Route that was returned
     * @return array
     */
    public function receiveReturn(int $routeId): array {
        try {
            $this->db->beginTransaction();
            
            // Get route
            $stmt = $this->db->prepare("SELECT * FROM routes WHERE id = ?");
            $stmt->execute([$routeId]);
            $route = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$route) {
                throw new Exception("Route not found: $routeId");
            }
            
            if ($route['status'] !== 'Returned') {
                throw new Exception("Route must be in Returned status (current: {$route['status']})");
            }
            
            // Get route items
            $stmt = $this->db->prepare("
                SELECT ri.*, i.name as item_name, i.id as item_id
                FROM route_items ri
                JOIN serials s ON ri.serial_id = s.id
                JOIN items i ON s.item_id = i.id
                WHERE ri.route_id = ?
            ");
            $stmt->execute([$routeId]);
            $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Record stock movements for each item
            foreach ($items as $item) {
                $moveResult = $this->recordMovement(
                    self::MOVE_WH_RECEIVE,
                    (int) $item['item_id'],
                    1, // Each serial = 1 unit
                    self::LOC_IN_TRANSIT,
                    self::LOC_WH,
                    'routes',
                    $routeId,
                    (int) $item['serial_id'],
                    null,
                    "WH Receive from Route #{$routeId}"
                );
                
                if (!$moveResult['success']) {
                    throw new Exception("Failed to record movement: " . $moveResult['error']);
                }
            }
            
            // Transition route to WHReceived (uses Route class for history)
            require_once __DIR__ . '/../../core/Route.php';
            $routeService = new Route();
            $transResult = $routeService->transitionStatus($routeId, 'WHReceived');
            
            if (!$transResult['success']) {
                throw new Exception("Failed to transition route: " . ($transResult['error'] ?? 'Unknown error'));
            }
            
            $this->db->commit();
            
            return [
                'success' => true,
                'route_id' => $routeId,
                'items_received' => count($items)
            ];
            
        } catch (Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Record Goods Issue for Job Dispatch
     */
    public function recordDispatch(int $routeId): array {
        try {
            // Get route items
            $stmt = $this->db->prepare("
                SELECT ri.*, s.item_id
                FROM route_items ri
                JOIN serials s ON ri.serial_id = s.id
                WHERE ri.route_id = ?
            ");
            $stmt->execute([$routeId]);
            $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($items as $item) {
                $this->recordMovement(
                    self::MOVE_GI_JOB,
                    (int) $item['item_id'],
                    -1, // Goods out
                    self::LOC_WH,
                    self::LOC_IN_TRANSIT,
                    'routes',
                    $routeId,
                    (int) $item['serial_id']
                );
            }
            
            return ['success' => true, 'items_dispatched' => count($items)];
            
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Record Return arrival (before WH confirmation)
     */
    public function recordReturn(int $routeId): array {
        try {
            $stmt = $this->db->prepare("
                SELECT ri.*, s.item_id
                FROM route_items ri
                JOIN serials s ON ri.serial_id = s.id
                WHERE ri.route_id = ?
            ");
            $stmt->execute([$routeId]);
            $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($items as $item) {
                $this->recordMovement(
                    self::MOVE_RETURN_JOB,
                    (int) $item['item_id'],
                    1, // Goods back (pending WH)
                    self::LOC_SITE,
                    self::LOC_IN_TRANSIT,
                    'routes',
                    $routeId,
                    (int) $item['serial_id']
                );
            }
            
            return ['success' => true, 'items_returned' => count($items)];
            
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Get stock movements for an item
     */
    public function getMovements(int $itemId, int $limit = 50): array {
        $stmt = $this->db->prepare("
            SELECT sm.*, u.username as created_by_name
            FROM stock_movements sm
            LEFT JOIN users u ON sm.created_by = u.id
            WHERE sm.item_id = ?
            ORDER BY sm.created_at DESC
            LIMIT ?
        ");
        $stmt->execute([$itemId, $limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Get current stock level for an item
     */
    public function getStockLevel(int $itemId): float {
        $stmt = $this->db->prepare("SELECT quantity FROM items WHERE id = ?");
        $stmt->execute([$itemId]);
        return (float) $stmt->fetchColumn();
    }
    
    /**
     * Update item quantity (internal helper)
     */
    private function updateItemQuantity(int $itemId, float $qty): void {
        $stmt = $this->db->prepare("
            UPDATE items SET quantity = quantity + ? WHERE id = ?
        ");
        $stmt->execute([$qty, $itemId]);
    }
}
