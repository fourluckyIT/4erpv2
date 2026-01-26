<?php
require_once __DIR__ . '/../config/database.php';
$pdo = getDB();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$stmt = $pdo->query('DESCRIBE `customers`');
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    echo $r['Field'] . " " . $r['Type'] . " null=" . $r['Null'] . " default=" . ($r['Default'] ?? 'NULL') . "\n";
}
