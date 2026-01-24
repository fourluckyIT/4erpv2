<?php
require_once __DIR__ . '/../config/database.php';
$pdo = getDB();

$tables = ['items', 'serials', 'people'];
foreach ($tables as $t) {
    try {
        $count = (int)$pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
        echo "$t = $count\n";
    } catch (Exception $e) {
        echo "$t = ERROR: " . $e->getMessage() . "\n";
    }
}

// Check items table structure
echo "\n=== items table structure ===\n";
try {
    $stmt = $pdo->query("DESCRIBE items");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        echo "- {$r['Field']} {$r['Type']}\n";
    }
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
