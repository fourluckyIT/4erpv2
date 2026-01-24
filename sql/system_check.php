<?php
/**
 * System Health Check Script
 * Checks for missing/empty files and configuration issues
 */

echo "=== ERP v2 System Health Check ===\n\n";

$projectRoot = dirname(__DIR__);
$issues = [];

// 1. Check core files
$coreFiles = [
    'core/Auth.php',
    'core/RBAC.php',
    'core/AuditLog.php',
    'core/DocumentNumber.php',
    'core/Job.php',
    'core/StatusMachine.php',
    'core/Plan.php',
    'core/Dispatch.php',
    'core/Route.php',
    'core/EvidencePhoto.php',
    'core/Timesheet.php',
    'core/Reservation.php',
    'core/Approval.php',
    'core/Notification.php',
    'core/SiteOperation.php',
    'core/Costing.php',
    'core/KPI.php',
    'core/Compliance.php',
    'core/Session.php',
];

echo "1. Checking core files...\n";
foreach ($coreFiles as $file) {
    $path = $projectRoot . '/' . $file;
    if (!file_exists($path)) {
        $issues[] = "MISSING: $file";
        echo "   [MISSING] $file\n";
    } elseif (filesize($path) < 50) {
        $issues[] = "EMPTY/CORRUPT: $file (size: " . filesize($path) . " bytes)";
        echo "   [EMPTY] $file (" . filesize($path) . " bytes)\n";
    } else {
        echo "   [OK] $file\n";
    }
}

// 2. Check config files
$configFiles = [
    'config/bootstrap.php',
    'config/constants.php',
    'config/database.php',
];

echo "\n2. Checking config files...\n";
foreach ($configFiles as $file) {
    $path = $projectRoot . '/' . $file;
    if (!file_exists($path)) {
        $issues[] = "MISSING: $file";
        echo "   [MISSING] $file\n";
    } elseif (filesize($path) < 50) {
        $issues[] = "EMPTY/CORRUPT: $file (size: " . filesize($path) . " bytes)";
        echo "   [EMPTY] $file (" . filesize($path) . " bytes)\n";
    } else {
        echo "   [OK] $file\n";
    }
}

// 3. Check includes
$includeFiles = [
    'includes/functions.php',
    'includes/header.php',
    'includes/footer.php',
    'includes/nav.php',
];

echo "\n3. Checking include files...\n";
foreach ($includeFiles as $file) {
    $path = $projectRoot . '/' . $file;
    if (!file_exists($path)) {
        $issues[] = "MISSING: $file";
        echo "   [MISSING] $file\n";
    } elseif (filesize($path) < 50) {
        $issues[] = "EMPTY/CORRUPT: $file (size: " . filesize($path) . " bytes)";
        echo "   [EMPTY] $file (" . filesize($path) . " bytes)\n";
    } else {
        echo "   [OK] $file\n";
    }
}

// 4. Check key module files
$moduleFiles = [
    'modules/auth/login.php',
    'modules/auth/logout.php',
    'modules/jobs/index.php',
    'modules/jobs/create.php',
    'modules/jobs/view.php',
    'modules/jobs/api.php',
    'index.php',
];

echo "\n4. Checking module files...\n";
foreach ($moduleFiles as $file) {
    $path = $projectRoot . '/' . $file;
    if (!file_exists($path)) {
        $issues[] = "MISSING: $file";
        echo "   [MISSING] $file\n";
    } elseif (filesize($path) < 50) {
        $issues[] = "EMPTY/CORRUPT: $file (size: " . filesize($path) . " bytes)";
        echo "   [EMPTY] $file (" . filesize($path) . " bytes)\n";
    } else {
        echo "   [OK] $file\n";
    }
}

// 5. Check directories
$dirs = [
    'assets/css',
    'assets/js',
    'logs',
    'uploads',
];

echo "\n5. Checking directories...\n";
foreach ($dirs as $dir) {
    $path = $projectRoot . '/' . $dir;
    if (!is_dir($path)) {
        $issues[] = "MISSING DIR: $dir";
        echo "   [MISSING] $dir/\n";
    } else {
        echo "   [OK] $dir/\n";
    }
}

// 6. Try to load bootstrap and check constants
echo "\n6. Checking bootstrap and constants...\n";
try {
    require_once $projectRoot . '/config/bootstrap.php';
    echo "   [OK] Bootstrap loads\n";
    
    if (defined('BASE_URL')) {
        echo "   [OK] BASE_URL = " . BASE_URL . "\n";
    } else {
        $issues[] = "BASE_URL not defined";
        echo "   [FAIL] BASE_URL not defined\n";
    }
    
    if (defined('ROLE_ADMIN')) {
        echo "   [OK] Role constants defined\n";
    } else {
        $issues[] = "Role constants not defined";
        echo "   [FAIL] Role constants not defined\n";
    }
    
    // Check classes exist
    $classes = ['Auth', 'RBAC', 'AuditLog', 'DocumentNumber'];
    foreach ($classes as $class) {
        if (class_exists($class)) {
            echo "   [OK] Class $class exists\n";
        } else {
            $issues[] = "Class $class not found";
            echo "   [FAIL] Class $class not found\n";
        }
    }
    
} catch (Exception $e) {
    $issues[] = "Bootstrap error: " . $e->getMessage();
    echo "   [FAIL] " . $e->getMessage() . "\n";
}

// 7. Check database connection
echo "\n7. Checking database...\n";
try {
    $pdo = getDB();
    echo "   [OK] Database connection\n";
    
    // Check key tables
    $tables = ['users', 'roles', 'permissions', 'jobs', 'customers', 'sites'];
    foreach ($tables as $table) {
        $count = $pdo->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();
        echo "   [OK] Table $table ($count rows)\n";
    }
} catch (Exception $e) {
    $issues[] = "Database error: " . $e->getMessage();
    echo "   [FAIL] " . $e->getMessage() . "\n";
}

// Summary
echo "\n=== SUMMARY ===\n";
if (empty($issues)) {
    echo "All checks passed!\n";
} else {
    echo "Found " . count($issues) . " issue(s):\n";
    foreach ($issues as $i => $issue) {
        echo "  " . ($i+1) . ". $issue\n";
    }
}
