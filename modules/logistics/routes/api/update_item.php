<?php
/**
 * API: Update Route Item (quantity for consumables)
 */

// Disable HTML errors for API
ini_set('html_errors', 0);
header('Content-Type: application/json');

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
if (!$auth->isAuthenticated()) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$routeId = (int) ($input['route_id'] ?? 0);
$itemId = (int) ($input['item_id'] ?? 0);
$quantity = (int) ($input['quantity'] ?? 0);

if (!$routeId || !$itemId) {
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

if ($route['status'] !== 'Draft') {
    echo json_encode(['success' => false, 'error' => 'ไม่สามารถแก้ไข Route ที่ไม่ใช่ Draft']);
    exit;
}

try {
    // Get current item
    $stmt = $db->prepare("SELECT * FROM route_items WHERE id = ? AND route_id = ?");
    $stmt->execute([$itemId, $routeId]);
    $item = $stmt->fetch();
    
    if (!$item) {
        throw new Exception('Item not found');
    }
    
    $oldQty = $item['quantity'];
    
    if ($quantity <= 0) {
        // Remove item if quantity is 0
        $stmt = $db->prepare("DELETE FROM route_items WHERE id = ?");
        $stmt->execute([$itemId]);
        
        $audit->log('delete', 'ROUTE_ITEM', $itemId, [
            'route_id' => $routeId,
            'old_quantity' => $oldQty
        ], null);
    } else {
        // Update quantity
        $stmt = $db->prepare("UPDATE route_items SET quantity = ? WHERE id = ?");
        $stmt->execute([$quantity, $itemId]);
        
        $audit->log('update', 'ROUTE_ITEM', $itemId, [
            'route_id' => $routeId,
            'old_quantity' => $oldQty,
            'new_quantity' => $quantity
        ], null);
    }
    
    echo json_encode(['success' => true]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
