<?php
/**
 * Database Configuration
 * ERP v2 - Phase 1
 * 
 * For MAMP: Default port is 8889
 */

define('DB_HOST', '127.0.0.1');
define('DB_PORT', '3306');
define('DB_NAME', 'fourlucky_4erpv2');
define('DB_USER', 'fourlucky');
define('DB_PASS', 'strongpassword');
define('DB_CHARSET', 'utf8mb4');

/**
 * Get PDO database connection
 */
function getDB(): PDO {
    static $pdo = null;
    
    if ($pdo === null) {
        $dsn = sprintf(
            "mysql:host=%s;port=%s;dbname=%s;charset=%s",
            DB_HOST,
            DB_PORT,
            DB_NAME,
            DB_CHARSET
        );
        
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];
        
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            error_log("Database connection failed: " . $e->getMessage());
            throw new Exception("Database connection failed");
        }
    }
    
    return $pdo;
}
