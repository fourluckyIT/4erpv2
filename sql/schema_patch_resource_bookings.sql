-- ===========================================
-- Patch: Create resource_bookings table
-- Fix for: Table 'resource_bookings' doesn't exist
-- Used by includes/booking_conflicts.php
-- ===========================================

CREATE TABLE IF NOT EXISTS `resource_bookings` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `resource_type` ENUM('Serial', 'Person', 'Vehicle') NOT NULL,
    `resource_id` INT UNSIGNED NOT NULL,
    `start_time` DATETIME NOT NULL,
    `end_time` DATETIME NOT NULL,
    `reference_table` VARCHAR(50) NOT NULL,
    `reference_id` INT UNSIGNED NOT NULL,
    `status` ENUM('Active', 'Cancelled') NOT NULL DEFAULT 'Active',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_resource` (`resource_type`, `resource_id`),
    INDEX `idx_time` (`start_time`, `end_time`),
    INDEX `idx_ref` (`reference_table`, `reference_id`),
    INDEX `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
