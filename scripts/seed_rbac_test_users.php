<?php
/**
 * RBAC Test User Seeder
 * 
 * Creates deterministic test users for Playwright RBAC tests.
 * IDEMPOTENT: Safe to run multiple times.
 * 
 * Usage: php scripts/seed_rbac_test_users.php
 */

require_once __DIR__ . '/../config/bootstrap.php';

$db = getDB();

// Test password for all users (bcrypt hash of "Test1234!")
$passwordHash = password_hash('Test1234!', PASSWORD_BCRYPT);

// Test users configuration: username => [full_name, email, role_code]
$testUsers = [
    'admin'     => ['System Administrator', 'admin@test.local', 'ADM'],
    'warehouse' => ['Warehouse Manager', 'wh@test.local', 'WH'],
    'planner'   => ['Job Planner', 'pln@test.local', 'PLN'],
    'purchaser' => ['Procurement Officer', 'pur@test.local', 'PUR'],
    'accountant'=> ['Accountant', 'acc@test.local', 'ACC'],
    'manager'   => ['Department Manager', 'mgr@test.local', 'MGR'],
];

echo "=== RBAC Test User Seeder ===\n\n";

// Get role map
$roles = $db->query("SELECT id, code FROM roles")->fetchAll(PDO::FETCH_KEY_PAIR);
echo "Roles available: " . implode(', ', array_values($roles)) . "\n\n";

$created = 0;
$updated = 0;

foreach ($testUsers as $username => $config) {
    [$fullName, $email, $roleCode] = $config;
    
    // Find role ID
    $roleId = array_search($roleCode, $roles);
    if ($roleId === false) {
        echo "ERROR: Role {$roleCode} not found!\n";
        continue;
    }
    
    // Check if user exists
    $stmt = $db->prepare("SELECT id FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $userId = $stmt->fetchColumn();
    
    if ($userId) {
        // Update existing user
        $stmt = $db->prepare("
            UPDATE users 
            SET email = ?, full_name = ?, password_hash = ?, is_active = 1
            WHERE id = ?
        ");
        $stmt->execute([$email, $fullName, $passwordHash, $userId]);
        echo "UPDATED: {$username} (ID: {$userId})\n";
        $updated++;
    } else {
        // Create new user
        $stmt = $db->prepare("
            INSERT INTO users (username, email, password_hash, full_name, is_active, created_at)
            VALUES (?, ?, ?, ?, 1, NOW())
        ");
        $stmt->execute([$username, $email, $passwordHash, $fullName]);
        $userId = $db->lastInsertId();
        echo "CREATED: {$username} (ID: {$userId})\n";
        $created++;
    }
    
    // Ensure user has the correct role
    $stmt = $db->prepare("SELECT 1 FROM user_roles WHERE user_id = ? AND role_id = ?");
    $stmt->execute([$userId, $roleId]);
    if (!$stmt->fetchColumn()) {
        // Remove old roles first (clean assignment)
        $db->prepare("DELETE FROM user_roles WHERE user_id = ?")->execute([$userId]);
        
        // Assign new role
        $stmt = $db->prepare("
            INSERT INTO user_roles (user_id, role_id, assigned_by, assigned_at)
            VALUES (?, ?, 1, NOW())
        ");
        $stmt->execute([$userId, $roleId]);
        echo "  -> Assigned role: {$roleCode}\n";
    } else {
        echo "  -> Role {$roleCode} already assigned\n";
    }
}

echo "\n=== Summary ===\n";
echo "Created: {$created}\n";
echo "Updated: {$updated}\n";
echo "Total users: " . count($testUsers) . "\n";
echo "\nTest credentials:\n";
echo "  Password for all: Test1234!\n";
echo "  Users: admin, warehouse, planner, purchaser, accountant, manager\n";
echo "\nDone!\n";
