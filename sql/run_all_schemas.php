<?php
/**
 * Run All Schema Files
 * Execute this script once to create all database tables
 */

require_once __DIR__ . '/../config/database.php';

$schemas = [
    // Base schemas first
    'schema.sql',
    'schema_phase2.sql',
    'schema_phase3.sql',
    'schema_phase4.sql',
    'schema_phase5.sql',
    'schema_phase5_v2.sql',
    'schema_m2_stock.sql',
    'schema_m3_photos.sql',
    'schema_m4_warehouse.sql',
    'schema_m5_accounting.sql',
    // New module schemas
    'schema_m6_timesheet.sql',
    'schema_m7_reservations.sql', 
    'schema_m8_approvals_notifications.sql',
    'schema_m9_site_operations.sql',
    'schema_m10_costing.sql',
    'schema_m11_compliance.sql',
    // Patches
    'schema_patch_route_dispatch_reminders.sql',
];

echo "=== Running ERP v2 Schema Files ===\n\n";

$pdo = getDB();
$errors = [];

foreach ($schemas as $file) {
    $path = __DIR__ . '/' . $file;
    
    if (!file_exists($path)) {
        echo "⚠️  SKIP: {$file} (not found)\n";
        continue;
    }
    
    echo "📄 Running: {$file}... ";
    
    try {
        $sql = file_get_contents($path);
        $pdo->exec($sql);
        echo "✅ Done\n";
    } catch (PDOException $e) {
        $msg = $e->getMessage();
        // Ignore duplicate key errors (data already exists)
        if (strpos($msg, 'Duplicate entry') !== false || strpos($msg, '1062') !== false) {
            echo "⚠️ Skipped (data exists)\n";
        } elseif (strpos($msg, 'already exists') !== false || strpos($msg, '1050') !== false) {
            echo "⚠️ Skipped (table exists)\n";
        } else {
            echo "❌ Error: {$msg}\n";
            $errors[] = "{$file}: {$msg}";
        }
    }
}

echo "\n=== Schema execution complete ===\n";
if (!empty($errors)) {
    echo "\nErrors encountered:\n";
    foreach ($errors as $err) {
        echo "- {$err}\n";
    }
}
