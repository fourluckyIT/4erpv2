<?php
require_once __DIR__ . '/../config/database.php';
$pdo = getDB();
$rows = $pdo->query('SELECT * FROM doc_number_settings')->fetchAll(PDO::FETCH_ASSOC);
echo "doc_number_settings:\n";
print_r($rows);
