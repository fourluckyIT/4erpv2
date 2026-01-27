<?php
/**
 * Route Model Class
 * 4ERP - Phase 5 v2
 * 
 * Handles Route CRUD with:
 * - 1 Plan → Many Routes
 * - Route items (serials/people per route)
 * - Status management with photo enforcement
 * - Job status integration
 */

require_once __DIR__ . '/DocumentNumber.php';
require_once __DIR__ . '/../includes/booking_conflicts.php';
require_once __DIR__ . '/Notification.php';
require_once __DIR__ . '/RouteReminder.php';

class Route {
    private PDO $db;
    private AuditLog $audit;
    private DocumentNumber $docNum;
    private BookingConflict $conflict;
    private Notification $notification;
    private RouteReminder $reminder;
    
    // Photo requirements per event
    const PHOTOS_REQUIRED = 4;
    
    public function __construct() {
        $this->db = getDB();
        $this->audit = new AuditLog();
        $this->docNum = new DocumentNumber();
        $this->conflict = new BookingConflict($this->db);
        $this->notification = new Notification();
        $this->reminder = new RouteReminder();
    }
    
    /**
     * Create a new route for a confirmed plan
     */
    public function create(int $planId, array $data): array {
        try {
            // Validate plan exists and is confirmed
            $plan = $this->getPlan($planId);
            if (!$plan) {
                return ['success' => false, 'error' => 'Plan not found'];
            }
            if ($plan['status'] !== 'Confirmed') {
                return ['success' => false, 'error' => 'เฉพาะ Plan ที่ Confirmed แล้วเท่านั้นที่สามารถสร้าง Route ได้'];
            }
            
            // Generate route number
            $routeNumber = $this->docNum->generate('ROUTE');
            
            $this->db->beginTransaction();
            
            $stmt = $this->db->prepare("
                INSERT INTO routes (
                    route_number, plan_id, vehicle_serial_id, supplier_id,
                    route_date, status, driver_name, driver_phone, destination, notes, created_by
                ) VALUES (
                    :route_number, :plan_id, :vehicle_serial_id, :supplier_id,
                    :route_date, 'Draft', :driver_name, :driver_phone, :destination, :notes, :created_by
                )
            ");
            
            $stmt->execute([
                'route_number' => $routeNumber,
                'plan_id' => $planId,
                'vehicle_serial_id' => $data['vehicle_serial_id'] ?? null,
                'supplier_id' => $data['supplier_id'] ?? null,
                'route_date' => $data['route_date'] ?? date('Y-m-d'),
                'driver_name' => $data['driver_name'] ?? null,
                'driver_phone' => $data['driver_phone'] ?? null,
                'destination' => $data['destination'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by' => $_SESSION['user_id']
            ]);
            
            $routeId = (int) $this->db->lastInsertId();
            
            // CONFLICT CHECK: Vehicle
            if (!empty($data['vehicle_serial_id'])) {
                $startTime = ($data['route_date'] ?? date('Y-m-d')) . ' 00:00:00';
                $endTime = ($data['route_date'] ?? date('Y-m-d')) . ' 23:59:59';
                
                // Get all conflicts
                $conflicts = $this->conflict->getConflicts('Serial', $data['vehicle_serial_id'], $startTime, $endTime);
                
                foreach ($conflicts as $c) {
                    // Rule 1: Allow overlapping if reserved by THIS Plan (in plan_assignments)
                    if ($c['reference_table'] === 'plan_assignments') {
                        // Check if this assignment belongs to our plan
                        $stmt = $this->db->prepare("SELECT plan_id FROM plan_assignments WHERE id = ?");
                        $stmt->execute([$c['reference_id']]);
                        $assignmentPlanId = $stmt->fetchColumn();
                        
                        if ($assignmentPlanId == $planId) {
                            continue; // Allowed: It's our own reservation
                        }
                    }
                    
                    // Rule 2: Block overlap with ANY other route (even in same plan, assuming 1 vehicle = 1 route at a time)
                    // If it's a Route, it's a definite usage conflict.
                    
                    throw new Exception("Conflict detected: Vehicle is already booked by {$c['reference_table']} #{$c['reference_id']}");
                }
                
                // Book the vehicle for this Route
                $this->conflict->bookResource('Serial', $data['vehicle_serial_id'], $startTime, $endTime, 'routes', $routeId);
            }
            
            // Audit log
            $this->audit->log(
                AUDIT_ACTION_CREATE,
                'ROUTE',
                $routeId,
                null,
                ['route_number' => $routeNumber, 'plan_id' => $planId]
            );
            
            $this->db->commit();
            
            return ['success' => true, 'id' => $routeId, 'route_number' => $routeNumber];
            
        } catch (Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Add serial item to route
     */
    public function addSerial(int $routeId, int $serialId, string $itemType, string $conditionOut = 'Good', ?string $notes = null): array {
        try {
            $route = $this->getById($routeId);
            if (!$route) {
                return ['success' => false, 'error' => 'Route not found'];
            }
            if ($route['status'] !== 'Draft') {
                return ['success' => false, 'error' => 'ไม่สามารถเพิ่มรายการใน Route ที่ไม่ใช่ Draft'];
            }
            
            // Validate serial is allocated to this plan
            $stmt = $this->db->prepare("
                SELECT s.*, pa.id as assignment_id
                FROM serials s
                JOIN plan_assignments pa ON pa.serial_id = s.id
                JOIN routes r ON r.plan_id = pa.plan_id
                WHERE s.id = ? AND r.id = ?
            ");
            $stmt->execute([$serialId, $routeId]);
            $serial = $stmt->fetch();
            
            if (!$serial) {
                return ['success' => false, 'error' => 'Serial นี้ไม่ได้ถูกจัดสรรใน Plan ที่เกี่ยวข้อง'];
            }
            
            // Check not already in this route
            $stmt = $this->db->prepare("SELECT id FROM route_items WHERE route_id = ? AND serial_id = ?");
            $stmt->execute([$routeId, $serialId]);
            if ($stmt->fetch()) {
                return ['success' => false, 'error' => 'Serial นี้อยู่ใน Route นี้แล้ว'];
            }
            
            $stmt = $this->db->prepare("
                INSERT INTO route_items (route_id, serial_id, item_type, condition_out, notes)
                VALUES (:route_id, :serial_id, :item_type, :condition_out, :notes)
            ");
            $stmt->execute([
                'route_id' => $routeId,
                'serial_id' => $serialId,
                'item_type' => $itemType,
                'condition_out' => $conditionOut,
                'notes' => $notes
            ]);
            
            // CONFLICT CHECK: Serial
            $startTime = ($route['route_date'] ?? date('Y-m-d')) . ' 00:00:00';
            $endTime = ($route['route_date'] ?? date('Y-m-d')) . ' 23:59:59';
            
            // Get all conflicts
            $conflicts = $this->conflict->getConflicts('Serial', $serialId, $startTime, $endTime);
            
            foreach ($conflicts as $c) {
                // Rule 1: Allow overlapping if reserved by THIS Plan
                if ($c['reference_table'] === 'plan_assignments') {
                     // Check assignment -> plan linkage
                    $stmt = $this->db->prepare("SELECT plan_id FROM plan_assignments WHERE id = ?");
                    $stmt->execute([$c['reference_id']]);
                    $assignmentPlanId = $stmt->fetchColumn();
                    
                    if ($assignmentPlanId == $route['plan_id']) {
                        continue; // Allowed: It's our own reservation
                    }
                }
                
                throw new Exception("Conflict detected: Serial is already booked by {$c['reference_table']} #{$c['reference_id']}");
            }
            
            $this->conflict->bookResource('Serial', $serialId, $startTime, $endTime, 'route_items', (int) $this->db->lastInsertId());
            
            return ['success' => true, 'id' => (int) $this->db->lastInsertId()];
            
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Add manpower to route
     */
    public function addPeople(int $routeId, int $peopleId, ?string $notes = null): array {
        try {
            $route = $this->getById($routeId);
            if (!$route) {
                return ['success' => false, 'error' => 'Route not found'];
            }
            if ($route['status'] !== 'Draft') {
                return ['success' => false, 'error' => 'ไม่สามารถเพิ่มบุคลากรใน Route ที่ไม่ใช่ Draft'];
            }
            
            // Check not already in this route
            $stmt = $this->db->prepare("SELECT id FROM route_items WHERE route_id = ? AND people_id = ?");
            $stmt->execute([$routeId, $peopleId]);
            if ($stmt->fetch()) {
                return ['success' => false, 'error' => 'บุคคลนี้อยู่ใน Route นี้แล้ว'];
            }
            
            $stmt = $this->db->prepare("
                INSERT INTO route_items (route_id, people_id, item_type, notes)
                VALUES (:route_id, :people_id, 'Manpower', :notes)
            ");
            $stmt->execute([
                'route_id' => $routeId,
                'people_id' => $peopleId,
                'notes' => $notes
            ]);
            
            $routeItemId = (int) $this->db->lastInsertId();

            // CONFLICT CHECK: People
            $startTime = ($route['route_date'] ?? date('Y-m-d')) . ' 00:00:00';
            $endTime = ($route['route_date'] ?? date('Y-m-d')) . ' 23:59:59';
            
            $conflicts = $this->conflict->getConflicts('Person', $peopleId, $startTime, $endTime);
            
            foreach ($conflicts as $c) {
                if ($c['reference_table'] === 'plan_assignments') {
                    $stmt = $this->db->prepare("SELECT plan_id FROM plan_assignments WHERE id = ?");
                    $stmt->execute([$c['reference_id']]);
                    if ($stmt->fetchColumn() == $route['plan_id']) continue;
                }
                throw new Exception("Conflict detected: Person is already booked by {$c['reference_table']} #{$c['reference_id']}");
            }
            
            $this->conflict->bookResource('Person', $peopleId, $startTime, $endTime, 'route_items', $routeItemId);
            
            return ['success' => true, 'id' => $routeItemId];
            
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Add consumable to route
     */
    public function addConsumable(int $routeId, int $itemId, int $quantity, ?string $notes = null): array {
        try {
            $route = $this->getById($routeId);
            if (!$route) {
                return ['success' => false, 'error' => 'Route not found'];
            }
            if ($route['status'] !== 'Draft') {
                return ['success' => false, 'error' => 'ไม่สามารถเพิ่ม Consumable ใน Route ที่ไม่ใช่ Draft'];
            }
            
            // Check if already exists - update quantity
            $stmt = $this->db->prepare("SELECT id, quantity FROM route_items WHERE route_id = ? AND item_id = ? AND item_type = 'Consumable'");
            $stmt->execute([$routeId, $itemId]);
            $existing = $stmt->fetch();
            
            if ($existing) {
                // Update quantity
                $stmt = $this->db->prepare("UPDATE route_items SET quantity = ? WHERE id = ?");
                $stmt->execute([$quantity, $existing['id']]);
                return ['success' => true, 'id' => $existing['id']];
            }
            
            $stmt = $this->db->prepare("
                INSERT INTO route_items (route_id, item_id, item_type, quantity, notes)
                VALUES (:route_id, :item_id, 'Consumable', :quantity, :notes)
            ");
            $stmt->execute([
                'route_id' => $routeId,
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
     * Remove item from route
     */
    public function removeItem(int $itemId): array {
        try {
            $stmt = $this->db->prepare("
                SELECT ri.*, r.status as route_status
                FROM route_items ri
                JOIN routes r ON ri.route_id = r.id
                WHERE ri.id = ?
            ");
            $stmt->execute([$itemId]);
            $item = $stmt->fetch();
            
            if (!$item) {
                return ['success' => false, 'error' => 'Item not found'];
            }
            if ($item['route_status'] !== 'Draft') {
                return ['success' => false, 'error' => 'ไม่สามารถลบรายการใน Route ที่ไม่ใช่ Draft'];
            }
            
            $stmt = $this->db->prepare("DELETE FROM route_items WHERE id = ?");
            $stmt->execute([$itemId]);
            
            // Release booking
            $this->conflict->cancelBooking('route_items', $itemId);
            
            return ['success' => true];
            
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Confirm route - requires dispatch photos
     */
    public function confirm(int $routeId): array {
        try {
            $route = $this->getById($routeId);
            if (!$route) {
                return ['success' => false, 'error' => 'Route not found'];
            }
            if ($route['status'] !== 'Draft') {
                return ['success' => false, 'error' => 'เฉพาะ Route ที่เป็น Draft เท่านั้นที่สามารถ Confirm ได้'];
            }
            
            // Get route items
            $items = $this->getItems($routeId);
            if (empty($items)) {
                return ['success' => false, 'error' => 'กรุณาเพิ่มรายการใน Route ก่อน Confirm'];
            }
            
            $this->db->beginTransaction();
            
            // Update route status
            $stmt = $this->db->prepare("
                UPDATE routes 
                SET status = 'Confirmed', confirmed_at = NOW(), confirmed_by = ?
                WHERE id = ?
            ");
            $stmt->execute([$_SESSION['user_id'], $routeId]);

            // Create/reset WH dispatch reminder
            $this->reminder->createOrReset($route, $_SESSION['user_id']);

            // Notify WH: Route ready for dispatch
            $whUsers = $this->getUserIdsByRoles(['WH']);
            if (!empty($whUsers)) {
                $title = "Route {$route['route_number']} พร้อมปล่อยรถ";
                $message = "Job: {$route['job_number']} (กรุณา WH ปล่อยรถ)";
                $url = "/4erpv2/modules/logistics/routes/view.php?id={$routeId}";
                $this->notification->createBulk(
                    $whUsers,
                    Notification::TYPE_DISPATCH_ALERT,
                    $title,
                    $message,
                    $url,
                    'ROUTE',
                    $routeId,
                    Notification::PRIORITY_NORMAL
                );
            }
            
            // Audit log
            $this->audit->log(
                'confirm',
                'ROUTE',
                $routeId,
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
     * Dispatch route - REQUIRES 4 dispatch photos
     */
    public function dispatch(int $routeId): array {
        try {
            $route = $this->getById($routeId);
            if (!$route) {
                return ['success' => false, 'error' => 'Route not found'];
            }
            if ($route['status'] !== 'Confirmed') {
                return ['success' => false, 'error' => 'เฉพาะ Route ที่ Confirmed แล้วเท่านั้นที่สามารถ Dispatch ได้'];
            }
            
            // Check dispatch photos
            $photoCount = $this->getPhotoCount($routeId, 'Dispatch');
            if ($photoCount < self::PHOTOS_REQUIRED) {
                return [
                    'success' => false, 
                    'error' => "กรุณาอัพโหลดรูป Dispatch ให้ครบ " . self::PHOTOS_REQUIRED . " รูป (ปัจจุบันมี $photoCount รูป)"
                ];
            }
            
            $this->db->beginTransaction();
            
            // Update route status
            $stmt = $this->db->prepare("
                UPDATE routes 
                SET status = 'Dispatched', dispatched_at = NOW(), dispatched_by = ?
                WHERE id = ?
            ");
            $stmt->execute([$_SESSION['user_id'], $routeId]);
            
            // Update serial status for items in this route
            $items = $this->getItems($routeId);
            foreach ($items as $item) {
                if ($item['serial_id']) {
                    $stmt = $this->db->prepare("UPDATE serials SET status = 'Dispatched' WHERE id = ?");
                    $stmt->execute([$item['serial_id']]);
                }
            }
            
            // Check if all routes in plan are dispatched, then update job status
            $this->checkAndUpdateJobStatus($route['plan_id']);

            // Stop reminder and notify planner + supervisor
            $this->reminder->stop($routeId, 'Route dispatched', $_SESSION['user_id']);
            $job = $this->getJob($route['job_id']);
            $notifyIds = [];
            if (!empty($job['owner_planner_id'])) {
                $notifyIds[] = (int) $job['owner_planner_id'];
            }
            $notifyIds = array_merge($notifyIds, $this->getUserIdsByRoles(['MGR']));
            $notifyIds = array_values(array_unique($notifyIds));
            if (!empty($notifyIds)) {
                $this->notification->notifyDispatch($routeId, $route['route_number'], $job['job_number'], $notifyIds);
            }
            
            // Audit log
            $this->audit->log(
                'dispatch',
                'ROUTE',
                $routeId,
                ['status' => 'Confirmed'],
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
     * Mark route as in progress (arrived at site)
     */
    public function startProgress(int $routeId): array {
        try {
            $route = $this->getById($routeId);
            if (!$route) {
                return ['success' => false, 'error' => 'Route not found'];
            }
            if ($route['status'] !== 'Dispatched') {
                return ['success' => false, 'error' => 'เฉพาะ Route ที่ Dispatched แล้วเท่านั้นที่สามารถเริ่มงานได้'];
            }
            
            // Check receive photos
            $photoCount = $this->getPhotoCount($routeId, 'Receive');
            if ($photoCount < self::PHOTOS_REQUIRED) {
                return [
                    'success' => false, 
                    'error' => "กรุณาอัพโหลดรูป Receive ให้ครบ " . self::PHOTOS_REQUIRED . " รูป (ปัจจุบันมี $photoCount รูป)"
                ];
            }
            
            $this->db->beginTransaction();
            
            $stmt = $this->db->prepare("
                UPDATE routes 
                SET status = 'InProgress', in_progress_at = NOW(), in_progress_by = ?
                WHERE id = ?
            ");
            $stmt->execute([$_SESSION['user_id'], $routeId]);
            
            // Update serial status
            $items = $this->getItems($routeId);
            foreach ($items as $item) {
                if ($item['serial_id']) {
                    $stmt = $this->db->prepare("UPDATE serials SET status = 'InUse' WHERE id = ?");
                    $stmt->execute([$item['serial_id']]);
                }
            }
            
            // Update job status
            $this->checkAndUpdateJobStatus($route['plan_id']);
            
            // Audit log
            $this->audit->log(
                'start_progress',
                'ROUTE',
                $routeId,
                ['status' => 'Dispatched'],
                ['status' => 'InProgress']
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
     * Mark route as returned from site
     */
    public function markReturned(int $routeId, array $itemConditions = [], array $consumableUsed = []): array {
        try {
            $route = $this->getById($routeId);
            if (!$route) {
                return ['success' => false, 'error' => 'Route not found'];
            }
            if (!in_array($route['status'], ['Dispatched', 'InProgress'])) {
                return ['success' => false, 'error' => 'Route ต้องอยู่ในสถานะ Dispatched หรือ In Progress'];
            }
            
            // Check return photos
            $photoCount = $this->getPhotoCount($routeId, 'Return');
            if ($photoCount < self::PHOTOS_REQUIRED) {
                return [
                    'success' => false, 
                    'error' => "กรุณาอัพโหลดรูป Return ให้ครบ " . self::PHOTOS_REQUIRED . " รูป (ปัจจุบันมี $photoCount รูป)"
                ];
            }
            
            $this->db->beginTransaction();
            
            $stmt = $this->db->prepare("
                UPDATE routes 
                SET status = 'Returned', returned_at = NOW(), returned_by = ?
                WHERE id = ?
            ");
            $stmt->execute([$_SESSION['user_id'], $routeId]);
            
            // Update item conditions and serial status
            $items = $this->getItems($routeId);
            foreach ($items as $item) {
                if ($item['serial_id']) {
                    $conditionIn = $itemConditions[$item['id']] ?? 'Good';
                    
                    // Update route_items
                    $stmt = $this->db->prepare("UPDATE route_items SET condition_in = ? WHERE id = ?");
                    $stmt->execute([$conditionIn, $item['id']]);
                    
                    // Update serial status
                    $serialStatus = ($conditionIn === 'Lost') ? 'Lost' : 'Returned';
                    $stmt = $this->db->prepare("UPDATE serials SET status = ? WHERE id = ?");
                    $stmt->execute([$serialStatus, $item['serial_id']]);
                } elseif ($item['item_type'] === 'Consumable' && isset($consumableUsed[$item['id']])) {
                    // Record actual used quantity for consumables
                    $qtyUsed = (float) $consumableUsed[$item['id']];
                    $qtyOut = (float) $item['qty_out'];
                    $qtyReturned = max(0, $qtyOut - $qtyUsed);
                    
                    // Update route_items with actual used and returned qty
                    $stmt = $this->db->prepare("UPDATE route_items SET qty_used = ?, qty_in = ? WHERE id = ?");
                    $stmt->execute([$qtyUsed, $qtyReturned, $item['id']]);
                }
            }
            
            // Check job status
            $this->checkAndUpdateJobStatus($route['plan_id']);
            
            // Audit log
            $this->audit->log(
                'return',
                'ROUTE',
                $routeId,
                ['status' => $route['status']],
                ['status' => 'Returned', 'consumable_used' => $consumableUsed]
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
     * WH Receive - warehouse receives returned items
     */
    public function whReceive(int $routeId): array {
        try {
            $route = $this->getById($routeId);
            if (!$route) {
                return ['success' => false, 'error' => 'Route not found'];
            }
            if ($route['status'] !== 'Returned') {
                return ['success' => false, 'error' => 'เฉพาะ Route ที่ Returned แล้วเท่านั้นที่สามารถ WH Receive ได้'];
            }
            
            $this->db->beginTransaction();
            
            $stmt = $this->db->prepare("
                UPDATE routes 
                SET status = 'WHReceived', wh_received_at = NOW(), wh_received_by = ?
                WHERE id = ?
            ");
            $stmt->execute([$_SESSION['user_id'], $routeId]);
            
            // Update serial status - returned items are now Available (if Good/Fair)
            $items = $this->getItems($routeId);
            foreach ($items as $item) {
                if ($item['serial_id'] && $item['condition_in'] !== 'Lost') {
                    $stmt = $this->db->prepare("
                        UPDATE serials 
                        SET status = 'Available', current_job_id = NULL 
                        WHERE id = ?
                    ");
                    $stmt->execute([$item['serial_id']]);
                }
            }
            
            // Check job status
            $this->checkAndUpdateJobStatus($route['plan_id']);
            
            // Audit log
            $this->audit->log(
                'wh_receive',
                'ROUTE',
                $routeId,
                ['status' => 'Returned'],
                ['status' => 'WHReceived']
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
     * Cancel route
     */
    public function cancel(int $routeId, string $reason): array {
        try {
            if (empty(trim($reason))) {
                return ['success' => false, 'error' => 'กรุณาระบุเหตุผลในการยกเลิก'];
            }
            
            $route = $this->getById($routeId);
            if (!$route) {
                return ['success' => false, 'error' => 'Route not found'];
            }
            if ($route['status'] === 'Cancelled') {
                return ['success' => false, 'error' => 'Route นี้ถูกยกเลิกไปแล้ว'];
            }
            if (in_array($route['status'], ['InProgress', 'Returned', 'WHReceived'])) {
                return ['success' => false, 'error' => 'ไม่สามารถยกเลิก Route ที่อยู่ระหว่างดำเนินการหรือเสร็จสิ้นแล้ว'];
            }
            
            $this->db->beginTransaction();
            
            // Revert serial status if dispatched
            if ($route['status'] === 'Dispatched') {
                $items = $this->getItems($routeId);
                foreach ($items as $item) {
                    if ($item['serial_id']) {
                        $stmt = $this->db->prepare("UPDATE serials SET status = 'Allocated' WHERE id = ?");
                        $stmt->execute([$item['serial_id']]);
                    }
                }
            }
            
            $stmt = $this->db->prepare("
                UPDATE routes 
                SET status = 'Cancelled', cancelled_at = NOW(), cancelled_by = ?, cancel_reason = ?
                WHERE id = ?
            ");
            $stmt->execute([$_SESSION['user_id'], $reason, $routeId]);
            
            // Release vehicle booking
            $this->conflict->cancelBooking('routes', $routeId);
            
            // Release all item bookings
            $stmt = $this->db->prepare("SELECT id FROM route_items WHERE route_id = ?");
            $stmt->execute([$routeId]);
            $itemIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
            foreach ($itemIds as $iid) {
                $this->conflict->cancelBooking('route_items', $iid);
            }
            
            // Audit log
            $this->audit->log(
                'cancel',
                'ROUTE',
                $routeId,
                ['status' => $route['status']],
                ['status' => 'Cancelled'],
                $reason
            );

            // Stop reminder on cancel
            $this->reminder->stop($routeId, 'Route cancelled', $_SESSION['user_id']);
            
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
     * Get route by ID
     */
    public function getById(int $id): ?array {
        $stmt = $this->db->prepare("
            SELECT r.*, 
                   p.plan_number, p.job_id,
                   j.job_number, j.scope_short,
                   c.name as customer_name,
                   vs.serial_number as vehicle_serial,
                   vi.name as vehicle_name,
                   sup.name as supplier_name,
                   u.full_name as created_by_name
            FROM routes r
            LEFT JOIN plans p ON r.plan_id = p.id
            LEFT JOIN jobs j ON p.job_id = j.id
            LEFT JOIN customers c ON j.customer_id = c.id
            LEFT JOIN serials vs ON r.vehicle_serial_id = vs.id
            LEFT JOIN items vi ON vs.item_id = vi.id
            LEFT JOIN suppliers sup ON r.supplier_id = sup.id
            LEFT JOIN users u ON r.created_by = u.id
            WHERE r.id = ?
        ");
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }
    
    /**
     * Get routes by plan ID
     */
    public function getByPlanId(int $planId): array {
        $stmt = $this->db->prepare("
            SELECT r.*, 
                   vs.serial_number as vehicle_serial,
                   vi.name as vehicle_name,
                   sup.name as supplier_name, sup.code as supplier_code
            FROM routes r
            LEFT JOIN serials vs ON r.vehicle_serial_id = vs.id
            LEFT JOIN items vi ON vs.item_id = vi.id
            LEFT JOIN suppliers sup ON r.supplier_id = sup.id
            WHERE r.plan_id = ?
            ORDER BY r.route_date, r.id
        ");
        $stmt->execute([$planId]);
        return $stmt->fetchAll();
    }
    
    /**
     * Get route items
     */
    public function getItems(int $routeId): array {
        $stmt = $this->db->prepare("
            SELECT ri.*,
                   s.serial_number, s.status as serial_status,
                   COALESCE(i.name, ci.name) as item_name, 
                   COALESCE(i.code, ci.code) as item_code, 
                   COALESCE(i.item_type, ci.item_type) as item_category,
                   pe.full_name as people_name, pe.code as people_code, pe.position
            FROM route_items ri
            LEFT JOIN serials s ON ri.serial_id = s.id
            LEFT JOIN items i ON s.item_id = i.id
            LEFT JOIN items ci ON ri.item_id = ci.id
            LEFT JOIN people pe ON ri.people_id = pe.id
            WHERE ri.route_id = ?
            ORDER BY ri.item_type, ri.id
        ");
        $stmt->execute([$routeId]);
        return $stmt->fetchAll();
    }
    
    /**
     * Get list of routes
     */
    public function getList(array $filters = [], int $limit = 50, int $offset = 0): array {
        $where = ['1=1'];
        $params = [];
        
        if (!empty($filters['status'])) {
            $where[] = 'r.status = :status';
            $params['status'] = $filters['status'];
        }
        
        if (!empty($filters['plan_id'])) {
            $where[] = 'r.plan_id = :plan_id';
            $params['plan_id'] = $filters['plan_id'];
        }
        
        if (!empty($filters['date_from'])) {
            $where[] = 'r.route_date >= :date_from';
            $params['date_from'] = $filters['date_from'];
        }
        
        if (!empty($filters['date_to'])) {
            $where[] = 'r.route_date <= :date_to';
            $params['date_to'] = $filters['date_to'];
        }
        
        $sql = "
            SELECT r.*, 
                   p.plan_number,
                   j.job_number, j.scope_short,
                   c.name as customer_name,
                   vs.serial_number as vehicle_serial
            FROM routes r
            LEFT JOIN plans p ON r.plan_id = p.id
            LEFT JOIN jobs j ON p.job_id = j.id
            LEFT JOIN customers c ON j.customer_id = c.id
            LEFT JOIN serials vs ON r.vehicle_serial_id = vs.id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY r.route_date DESC, r.id DESC
            LIMIT $limit OFFSET $offset
        ";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
    
    /**
     * Get photo count for route event
     */
    public function getPhotoCount(int $routeId, string $eventType): int {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) FROM evidence_photos 
            WHERE route_id = ? AND event_type = ?
        ");
        $stmt->execute([$routeId, $eventType]);
        return (int) $stmt->fetchColumn();
    }
    
    /**
     * Check and update job status based on route statuses
     */
    private function checkAndUpdateJobStatus(int $planId): void {
        $plan = $this->getPlan($planId);
        if (!$plan) return;
        
        $jobId = $plan['job_id'];
        
        // Get all route statuses for this plan
        $stmt = $this->db->prepare("
            SELECT status, COUNT(*) as cnt 
            FROM routes 
            WHERE plan_id = ? AND status != 'Cancelled'
            GROUP BY status
        ");
        $stmt->execute([$planId]);
        $statuses = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        
        $total = array_sum($statuses);
        if ($total === 0) return;
        
        // Determine job status based on route statuses
        $newJobStatus = null;
        $currentJob = $this->getJob($jobId);
        
        if (isset($statuses['WHReceived']) && $statuses['WHReceived'] == $total) {
            $newJobStatus = 'WH Received';
        } elseif (isset($statuses['Returned']) && ($statuses['Returned'] + ($statuses['WHReceived'] ?? 0)) == $total) {
            $newJobStatus = 'Returned';
        } elseif (isset($statuses['InProgress']) && $statuses['InProgress'] > 0) {
            $newJobStatus = 'In Progress';
        } elseif (isset($statuses['Dispatched']) && $statuses['Dispatched'] > 0) {
            $newJobStatus = 'Dispatched';
        }
        
        if ($newJobStatus && $currentJob['status'] !== $newJobStatus) {
            $stmt = $this->db->prepare("UPDATE jobs SET status = ? WHERE id = ?");
            $stmt->execute([$newJobStatus, $jobId]);
            
            // Log status change
            $stmt = $this->db->prepare("
                INSERT INTO job_status_history (job_id, old_status, new_status, changed_by, reason)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $jobId, 
                $currentJob['status'], 
                $newJobStatus, 
                $_SESSION['user_id'],
                'Auto-updated from route status changes'
            ]);
        }
    }

    /**
     * M3: Add Photo Evidence
     */
    public function addPhoto(int $routeId, string $eventType, string $filePath, ?string $caption = null): array {
        try {
            // Validate Route
            $route = $this->getById($routeId);
            if (!$route) throw new Exception("Route not found");
            
            // Determine seq
            $stmt = $this->db->prepare("SELECT COUNT(*) FROM evidence_photos WHERE route_id = ? AND event_type = ?");
            $stmt->execute([$routeId, $eventType]);
            $seq = $stmt->fetchColumn() + 1;
            
            $stmt = $this->db->prepare("
                INSERT INTO evidence_photos (route_id, event_type, photo_seq, file_path, caption, uploaded_by)
                VALUES (:route_id, :event_type, :seq, :file_path, :caption, :user_id)
            ");
            
            $stmt->execute([
                'route_id' => $routeId,
                'event_type' => $eventType,
                'seq' => $seq,
                'file_path' => $filePath,
                'caption' => $caption,
                'user_id' => $_SESSION['user_id'] ?? 1
            ]);
            
            return ['success' => true, 'id' => $this->db->lastInsertId()];
            
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * M3: Check Transition Rules
     */
    public function canTransition(int $routeId, string $toStatus): array {
        $route = $this->getById($routeId);
        if (!$route) return ['allowed' => false, 'reason' => 'Route not found'];
        
        $current = $route['status'];
        
        // Linear Progression (Simplified for M3)
        // Confirmed -> Dispatched -> InProgress -> Returned -> WHReceived
        
        if ($toStatus === 'Dispatched') {
            // Requirement: 4 'Dispatch' photos
            $count = $this->getPhotoCount($routeId, 'Dispatch');
            if ($count < self::PHOTOS_REQUIRED) {
                return ['allowed' => false, 'reason' => "Need 4 Dispatch photos (Current: $count)"];
            }
        }
        
        if ($toStatus === 'Returned') {
             // Requirement: 4 'Return' photos
            $count = $this->getPhotoCount($routeId, 'Return');
            if ($count < self::PHOTOS_REQUIRED) {
                return ['allowed' => false, 'reason' => "Need 4 Return photos (Current: $count)"];
            }
        }
        
        return ['allowed' => true];
    }
    
    /**
     * M3: Transition Status
     */
    public function transitionStatus(int $routeId, string $toStatus, ?string $reason = null): array {
        $startedTransaction = false;
        try {
            // Check Rules
            $check = $this->canTransition($routeId, $toStatus);
            if (!$check['allowed']) {
                return ['success' => false, 'error' => $check['reason']];
            }
            
            $route = $this->getById($routeId);
            $fromStatus = $route['status'];
            
            if ($fromStatus === $toStatus) {
                return ['success' => true, 'message' => 'Status unchanged'];
            }
            
            if (!$this->db->inTransaction()) {
                $this->db->beginTransaction();
                $startedTransaction = true;
            }
            
            // Update Status
            $stmt = $this->db->prepare("UPDATE routes SET status = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$toStatus, $routeId]);
            
            // Log History
            $stmt = $this->db->prepare("
                INSERT INTO route_status_history (route_id, from_status, to_status, reason, created_by)
                VALUES (:route_id, :from_status, :to_status, :reason, :user_id)
            ");
            $stmt->execute([
                'route_id' => $routeId,
                'from_status' => $fromStatus,
                'to_status' => $toStatus,
                'reason' => $reason,
                'user_id' => $_SESSION['user_id'] ?? 1
            ]);
            
            // Audit
            $this->audit->log('status_change', 'routes', $routeId, ['from' => $fromStatus], ['to' => $toStatus]);
            
            // If status changed, check Job Status update (existing logic)
            $stmt = $this->db->prepare("SELECT plan_id FROM routes WHERE id = ?");
            $stmt->execute([$routeId]);
            $planId = $stmt->fetchColumn();
            if ($planId) {
                $this->checkAndUpdateJobStatus($planId);
            }
            
            if ($startedTransaction) $this->db->commit();
            
            return ['success' => true];
            
        } catch (Exception $e) {
            if ($startedTransaction && $this->db->inTransaction()) $this->db->rollBack();
            return ['success' => false, 'error' => $e->getMessage()];
        }
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

    private function getUserIdsByRoles(array $roles): array {
        if (empty($roles)) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($roles), '?'));
        $stmt = $this->db->prepare("
            SELECT DISTINCT u.id
            FROM users u
            JOIN user_roles ur ON u.id = ur.user_id
            JOIN roles r ON ur.role_id = r.id
            WHERE r.code IN ($placeholders) AND u.is_active = 1
        ");
        $stmt->execute($roles);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }
}
