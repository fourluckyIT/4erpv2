-- ===========================================
-- ERP v2 Database Schema - M11: Compliance Gate
-- ===========================================
-- Following blueprint.md §14:
-- - Site-specific compliance requirements
-- - Certificate tracking for people
-- - Compliance gate enforcement before dispatch
-- ===========================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- -------------------------------------------
-- Table: compliance_requirements
-- Site-specific requirements
-- -------------------------------------------
CREATE TABLE IF NOT EXISTS `compliance_requirements` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `site_id` INT UNSIGNED NOT NULL,
    `requirement_type` ENUM('Certificate', 'Training', 'Equipment', 'Document', 'Other') NOT NULL,
    `name` VARCHAR(200) NOT NULL,
    `description` TEXT NULL,
    `is_mandatory` TINYINT(1) DEFAULT 1,
    `applies_to` ENUM('All', 'People', 'Serials', 'Vehicles') DEFAULT 'All',
    `validity_days` INT NULL COMMENT 'How many days before expiry to warn',
    `is_active` TINYINT(1) DEFAULT 1,
    `created_by` INT UNSIGNED NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_site` (`site_id`),
    INDEX `idx_type` (`requirement_type`),
    INDEX `idx_active` (`is_active`),
    CONSTRAINT `fk_cr_site` FOREIGN KEY (`site_id`) REFERENCES `sites`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Site-specific compliance requirements (blueprint §14)';

-- -------------------------------------------
-- Table: people_certificates
-- Certificates held by people
-- -------------------------------------------
CREATE TABLE IF NOT EXISTS `people_certificates` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `people_id` INT UNSIGNED NOT NULL,
    `certificate_type` VARCHAR(100) NOT NULL,
    `certificate_number` VARCHAR(100) NULL,
    `issuer` VARCHAR(200) NULL,
    `issue_date` DATE NULL,
    `expiry_date` DATE NULL,
    `file_path` VARCHAR(500) NULL,
    `status` ENUM('Valid', 'Expired', 'Revoked', 'Pending') DEFAULT 'Valid',
    `notes` TEXT NULL,
    `verified_by` INT UNSIGNED NULL,
    `verified_at` DATETIME NULL,
    `created_by` INT UNSIGNED NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_people` (`people_id`),
    INDEX `idx_type` (`certificate_type`),
    INDEX `idx_expiry` (`expiry_date`),
    INDEX `idx_status` (`status`),
    CONSTRAINT `fk_pc_people` FOREIGN KEY (`people_id`) REFERENCES `people`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='People certificates for compliance';

-- -------------------------------------------
-- Table: serial_certificates
-- Certificates/calibrations for serials
-- -------------------------------------------
CREATE TABLE IF NOT EXISTS `serial_certificates` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `serial_id` INT UNSIGNED NOT NULL,
    `certificate_type` VARCHAR(100) NOT NULL COMMENT 'e.g. Calibration, Safety, Insurance',
    `certificate_number` VARCHAR(100) NULL,
    `issuer` VARCHAR(200) NULL,
    `issue_date` DATE NULL,
    `expiry_date` DATE NULL,
    `file_path` VARCHAR(500) NULL,
    `status` ENUM('Valid', 'Expired', 'Revoked', 'Pending') DEFAULT 'Valid',
    `notes` TEXT NULL,
    `created_by` INT UNSIGNED NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_serial` (`serial_id`),
    INDEX `idx_type` (`certificate_type`),
    INDEX `idx_expiry` (`expiry_date`),
    CONSTRAINT `fk_sc_serial` FOREIGN KEY (`serial_id`) REFERENCES `serials`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Serial/equipment certificates';

-- -------------------------------------------
-- Table: compliance_checks
-- Records of compliance checks performed
-- -------------------------------------------
CREATE TABLE IF NOT EXISTS `compliance_checks` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `job_id` INT UNSIGNED NOT NULL,
    `plan_id` INT UNSIGNED NULL,
    `route_id` INT UNSIGNED NULL,
    `check_type` ENUM('PreDispatch', 'PreEntry', 'Periodic') NOT NULL DEFAULT 'PreDispatch',
    `checked_at` DATETIME NOT NULL,
    `checked_by` INT UNSIGNED NOT NULL,
    `overall_status` ENUM('Pass', 'Fail', 'PassWithOverride') NOT NULL,
    `total_requirements` INT DEFAULT 0,
    `passed_requirements` INT DEFAULT 0,
    `failed_requirements` INT DEFAULT 0,
    `override_reason` TEXT NULL,
    `override_approved_by` INT UNSIGNED NULL,
    `notes` TEXT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_job` (`job_id`),
    INDEX `idx_plan` (`plan_id`),
    INDEX `idx_status` (`overall_status`),
    CONSTRAINT `fk_cc_job` FOREIGN KEY (`job_id`) REFERENCES `jobs`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Compliance check records';

-- -------------------------------------------
-- Table: compliance_check_items
-- Detail items of each compliance check
-- -------------------------------------------
CREATE TABLE IF NOT EXISTS `compliance_check_items` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `check_id` INT UNSIGNED NOT NULL,
    `requirement_id` INT UNSIGNED NULL,
    `entity_type` ENUM('People', 'Serial', 'Vehicle', 'General') NOT NULL,
    `entity_id` INT UNSIGNED NULL,
    `entity_name` VARCHAR(200) NULL,
    `requirement_name` VARCHAR(200) NOT NULL,
    `status` ENUM('Pass', 'Fail', 'Warning', 'Override', 'NA') NOT NULL,
    `details` TEXT NULL,
    `certificate_id` INT UNSIGNED NULL COMMENT 'Link to certificate if applicable',
    `expiry_date` DATE NULL,
    PRIMARY KEY (`id`),
    INDEX `idx_check` (`check_id`),
    INDEX `idx_status` (`status`),
    CONSTRAINT `fk_cci_check` FOREIGN KEY (`check_id`) REFERENCES `compliance_checks`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- ===========================================
-- System settings table (if not exists)
-- ===========================================
CREATE TABLE IF NOT EXISTS `system_settings` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `setting_key` VARCHAR(100) NOT NULL UNIQUE,
    `setting_value` TEXT NULL,
    `setting_group` VARCHAR(50) DEFAULT 'general',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_group` (`setting_group`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===========================================
-- Seed common certificate types
-- ===========================================
INSERT INTO `system_settings` (`setting_key`, `setting_value`, `setting_group`) VALUES
('certificate_types_people', 'Safety Training,First Aid,Working at Height,Confined Space,Electrical License,Crane Operator,Forklift License', 'compliance'),
('certificate_types_serial', 'Calibration,Safety Inspection,Insurance,Road Tax', 'compliance')
ON DUPLICATE KEY UPDATE `setting_key` = `setting_key`;
