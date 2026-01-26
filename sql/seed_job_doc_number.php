<?php
require_once __DIR__ . '/../config/database.php';
$pdo = getDB();

// Check if JOB exists
$stmt = $pdo->prepare("SELECT id FROM doc_number_settings WHERE doc_type = ?");
$stmt->execute(['JOB']);
if ($stmt->fetchColumn()) {
    echo "JOB already exists\n";
} else {
    $pdo->exec("INSERT INTO doc_number_settings (doc_type, prefix, current_year, next_number, padding, reset_yearly) VALUES ('JOB', 'JOB-', 2026, 1, 5, 1)");
    echo "Added JOB to doc_number_settings\n";
}

// Also add INV and PAY if missing
$types = [
    ['INV', 'INV-', 5],
    ['PAY', 'PAY-', 5],
    ['DN', 'DN-', 5],
    ['RN', 'RN-', 5],
];

foreach ($types as $t) {
    $stmt->execute([$t[0]]);
    if (!$stmt->fetchColumn()) {
        $pdo->exec("INSERT INTO doc_number_settings (doc_type, prefix, current_year, next_number, padding, reset_yearly) VALUES ('{$t[0]}', '{$t[1]}', 2026, 1, {$t[2]}, 1)");
        echo "Added {$t[0]}\n";
    }
}

echo "Done\n";
