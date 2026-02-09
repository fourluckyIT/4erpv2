<?php
/**
 * Backfill Serial numbers for Device items (1:1 using item code)
 */

require_once __DIR__ . '/../config/bootstrap.php';

$db = getDB();
$audit = new AuditLog();

function resolveUserId(PDO $db): int {
    $sessionUserId = (int) ($_SESSION['user_id'] ?? 0);
    if ($sessionUserId > 0) {
        return $sessionUserId;
    }
    $stmt = $db->prepare("
        SELECT u.id
        FROM users u
        JOIN user_roles ur ON ur.user_id = u.id
        JOIN roles r ON r.id = ur.role_id
        WHERE r.code = ?
        ORDER BY u.id ASC
        LIMIT 1
    ");
    $stmt->execute([ROLE_ADMIN]);
    $id = (int) ($stmt->fetchColumn() ?: 0);
    if ($id > 0) {
        return $id;
    }
    $id = (int) ($db->query("SELECT id FROM users ORDER BY id ASC LIMIT 1")->fetchColumn() ?: 0);
    return $id > 0 ? $id : 1;
}

$userId = resolveUserId($db);

$items = $db->query("
    SELECT i.id, i.code, i.name, i.is_serialized
    FROM items i
    WHERE i.item_type = 'Device'
      AND i.is_active = 1
      AND NOT EXISTS (SELECT 1 FROM serials s WHERE s.item_id = i.id)
    ORDER BY i.id
")->fetchAll(PDO::FETCH_ASSOC);

echo "Found devices without serials: " . count($items) . PHP_EOL;

foreach ($items as $row) {
    $db->beginTransaction();
    try {
        if ((int) $row['is_serialized'] !== 1) {
            $db->prepare("UPDATE items SET is_serialized = 1 WHERE id = ?")->execute([(int) $row['id']]);
            $audit->log('update', 'ITEM', (int) $row['id'], ['is_serialized' => (int) $row['is_serialized']], ['is_serialized' => 1], 'Backfill serial for Device');
        }

        $stmt = $db->prepare("
            INSERT INTO serials (item_id, serial_number, status, location, created_by)
            VALUES (?, ?, 'Available', 'WH', ?)
        ");
        $stmt->execute([(int) $row['id'], $row['code'], $userId]);
        $serialId = (int) $db->lastInsertId();
        $audit->log('create', 'SERIAL', $serialId, null, ['serial_number' => $row['code'], 'item_id' => (int) $row['id']]);

        $db->commit();
        echo "OK: {$row['code']} -> serial {$row['code']}" . PHP_EOL;
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        echo "FAIL: {$row['code']} : " . $e->getMessage() . PHP_EOL;
    }
}

echo "Done" . PHP_EOL;
