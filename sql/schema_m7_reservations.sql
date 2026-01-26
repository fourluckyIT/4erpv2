-- ===========================================
-- ERP v2 Database Schema - M7: Reservation System
-- ===========================================
-- Following blueprint.md §9:
-- - Reservation when plan confirms
-- - Available = OnHand - Reserved
-- - Serial reservation: 1 serial per 1 allocation only
-- - Concurrent allocation prevention (transactional locking)
-- ===========================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- -------------------------------------------
-- Table: reservations
-- Tracks reserved quantities/serials for plans/routes
-- -------------------------------------------
CREATE TABLE IF NOT EXISTS `reservations` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `reservation_type` ENUM('Serial', 'Qty') NOT NULL COMMENT 'Serial=specific serial, Qty=quantity of item',
    `item_id` INT UNSIGNED NOT NULL,
    `serial_id` INT UNSIGNED NULL COMMENT 'For Serial type only',
    `qty` DECIMAL(12,2) NOT NULL DEFAULT 1 COMMENT 'For Qty type or always 1 for Serial',
    `location` ENUM('WH', 'SITE', 'IN_TRANSIT') NOT NULL DEFAULT 'WH',
    -- Source document
    `source_type` ENUM('Plan', 'Route', 'Package') NOT NULL,
    `source_id` INT UNSIGNED NOT NULL,
    `job_id` INT UNSIGNED NOT NULL,
    -- Reservation lifecycle
    `status` ENUM('Reserved', 'Allocated', 'Released', 'Cancelled') NOT NULL DEFAULT 'Reserved',
    `reserved_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `reserved_by` INT UNSIGNED NOT NULL,
    `allocated_at` DATETIME NULL COMMENT 'When actually picked/dispatched',
    `allocated_by` INT UNSIGNED NULL,
    `released_at` DATETIME NULL COMMENT 'When returned or cancelled',
    `released_by` INT UNSIGNED NULL,
    `release_reason` VARCHAR(255) NULL,
    -- Audit
    `notes` TEXT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_item` (`item_id`),
    INDEX `idx_serial` (`serial_id`),
    INDEX `idx_source` (`source_type`, `source_id`),
    INDEX `idx_job` (`job_id`),
    INDEX `idx_status` (`status`),
    INDEX `idx_location` (`location`),
    -- Ensure serial can only be reserved once when active
    UNIQUE KEY `uk_serial_active` (`serial_id`, `status`),
    CONSTRAINT `fk_res_item` FOREIGN KEY (`item_id`) REFERENCES `items`(`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_res_serial` FOREIGN KEY (`serial_id`) REFERENCES `serials`(`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_res_job` FOREIGN KEY (`job_id`) REFERENCES `jobs`(`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_res_user` FOREIGN KEY (`reserved_by`) REFERENCES `users`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Reservation system for serial/qty allocation (blueprint §9)';

-- -------------------------------------------
-- Table: item_stock_levels
-- Materialized view of current stock levels per item/location
-- Updated by triggers or recalculated from stock_movements
-- -------------------------------------------
CREATE TABLE IF NOT EXISTS `item_stock_levels` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `item_id` INT UNSIGNED NOT NULL,
    `location` ENUM('WH', 'SITE', 'IN_TRANSIT') NOT NULL DEFAULT 'WH',
    `on_hand` DECIMAL(12,2) NOT NULL DEFAULT 0,
    `reserved` DECIMAL(12,2) NOT NULL DEFAULT 0,
    `available` DECIMAL(12,2) GENERATED ALWAYS AS (`on_hand` - `reserved`) STORED,
    `last_movement_id` INT UNSIGNED NULL COMMENT 'Last stock_movement that updated this',
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_item_location` (`item_id`, `location`),
    INDEX `idx_item` (`item_id`),
    INDEX `idx_available` (`available`),
    CONSTRAINT `fk_isl_item` FOREIGN KEY (`item_id`) REFERENCES `items`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Current stock levels per item/location';

-- -------------------------------------------
-- Table: reservation_log
-- Append-only log of all reservation changes
-- -------------------------------------------
CREATE TABLE IF NOT EXISTS `reservation_log` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `reservation_id` INT UNSIGNED NOT NULL,
    `action` ENUM('create', 'allocate', 'release', 'cancel') NOT NULL,
    `old_status` VARCHAR(20) NULL,
    `new_status` VARCHAR(20) NOT NULL,
    `qty` DECIMAL(12,2) NULL,
    `reason` TEXT NULL,
    `performed_by` INT UNSIGNED NOT NULL,
    `performed_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_reservation` (`reservation_id`),
    INDEX `idx_performed_at` (`performed_at`),
    CONSTRAINT `fk_rl_reservation` FOREIGN KEY (`reservation_id`) REFERENCES `reservations`(`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_rl_user` FOREIGN KEY (`performed_by`) REFERENCES `users`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Append-only reservation history (agents.md §1.4)';

SET FOREIGN_KEY_CHECKS = 1;

-- ===========================================
-- Initialize stock levels from existing data
-- ===========================================
-- This would be run once to populate from stock_movements
-- In production, this should be a stored procedure

-- INSERT INTO item_stock_levels (item_id, location, on_hand, reserved)
-- SELECT 
--     sm.item_id,
--     COALESCE(sm.to_location, 'WH') as location,
--     SUM(sm.qty) as on_hand,
--     0 as reserved
-- FROM stock_movements sm
-- GROUP BY sm.item_id, COALESCE(sm.to_location, 'WH')
-- ON DUPLICATE KEY UPDATE on_hand = VALUES(on_hand);
