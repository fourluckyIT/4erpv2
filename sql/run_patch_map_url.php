<?php
require_once __DIR__ . '/../config/database.php';
$pdo = getDB();
try {
    $pdo->exec("ALTER TABLE sites ADD COLUMN map_url VARCHAR(500) NULL AFTER longitude");
    echo "Added map_url column\n";
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'Duplicate column') !== false) {
        echo "Column already exists\n";
    } else {
        echo "Error: " . $e->getMessage() . "\n";
    }
}
