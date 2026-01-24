<?php
/**
 * Test Admin Pages for Runtime Errors
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../config/bootstrap.php';

// Mock session for testing
$_SESSION['logged_in'] = true;
$_SESSION['user_id'] = 1;
$_SESSION['username'] = 'admin';
$_SESSION['full_name'] = 'Test Admin';

$pages = [
    'modules/admin/index.php',
    'modules/admin/users.php',
    'modules/admin/roles.php',
    'modules/admin/permissions.php',
    'modules/admin/audit_logs.php',
    'modules/admin/doc_numbers.php',
    'modules/admin/line_bindings.php',
];

$projectRoot = dirname(__DIR__);

foreach ($pages as $page) {
    $fullPath = $projectRoot . '/' . $page;
    echo "\n=== Testing: $page ===\n";
    
    if (!file_exists($fullPath)) {
        echo "  [MISSING] File does not exist\n";
        continue;
    }
    
    // Check for common issues by parsing the file
    $content = file_get_contents($fullPath);
    
    // Check for undefined methods/functions
    $issues = [];
    
    // Check for RBAC method calls
    if (preg_match_all('/\$rbac->(\w+)\(/', $content, $matches)) {
        $rbacMethods = ['can', 'hasRole', 'getUserRoleCodes', 'getAllPermissions', 'requireOrRedirect', 
                        'assignRole', 'removeRole', 'grantPermission', 'revokePermission',
                        'getAllRoles', 'getAllUsers', 'getUserRolesById', 'getRolePermissions',
                        'getAllPermissionsFromDB', 'assignCustomPermission', 'removeCustomPermission',
                        'getUserCustomPermissions', 'assignRolePermission', 'removeRolePermission'];
        foreach ($matches[1] as $method) {
            if (!in_array($method, $rbacMethods)) {
                $issues[] = "Unknown RBAC method: $method";
            }
        }
    }
    
    // Check for Auth method calls
    if (preg_match_all('/\$auth->(\w+)\(/', $content, $matches)) {
        $authMethods = ['isAuthenticated', 'requireAuth', 'getCurrentUser', 'getCurrentRoles', 
                        'hasRole', 'isAdmin', 'login', 'logout', 'register', 'updatePassword',
                        'requireRole', 'hashPassword', 'getCurrentUserId', 'verifyPassword'];
        foreach ($matches[1] as $method) {
            if (!in_array($method, $authMethods)) {
                $issues[] = "Unknown Auth method: $method";
            }
        }
    }
    
    if (empty($issues)) {
        echo "  [OK] No obvious issues found\n";
    } else {
        foreach ($issues as $issue) {
            echo "  [WARN] $issue\n";
        }
    }
}

echo "\n=== Done ===\n";
