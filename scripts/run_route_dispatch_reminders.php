<?php
/**
 * Cron: Route dispatch reminders (WH)
 * - Send in-app reminders at 07:30 and every 2 hours
 * - Stop on dispatch/cancel/job cancel
 */

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../core/RouteReminder.php';

$reminder = new RouteReminder();
$notification = new Notification();
$audit = new AuditLog();
$db = getDB();

$now = new DateTimeImmutable();
$due = $reminder->getDueReminders($now);

if (empty($due)) {
    echo "No due reminders\n";
    exit(0);
}

// Cache WH user IDs
$whUsers = [];
$stmt = $db->prepare("
    SELECT DISTINCT u.id
    FROM users u
    JOIN user_roles ur ON u.id = ur.user_id
    JOIN roles r ON ur.role_id = r.id
    WHERE r.code = 'WH' AND u.is_active = 1
");
$stmt->execute();
$whUsers = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

foreach ($due as $row) {
    // Stop if route/job no longer valid
    if (in_array($row['route_status'], ['Cancelled', 'Dispatched', 'InProgress', 'Returned', 'WHReceived', 'Draft'])) {
        $reminder->stop((int) $row['route_id'], 'Route status no longer eligible', $_SESSION['user_id'] ?? 1);
        continue;
    }
    if (in_array($row['job_status'], ['Voided', 'Cancelled'])) {
        $reminder->stop((int) $row['route_id'], 'Job cancelled/voided', $_SESSION['user_id'] ?? 1);
        continue;
    }

    if (empty($whUsers)) {
        continue;
    }

    $title = "Route {$row['route_number']} พร้อมปล่อยรถ";
    $message = "Job: {$row['job_number']} (กรุณา WH ปล่อยรถ)";
    $url = "/4erpv2/modules/logistics/routes/view.php?id={$row['route_id']}";
    $notification->createBulk(
        $whUsers,
        Notification::TYPE_DISPATCH_ALERT,
        $title,
        $message,
        $url,
        'ROUTE',
        (int) $row['route_id'],
        Notification::PRIORITY_NORMAL
    );

    $nextDue = $reminder->computeNextDue($now, $row['route_date']);
    $reminder->markNotified((int) $row['id'], $nextDue, $now);

    $audit->log(
        'reminder_notify',
        'ROUTE',
        (int) $row['route_id'],
        null,
        ['notified_at' => $now->format('Y-m-d H:i:s'), 'next_due_at' => $nextDue->format('Y-m-d H:i:s')]
    );
}

echo "Processed " . count($due) . " reminders\n";
