<?php
/**
 * API: List notifications for current user
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

$limit = (int) ($_GET['limit'] ?? 10);
$limit = max(1, min(50, $limit));

$notification = new Notification();
$userId = $_SESSION['user_id'];

$items = $notification->getRecent($userId, $limit);
$unreadCount = $notification->getUnreadCount($userId);

echo json_encode([
    'success' => true,
    'unread_count' => $unreadCount,
    'items' => $items
]);
