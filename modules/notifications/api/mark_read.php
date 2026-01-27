<?php
/**
 * API: Mark a notification as read
 */

ini_set('html_errors', 0);
header('Content-Type: application/json');

try {
    require_once __DIR__ . '/../../../config/bootstrap.php';
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
$id = (int) ($input['id'] ?? 0);

if (!$id) {
    echo json_encode(['success' => false, 'error' => 'Missing id']);
    exit;
}

$notification = new Notification();
$ok = $notification->markRead($id);

echo json_encode(['success' => (bool) $ok]);
