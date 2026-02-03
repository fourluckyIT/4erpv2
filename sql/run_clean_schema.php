<?php
/**
 * Run Clean Schema Files (no demo data)
 * Creates a fresh database and loads schema files, excluding seed sections
 * in schema_phase2.sql and schema_phase3.sql.
 *
 * Usage (browser): /4erpv2/sql/run_clean_schema.php?db=TEST_CLEAN
 * Optional: &root_user=root&root_pass=root
 */

require_once __DIR__ . '/../config/database.php';

$targetDb = $_GET['db'] ?? 'TEST_CLEAN';
$rootUser = $_GET['root_user'] ?? 'root';
$rootPass = $_GET['root_pass'] ?? 'root';

if (!preg_match('/^[A-Za-z0-9_]+$/', $targetDb)) {
    http_response_code(400);
    exit("Invalid db name. Use letters, numbers, underscore only.\n");
}

$rootDsn = sprintf(
    'mysql:host=%s;port=%s;charset=%s',
    DB_HOST,
    DB_PORT,
    DB_CHARSET
);

$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
];

try {
    $rootPdo = new PDO($rootDsn, $rootUser, $rootPass, $options);
} catch (PDOException $e) {
    http_response_code(500);
    exit("Root connection failed: {$e->getMessage()}\n");
}

try {
    $rootPdo->exec("CREATE DATABASE IF NOT EXISTS `{$targetDb}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

    $appUser = DB_USER;
    $appPass = DB_PASS;

    $quotedUser = str_replace('`', '``', $appUser);
    $quotedPass = str_replace("'", "''", $appPass);

    $rootPdo->exec("CREATE USER IF NOT EXISTS '{$quotedUser}'@'localhost' IDENTIFIED BY '{$quotedPass}'");
    $rootPdo->exec("CREATE USER IF NOT EXISTS '{$quotedUser}'@'%' IDENTIFIED BY '{$quotedPass}'");
    $rootPdo->exec("GRANT ALL PRIVILEGES ON `{$targetDb}`.* TO '{$quotedUser}'@'localhost'");
    $rootPdo->exec("GRANT ALL PRIVILEGES ON `{$targetDb}`.* TO '{$quotedUser}'@'%'");
} catch (PDOException $e) {
    http_response_code(500);
    exit("DB setup failed: {$e->getMessage()}\n");
}

$appDsn = sprintf(
    'mysql:host=%s;port=%s;dbname=%s;charset=%s',
    DB_HOST,
    DB_PORT,
    $targetDb,
    DB_CHARSET
);

try {
    $pdo = new PDO($appDsn, DB_USER, DB_PASS, $options);
} catch (PDOException $e) {
    http_response_code(500);
    exit("App connection failed: {$e->getMessage()}\n");
}

$schemas = [
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
    'schema_m6_timesheet.sql',
    'schema_m7_reservations.sql',
    'schema_m8_approvals_notifications.sql',
    'schema_m9_site_operations.sql',
    'schema_m10_costing.sql',
    'schema_m11_compliance.sql',
    'schema_patch_item_types.sql',
    'schema_patch_job_extensions.sql',
    'schema_patch_plan_consumables.sql',
    'schema_patch_pr_type.sql',
    'schema_patch_resource_bookings.sql',
    'schema_patch_route_consumables.sql',
    'schema_patch_route_dispatch_reminders.sql',
    'schema_patch_route_permissions.sql',
    'schema_patch_route_receive.sql',
    'schema_patch_sites_map_url.sql',
    'schema_patch_hrm_salary_overtime.sql',
    'fix_confirmed_at_column.sql',
];

function strip_seed_section(string $sql): string {
    $marker = '-- SEED DATA';
    $pos = strpos($sql, $marker);
    if ($pos === false) {
        return $sql;
    }
    return substr($sql, 0, $pos);
}

echo "=== Running Clean ERP v2 Schema Files ===\n";
echo "Target DB: {$targetDb}\n\n";

$errors = [];
foreach ($schemas as $file) {
    $path = __DIR__ . '/' . $file;
    if (!file_exists($path)) {
        echo "SKIP: {$file} (not found)\n";
        continue;
    }

    echo "Running: {$file}... ";

    try {
        $sql = file_get_contents($path);
        if ($file === 'schema_phase2.sql' || $file === 'schema_phase3.sql') {
            $sql = strip_seed_section($sql);
        }
        $pdo->exec($sql);
        echo "OK\n";
    } catch (PDOException $e) {
        $msg = $e->getMessage();
        if (strpos($msg, 'Duplicate entry') !== false || strpos($msg, '1062') !== false) {
            echo "SKIP (data exists)\n";
        } elseif (strpos($msg, 'already exists') !== false || strpos($msg, '1050') !== false) {
            echo "SKIP (table exists)\n";
        } else {
            echo "ERROR\n";
            $errors[] = "{$file}: {$msg}";
        }
    }
}

echo "\n=== Clean schema execution complete ===\n";
if (!empty($errors)) {
    echo "\nErrors encountered:\n";
    foreach ($errors as $err) {
        echo "- {$err}\n";
    }
}
