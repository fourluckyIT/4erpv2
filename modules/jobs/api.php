<?php
/**
 * Job API Endpoints
 * ERP v2 - Phase 2
 */

require_once __DIR__ . '/../../config/bootstrap.php';

header('Content-Type: application/json');

$auth = new Auth();
if (!$auth->isAuthenticated()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$db = getDB();
$action = get('action');

switch ($action) {
    case 'get_sites':
        $customerId = (int) get('customer_id');
        if (!$customerId) {
            echo json_encode([]);
            exit;
        }
        
        $stmt = $db->prepare("SELECT id, name FROM sites WHERE customer_id = ? AND is_active = 1 ORDER BY name");
        $stmt->execute([$customerId]);
        echo json_encode($stmt->fetchAll());
        break;
        
    case 'get_customers':
        $stmt = $db->query("SELECT id, code, name FROM customers WHERE is_active = 1 ORDER BY name");
        echo json_encode($stmt->fetchAll());
        break;
        
    default:
        http_response_code(400);
        echo json_encode(['error' => 'Invalid action']);
}
