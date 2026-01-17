-- ===========================================
-- Milestone M1: Planning Conflict Prevention
-- ===========================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- -------------------------------------------
-- Table: resource_bookings
-- Centralized table for conflict checking (Double Booking Prevention)
-- -------------------------------------------
CREATE TABLE IF NOT EXISTS `resource_bookings` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `resource_type` ENUM('Serial', 'Person') NOT NULL,
    `resource_id` INT UNSIGNED NOT NULL,
    `start_time` DATETIME NOT NULL,
    `end_time` DATETIME NOT NULL,
    `reference_table` VARCHAR(50) NOT NULL COMMENT 'e.g., plan_assignments',
    `reference_id` INT UNSIGNED NOT NULL COMMENT 'The ID in the reference table',
    `status` ENUM('Active', 'Cancelled') NOT NULL DEFAULT 'Active',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    -- Composite index mainly for conflict range checks
    INDEX `idx_conflict_check` (`resource_type`, `resource_id`, `status`, `start_time`, `end_time`),
    INDEX `idx_ref` (`reference_table`, `reference_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
