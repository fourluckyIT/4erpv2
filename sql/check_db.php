<?php
require_once __DIR__ . '/../config/database.php';

try {
    $pdo = getDB();
    $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    echo count($tables) . " tables exist\n";
    foreach ($tables as $t) {
        echo "- $t\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
