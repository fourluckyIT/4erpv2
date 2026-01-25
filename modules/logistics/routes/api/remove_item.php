<?php
/**
 * API: Remove item from route
 */

require_once __DIR__ . '/../../../../config/bootstrap.php';
require_once __DIR__ . '/../../../../core/Route.php';

header('Content-Type: application/json');

$auth = new Auth();
if (!$auth->check()) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$routeId = (int) ($input['route_id'] ?? 0);
$itemId = (int) ($input['item_id'] ?? 0);

if (!$routeId || !$itemId) {
    echo json_encode(['success' => false, 'error' => 'Missing parameters']);
    exit;
}

$routeModel = new Route();
$db = getDB();

// Check route exists and is Draft
$route = $routeModel->getById($routeId);
if (!$route) {
    echo json_encode(['success' => false, 'error' => 'Route not found']);
    exit;
}

if ($route['status'] !== 'Draft') {
    echo json_encode(['success' => false, 'error' => 'Cannot modify non-Draft route']);
    exit;
}

// Remove item
try {
    $stmt = $db->prepare("DELETE FROM route_items WHERE id = ? AND route_id = ?");
    $stmt->execute([$itemId, $routeId]);
    
    if ($stmt->rowCount() > 0) {
        $audit = new AuditLog();
        $audit->log('delete', 'ROUTE_ITEM', $itemId, ['route_id' => $routeId], null);
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Item not found']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
