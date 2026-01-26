-- ===========================================
-- ERP v2 Database Schema - Phase 5: Planning & Dispatch
-- ===========================================
-- Tables: plans, plan_assignments, dispatch_notes, dispatch_items
-- ===========================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- -------------------------------------------
-- Table: plans
-- Planning header - assigns resources to jobs
-- -------------------------------------------
CREATE TABLE IF NOT EXISTS `plans` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `plan_number` VARCHAR(30) NOT NULL UNIQUE,
    `job_id` INT UNSIGNED NOT NULL,
    `plan_date` DATE NOT NULL,
    `status` ENUM('Draft', 'Confirmed', 'Cancelled') NOT NULL DEFAULT 'Draft',
    `notes` TEXT,
    `confirmed_at` DATETIME,
    `confirmed_by` INT UNSIGNED,
    `cancelled_at` DATETIME,
    `cancelled_by` INT UNSIGNED,
    `cancel_reason` TEXT,
    `created_by` INT UNSIGNED NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_plan_number` (`plan_number`),
    INDEX `idx_job` (`job_id`),
    INDEX `idx_status` (`status`),
    CONSTRAINT `fk_plan_job` FOREIGN KEY (`job_id`) REFERENCES `jobs`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------
-- Table: plan_assignments
-- Detail: assigns serials and/or people to a plan
-- -------------------------------------------
CREATE TABLE IF NOT EXISTS `plan_assignments` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `plan_id` INT UNSIGNED NOT NULL,
    `serial_id` INT UNSIGNED COMMENT 'Assigned serial (nullable if people-only)',
    `people_id` INT UNSIGNED COMMENT 'Assigned person (nullable if serial-only)',
    `assignment_type` ENUM('Serial', 'People', 'Both') NOT NULL DEFAULT 'Serial',
    `notes` TEXT,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_plan` (`plan_id`),
    INDEX `idx_serial` (`serial_id`),
    INDEX `idx_people` (`people_id`),
    CONSTRAINT `fk_pa_plan` FOREIGN KEY (`plan_id`) REFERENCES `plans`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_pa_serial` FOREIGN KEY (`serial_id`) REFERENCES `serials`(`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_pa_people` FOREIGN KEY (`people_id`) REFERENCES `people`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------
-- Table: dispatch_notes
-- Dispatch/Delivery header
-- -------------------------------------------
CREATE TABLE IF NOT EXISTS `dispatch_notes` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `do_number` VARCHAR(30) NOT NULL UNIQUE COMMENT 'Delivery Order number',
    `plan_id` INT UNSIGNED NOT NULL,
    `dispatch_date` DATE NOT NULL,
    `status` ENUM('Draft', 'Dispatched', 'Delivered', 'Cancelled') NOT NULL DEFAULT 'Draft',
    `vehicle_info` VARCHAR(200) COMMENT 'Vehicle plate or description',
    `driver_name` VARCHAR(100),
    `driver_phone` VARCHAR(50),
    `notes` TEXT,
    `dispatched_at` DATETIME,
    `dispatched_by` INT UNSIGNED,
    `delivered_at` DATETIME,
    `delivered_by` INT UNSIGNED,
    `cancelled_at` DATETIME,
    `cancelled_by` INT UNSIGNED,
    `cancel_reason` TEXT,
    `created_by` INT UNSIGNED NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_do_number` (`do_number`),
    INDEX `idx_plan` (`plan_id`),
    INDEX `idx_status` (`status`),
    CONSTRAINT `fk_dn_plan` FOREIGN KEY (`plan_id`) REFERENCES `plans`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------
-- Table: dispatch_items
-- Detail: which serials are dispatched
-- -------------------------------------------
CREATE TABLE IF NOT EXISTS `dispatch_items` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `dispatch_id` INT UNSIGNED NOT NULL,
    `serial_id` INT UNSIGNED NOT NULL,
    `condition_out` ENUM('Good', 'Fair', 'Damaged') DEFAULT 'Good',
    `notes` TEXT,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_dispatch` (`dispatch_id`),
    INDEX `idx_serial` (`serial_id`),
    CONSTRAINT `fk_di_dispatch` FOREIGN KEY (`dispatch_id`) REFERENCES `dispatch_notes`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_di_serial` FOREIGN KEY (`serial_id`) REFERENCES `serials`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- ===========================================
-- Add document number settings for PLAN, DO
-- ===========================================
INSERT INTO `doc_number_settings` (`doc_type`, `prefix`, `current_year`, `next_number`, `padding`, `reset_yearly`) VALUES
('PLAN', 'PLN', 2026, 1, 5, 1),
('DO', 'DO', 2026, 1, 5, 1)
ON DUPLICATE KEY UPDATE `doc_type` = `doc_type`;
