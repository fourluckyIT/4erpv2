-- ===========================================
-- ERP v2 Database Schema - Phase 5 v2: Routes & Evidence
-- ===========================================
-- New tables: routes, route_items, evidence_photos
-- Modifications: plan_assignments, dispatch_notes
-- ===========================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- -------------------------------------------
-- Table: routes
-- 1 Plan → Many Routes (each route = 1 vehicle + 1 trip)
-- -------------------------------------------
CREATE TABLE IF NOT EXISTS `routes` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `route_number` VARCHAR(30) NOT NULL UNIQUE,
    `plan_id` INT UNSIGNED NOT NULL,
    `vehicle_serial_id` INT UNSIGNED COMMENT 'Vehicle from serials table',
    `supplier_id` INT UNSIGNED COMMENT 'Transport supplier (if external)',
    `route_date` DATE NOT NULL,
    `status` ENUM('Draft', 'Confirmed', 'Dispatched', 'InProgress', 'Returned', 'WHReceived', 'Cancelled') NOT NULL DEFAULT 'Draft',
    `driver_name` VARCHAR(100),
    `driver_phone` VARCHAR(50),
    `destination` VARCHAR(255) COMMENT 'Delivery destination',
    `notes` TEXT,
    -- Status timestamps
    `confirmed_at` DATETIME,
    `confirmed_by` INT UNSIGNED,
    `dispatched_at` DATETIME,
    `dispatched_by` INT UNSIGNED,
    `in_progress_at` DATETIME,
    `in_progress_by` INT UNSIGNED,
    `returned_at` DATETIME,
    `returned_by` INT UNSIGNED,
    `wh_received_at` DATETIME,
    `wh_received_by` INT UNSIGNED,
    `cancelled_at` DATETIME,
    `cancelled_by` INT UNSIGNED,
    `cancel_reason` TEXT,
    -- Audit
    `created_by` INT UNSIGNED NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_route_number` (`route_number`),
    INDEX `idx_plan` (`plan_id`),
    INDEX `idx_status` (`status`),
    INDEX `idx_date` (`route_date`),
    CONSTRAINT `fk_route_plan` FOREIGN KEY (`plan_id`) REFERENCES `plans`(`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_route_vehicle` FOREIGN KEY (`vehicle_serial_id`) REFERENCES `serials`(`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_route_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------
-- Table: route_items
-- Which serials/people are assigned to each route
-- -------------------------------------------
CREATE TABLE IF NOT EXISTS `route_items` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `route_id` INT UNSIGNED NOT NULL,
    `serial_id` INT UNSIGNED COMMENT 'Serial being dispatched',
    `people_id` INT UNSIGNED COMMENT 'Person assigned to this route',
    `item_type` ENUM('Device', 'Equipment', 'Vehicle', 'Consumable', 'Manpower') NOT NULL,
    `condition_out` ENUM('Good', 'Fair', 'Damaged') DEFAULT 'Good',
    `condition_in` ENUM('Good', 'Fair', 'Damaged', 'Lost') COMMENT 'Condition when returned',
    `qty_out` INT DEFAULT 1 COMMENT 'For consumables',
    `qty_used` INT COMMENT 'Actual used (consumables)',
    `notes` TEXT,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_route` (`route_id`),
    INDEX `idx_serial` (`serial_id`),
    INDEX `idx_people` (`people_id`),
    INDEX `idx_type` (`item_type`),
    CONSTRAINT `fk_ri_route` FOREIGN KEY (`route_id`) REFERENCES `routes`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_ri_serial` FOREIGN KEY (`serial_id`) REFERENCES `serials`(`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_ri_people` FOREIGN KEY (`people_id`) REFERENCES `people`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------
-- Table: evidence_photos
-- 4 photos per event type per route
-- -------------------------------------------
CREATE TABLE IF NOT EXISTS `evidence_photos` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `route_id` INT UNSIGNED NOT NULL,
    `event_type` ENUM('Dispatch', 'Receive', 'Return', 'POSCheck') NOT NULL,
    `photo_seq` TINYINT UNSIGNED NOT NULL COMMENT '1-4',
    `file_path` VARCHAR(255) NOT NULL,
    `file_size` INT UNSIGNED COMMENT 'Bytes',
    `mime_type` VARCHAR(50) DEFAULT 'image/jpeg',
    `caption` VARCHAR(255),
    `uploaded_by` INT UNSIGNED NOT NULL,
    `uploaded_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_route_event_seq` (`route_id`, `event_type`, `photo_seq`),
    INDEX `idx_route` (`route_id`),
    INDEX `idx_event` (`event_type`),
    CONSTRAINT `fk_photo_route` FOREIGN KEY (`route_id`) REFERENCES `routes`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------
-- Modify plan_assignments: add resource_type for 3-tab filtering
-- -------------------------------------------
ALTER TABLE `plan_assignments` 
ADD COLUMN IF NOT EXISTS `resource_type` ENUM('Manpower', 'Device', 'Equipment', 'Vehicle', 'Consumable') 
    AFTER `assignment_type`;

ALTER TABLE `plan_assignments`
ADD COLUMN IF NOT EXISTS `cert_override_by` INT UNSIGNED AFTER `notes`,
ADD COLUMN IF NOT EXISTS `cert_override_reason` TEXT AFTER `cert_override_by`;

-- -------------------------------------------
-- Modify dispatch_notes: link to route instead of plan
-- -------------------------------------------
ALTER TABLE `dispatch_notes`
ADD COLUMN IF NOT EXISTS `route_id` INT UNSIGNED AFTER `plan_id`;

-- Add FK if not exists (will fail silently if already exists)
-- ALTER TABLE `dispatch_notes` ADD CONSTRAINT `fk_dn_route` FOREIGN KEY (`route_id`) REFERENCES `routes`(`id`);

SET FOREIGN_KEY_CHECKS = 1;

-- ===========================================
-- Add document number setting for ROUTE
-- ===========================================
INSERT INTO `doc_number_settings` (`doc_type`, `prefix`, `current_year`, `next_number`, `padding`, `reset_yearly`) VALUES
('ROUTE', 'RT', 2026, 1, 5, 1)
ON DUPLICATE KEY UPDATE `doc_type` = `doc_type`;
