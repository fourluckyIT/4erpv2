<?php
require_once __DIR__ . '/config/database.php';

try {
    $pdo = getDB();
    
    // Just get username and fullname
    $stmt = $pdo->query("SELECT username, full_name FROM users");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "Users found:\n";
    foreach ($users as $u) {
        echo " - " . $u['username'] . " (" . $u['full_name'] . ")\n";
    }

    echo "\nResetting password for ALL users to '123456'...\n";
    $newPass = password_hash('123456', PASSWORD_DEFAULT);
    
    $pdo->exec("UPDATE users SET password_hash = '$newPass'");
    echo "SUCCESS: Passwords reset.\n";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
