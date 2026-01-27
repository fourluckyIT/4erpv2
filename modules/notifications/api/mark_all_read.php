<?php
/**
 * API: Mark all notifications as read
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

$notification = new Notification();
$count = $notification->markAllRead();

echo json_encode(['success' => true, 'count' => $count]);
