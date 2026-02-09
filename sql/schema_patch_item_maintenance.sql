-- ===========================================
-- Patch: Item Maintenance Scheduling
-- ===========================================
-- Adds maintenance fields to items and log table

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- Items maintenance fields
ALTER TABLE `items`
    ADD COLUMN `maintenance_required` TINYINT(1) NOT NULL DEFAULT 0 AFTER `is_serialized`,
    ADD COLUMN `maintenance_interval_days` INT NULL AFTER `maintenance_required`,
    ADD COLUMN `maintenance_last_date` DATE NULL AFTER `maintenance_interval_days`,
    ADD COLUMN `maintenance_next_date` DATE NULL AFTER `maintenance_last_date`,
    ADD COLUMN `maintenance_ack_at` DATETIME NULL AFTER `maintenance_next_date`,
    ADD COLUMN `maintenance_ack_by` INT UNSIGNED NULL AFTER `maintenance_ack_at`,
    ADD COLUMN `maintenance_ack_until` DATE NULL AFTER `maintenance_ack_by`,
    ADD COLUMN `maintenance_scheduled_date` DATE NULL AFTER `maintenance_ack_until`,
    ADD COLUMN `maintenance_scheduled_hours` DECIMAL(5,2) NULL AFTER `maintenance_scheduled_date`,
    ADD COLUMN `maintenance_last_notified` DATE NULL AFTER `maintenance_scheduled_hours`;

-- Maintenance log (append-only)
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
COMMENT='Append-only maintenance logs for items';

SET FOREIGN_KEY_CHECKS = 1;
