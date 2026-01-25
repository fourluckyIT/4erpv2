<?php
/**
 * API: Update Route Status
 * Actions: confirm, dispatch, edit (back to draft), cancel
 */

// Disable HTML errors for API
ini_set('html_errors', 0);
header('Content-Type: application/json');

// Custom error handler for JSON response
set_error_handler(function($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

try {
    require_once __DIR__ . '/../../../../config/bootstrap.php';
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'error' => 'Bootstrap error: ' . $e->getMessage()]);
    exit;
}

$auth = new Auth();
if (!$auth->check()) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$routeId = (int) ($input['route_id'] ?? 0);
$action = $input['action'] ?? '';
$reason = $input['reason'] ?? '';

if (!$routeId || !$action) {
    echo json_encode(['success' => false, 'error' => 'Missing parameters']);
    exit;
}

$routeModel = new Route();
$db = getDB();
$audit = new AuditLog();

// Get route
$route = $routeModel->getById($routeId);
if (!$route) {
    echo json_encode(['success' => false, 'error' => 'Route not found']);
    exit;
}

$oldStatus = $route['status'];
$newStatus = null;

try {
    switch ($action) {
        case 'confirm':
            if ($oldStatus !== 'Draft') {
                throw new Exception('Route ต้องอยู่ในสถานะ Draft เท่านั้น');
            }
            // Check route has items
            $items = $routeModel->getItems($routeId);
            if (empty($items)) {
                throw new Exception('Route ต้องมีรายการอย่างน้อย 1 รายการ');
            }
            $newStatus = 'Confirmed';
            break;
            
        case 'dispatch':
            if ($oldStatus !== 'Confirmed') {
                throw new Exception('Route ต้องอยู่ในสถานะ Confirmed เท่านั้น');
            }
            $newStatus = 'Dispatched';
            break;
            
        case 'edit':
            if ($oldStatus !== 'Confirmed') {
                throw new Exception('Route ต้องอยู่ในสถานะ Confirmed เท่านั้น');
            }
            $newStatus = 'Draft';
            break;
            
        case 'cancel':
            if (!in_array($oldStatus, ['Draft', 'Confirmed'])) {
                throw new Exception('ไม่สามารถยกเลิก Route ที่ Dispatch แล้วได้');
            }
            if (empty($reason)) {
                throw new Exception('กรุณาระบุเหตุผลในการยกเลิก');
            }
            $newStatus = 'Cancelled';
            break;
            
        default:
            throw new Exception('Invalid action');
    }
    
    // Update status
    $stmt = $db->prepare("UPDATE routes SET status = ?, updated_at = NOW() WHERE id = ?");
    $stmt->execute([$newStatus, $routeId]);
    
    // Audit log
    $audit->log('status_change', 'ROUTE', $routeId, [
        'old_status' => $oldStatus,
        'new_status' => $newStatus,
        'action' => $action,
        'reason' => $reason ?: null
    ], null);
    
    echo json_encode(['success' => true, 'new_status' => $newStatus]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
