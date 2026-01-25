<?php
require_once __DIR__ . '/../config/database.php';
$db = getDB();

$queries = [
    "ALTER TABLE routes ADD COLUMN received_by_name VARCHAR(100) NULL AFTER wh_received_by",
    "ALTER TABLE routes ADD COLUMN received_at DATETIME NULL AFTER received_by_name", 
    "ALTER TABLE routes ADD COLUMN receive_notes TEXT NULL AFTER received_at",
    "ALTER TABLE routes MODIFY COLUMN status ENUM('Draft','Confirmed','Dispatched','Received','InProgress','Returned','WHReceived','Cancelled') NOT NULL DEFAULT 'Draft'"
];

foreach ($queries as $sql) {
    try {
        $db->exec($sql);
        echo "OK: $sql\n";
    } catch (PDOException $e) {
        echo "SKIP: " . $e->getMessage() . "\n";
    }
}
echo "Done!\n";
