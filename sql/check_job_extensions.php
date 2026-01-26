<?php
require_once __DIR__ . '/../config/database.php';
$db = getDB();

echo "=== Checking job_extensions table ===\n\n";

try {
    $stmt = $db->query("SHOW TABLES LIKE 'job_extensions'");
    $exists = $stmt->fetch();
    
    if ($exists) {
        echo "Table EXISTS\n\n";
        echo "Columns:\n";
        $stmt = $db->query("DESCRIBE job_extensions");
        $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($columns as $col) {
            echo "  - {$col['Field']} ({$col['Type']})\n";
        }
    } else {
        echo "Table DOES NOT EXIST\n";
        echo "Creating table...\n";
        
        $sql = "CREATE TABLE IF NOT EXISTS job_extensions (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            job_id INT UNSIGNED NOT NULL,
            extension_type ENUM('date_extension', 'scope_change', 'budget_increase', 'resource_change', 'other') NOT NULL,
            reason TEXT NOT NULL,
            requested_changes TEXT NOT NULL,
            new_end_date DATE NULL,
            additional_budget DECIMAL(15,2) DEFAULT 0,
            status ENUM('Pending', 'Approved', 'Rejected') NOT NULL DEFAULT 'Pending',
            requested_by INT UNSIGNED NOT NULL,
            approved_by INT UNSIGNED NULL,
            approved_at DATETIME NULL,
            approval_notes TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            
            FOREIGN KEY (job_id) REFERENCES jobs(id),
            FOREIGN KEY (requested_by) REFERENCES users(id),
            FOREIGN KEY (approved_by) REFERENCES users(id),
            
            INDEX idx_job_extensions_job (job_id),
            INDEX idx_job_extensions_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        
        $db->exec($sql);
        echo "Table created successfully!\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
