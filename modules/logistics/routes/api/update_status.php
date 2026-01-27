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
if (!$auth->isAuthenticated()) {
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

// Get route
$route = $routeModel->getById($routeId);
if (!$route) {
    echo json_encode(['success' => false, 'error' => 'Route not found']);
    exit;
}

try {
    switch ($action) {
        case 'confirm':
            $result = $routeModel->confirm($routeId);
            $newStatus = 'Confirmed';
            break;
            
        case 'dispatch':
            $result = $routeModel->dispatch($routeId);
            $newStatus = 'Dispatched';
            break;
            
        case 'edit':
            $result = $routeModel->transitionStatus($routeId, 'Draft', $reason);
            $newStatus = 'Draft';
            break;
            
        case 'cancel':
            $result = $routeModel->cancel($routeId, $reason);
            $newStatus = 'Cancelled';
            break;
            
        default:
            throw new Exception('Invalid action');
    }

    if (!isset($result) || !$result['success']) {
        throw new Exception($result['error'] ?? 'Action failed');
    }

    echo json_encode(['success' => true, 'new_status' => $newStatus]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
