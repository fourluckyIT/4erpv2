<?php
/**
 * Run Patch: Item Maintenance (safe for existing DB)
 */

require_once __DIR__ . '/../config/database.php';

$pdo = getDB();

function columnExists(PDO $db, string $table, string $column): bool {
    $stmt = $db->prepare("
        SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
    ");
    $stmt->execute([$table, $column]);
    return ((int) $stmt->fetchColumn()) > 0;
}

function tableExists(PDO $db, string $table): bool {
    $stmt = $db->prepare("
        SELECT COUNT(*) FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
    ");
    $stmt->execute([$table]);
    return ((int) $stmt->fetchColumn()) > 0;
}

echo "=== Running Item Maintenance Patch (safe) ===\n";

$columns = [
    'maintenance_required' => "ALTER TABLE `items` ADD COLUMN `maintenance_required` TINYINT(1) NOT NULL DEFAULT 0 AFTER `is_serialized`",
    'maintenance_interval_days' => "ALTER TABLE `items` ADD COLUMN `maintenance_interval_days` INT NULL AFTER `maintenance_required`",
    'maintenance_last_date' => "ALTER TABLE `items` ADD COLUMN `maintenance_last_date` DATE NULL AFTER `maintenance_interval_days`",
    'maintenance_next_date' => "ALTER TABLE `items` ADD COLUMN `maintenance_next_date` DATE NULL AFTER `maintenance_last_date`",
    'maintenance_ack_at' => "ALTER TABLE `items` ADD COLUMN `maintenance_ack_at` DATETIME NULL AFTER `maintenance_next_date`",
    'maintenance_ack_by' => "ALTER TABLE `items` ADD COLUMN `maintenance_ack_by` INT UNSIGNED NULL AFTER `maintenance_ack_at`",
    'maintenance_ack_until' => "ALTER TABLE `items` ADD COLUMN `maintenance_ack_until` DATE NULL AFTER `maintenance_ack_by`",
    'maintenance_scheduled_date' => "ALTER TABLE `items` ADD COLUMN `maintenance_scheduled_date` DATE NULL AFTER `maintenance_ack_until`",
    'maintenance_scheduled_hours' => "ALTER TABLE `items` ADD COLUMN `maintenance_scheduled_hours` DECIMAL(5,2) NULL AFTER `maintenance_scheduled_date`",
    'maintenance_last_notified' => "ALTER TABLE `items` ADD COLUMN `maintenance_last_notified` DATE NULL AFTER `maintenance_scheduled_hours`",
];

foreach ($columns as $col => $sql) {
    if (columnExists($pdo, 'items', $col)) {
        echo "⚠️  Column exists: items.$col\n";
        continue;
    }
    $pdo->exec($sql);
    echo "✅ Added column: items.$col\n";
}

if (!tableExists($pdo, 'item_maintenance_logs')) {
    $createTableSql = "
        CREATE TABLE IF NOT EXISTS `item_maintenance_logs` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `item_id` INT UNSIGNED NOT NULL,
            `action` ENUM('ACK','SCHEDULE','COMPLETE','SNOOZE','UPDATE_INTERVAL') NOT NULL,
            `due_date` DATE NULL,
            `scheduled_date` DATE NULL,
            `scheduled_hours` DECIMAL(5,2) NULL,
            `completed_date` DATE NULL,
            `notes` TEXT NULL,
            `created_by` INT UNSIGNED NOT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            INDEX `idx_item` (`item_id`),
            INDEX `idx_action` (`action`),
            INDEX `idx_created` (`created_at`),
            CONSTRAINT `fk_iml_item` FOREIGN KEY (`item_id`) REFERENCES `items`(`id`) ON DELETE RESTRICT,
            CONSTRAINT `fk_iml_user` FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        COMMENT='Append-only maintenance logs for items'
    ";
    $pdo->exec($createTableSql);
    echo "✅ Created table: item_maintenance_logs\n";
} else {
    echo "⚠️  Table exists: item_maintenance_logs\n";
}

echo "=== Patch complete ===\n";
