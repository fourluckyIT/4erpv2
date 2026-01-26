<?php
require_once __DIR__ . '/../config/database.php';
$pdo = getDB();
$stmt = $pdo->query('DESCRIBE sites');
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    echo $r['Field'] . ' ' . $r['Type'] . ' null=' . $r['Null'] . "\n";
}
