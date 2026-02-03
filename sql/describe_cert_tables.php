<?php
require_once __DIR__ . '/../config/database.php';

$pdo = getDB();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

function describeTable(PDO $pdo, string $table): void {
    echo "=== {$table} ===\n";
    try {
        $stmt = $pdo->query("DESCRIBE `{$table}`");
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            echo "- {$r['Field']} {$r['Type']}\n";
        }
    } catch (Exception $e) {
        echo "(missing) " . $e->getMessage() . "\n";
    }
    echo "\n";
}

describeTable($pdo, 'people_certificates');
describeTable($pdo, 'job_required_certs');
describeTable($pdo, 'serial_certificates');
