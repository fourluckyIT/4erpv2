<?php
require_once __DIR__ . '/../config/database.php';

$pdo = getDB();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

function countTable(PDO $pdo, string $table): int {
    return (int)$pdo->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
}

echo "jobs=" . countTable($pdo, 'jobs') . "\n";
echo "customers=" . countTable($pdo, 'customers') . "\n";
echo "sites=" . countTable($pdo, 'sites') . "\n";
echo "users=" . countTable($pdo, 'users') . "\n";
echo "doc_number_settings=" . countTable($pdo, 'doc_number_settings') . "\n";

// show last 5 customers and users (for sanity)
$customers = $pdo->query("SELECT id, code, name FROM customers ORDER BY id DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
$users = $pdo->query("SELECT id, username, full_name FROM users ORDER BY id DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);

echo "\nLast customers:\n";
foreach ($customers as $c) {
    echo "- #{$c['id']} {$c['code']} {$c['name']}\n";
}

echo "\nLast users:\n";
foreach ($users as $u) {
    echo "- #{$u['id']} {$u['username']} {$u['full_name']}\n";
}
