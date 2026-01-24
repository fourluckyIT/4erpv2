<?php
require_once __DIR__ . '/../config/database.php';
$pdo = getDB();
$sql = file_get_contents(__DIR__ . '/schema_m11_compliance.sql');
$pdo->exec($sql);
echo "Done\n";
