-- ===========================================
-- ERP v2 Database Schema - Phase 2: Job Module
-- ===========================================
-- Following agents.md rules:
-- - Status changes logged in job_status_history (immutable)
-- - Extensions tracked with approval
-- - Lockpoints enforced via application logic
-- ===========================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- -------------------------------------------
-- Table: customers
-- -------------------------------------------
CREATE TABLE IF NOT EXISTS `customers` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `code` VARCHAR(20) NOT NULL UNIQUE,
    `name` VARCHAR(200) NOT NULL,
    `contact_name` VARCHAR(100),
    `phone` VARCHAR(50),
    `email` VARCHAR(100),
    `address` TEXT,
    `tax_id` VARCHAR(20),
    `credit_limit` DECIMAL(15,2) DEFAULT 0,
    `is_active` TINYINT(1) DEFAULT 1,
    `created_by` INT UNSIGNED,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_code` (`code`),
    INDEX `idx_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------
-- Table: sites (Customer locations)
-- -------------------------------------------
CREATE TABLE IF NOT EXISTS `sites` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `customer_id` INT UNSIGNED NOT NULL,
    `name` VARCHAR(200) NOT NULL,
    `address` TEXT,
    `contact_name` VARCHAR(100),
    `phone` VARCHAR(50),
    `latitude` DECIMAL(10,8),
    `longitude` DECIMAL(11,8),
    `is_active` TINYINT(1) DEFAULT 1,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_customer` (`customer_id`),
    CONSTRAINT `fk_site_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------
-- Table: jobs
-- Main job/work order table
-- -------------------------------------------
CREATE TABLE IF NOT EXISTS `jobs` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `job_number` VARCHAR(30) NOT NULL UNIQUE,
    `customer_id` INT UNSIGNED NOT NULL,
    `site_id` INT UNSIGNED,
    
    -- Job details
    `job_type` ENUM('Lumpsum', 'Dayrent', 'Manpower') NOT NULL,
    `scope_short` VARCHAR(500) NOT NULL COMMENT 'Brief description',
    `scope_detail` TEXT,
    `quotation_no` VARCHAR(50) COMMENT 'Reference only, not linked',
    
    -- Dates
    `plan_start_date` DATE NOT NULL,
    `plan_end_date` DATE NOT NULL,
    `actual_start_date` DATE,
    `actual_end_date` DATE,
    
    -- Owners
    `owner_sale_id` INT UNSIGNED NOT NULL,
    `owner_planner_id` INT UNSIGNED,
    
    -- Financial
    `contract_value` DECIMAL(15,2) DEFAULT 0,
    `budget` DECIMAL(15,2) DEFAULT 0,
    
    -- Status (15 states per agents.md)
    `status` ENUM(
        'Draft',
        'Submitted', 
        'Approved',
        'Planned',
        'Dispatched',
        'In Progress',
        'Returned',
        'WH Received',
        'POS Checked',
        'Accounting Ready',
        'Invoiced',
        'Paid',
        'Partial Paid',
        'Closed',
        'Voided'
    ) NOT NULL DEFAULT 'Draft',
    
    -- Approval tracking
    `submitted_at` TIMESTAMP NULL,
    `submitted_by` INT UNSIGNED,
    `approved_at` TIMESTAMP NULL,
    `approved_by` INT UNSIGNED,
    `closed_at` TIMESTAMP NULL,
    `closed_by` INT UNSIGNED,
    `voided_at` TIMESTAMP NULL,
    `voided_by` INT UNSIGNED,
    `void_reason` TEXT,
    
    -- Attachments
    `pdf_attachment` VARCHAR(255),
    
    -- Metadata
    `created_by` INT UNSIGNED NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    PRIMARY KEY (`id`),
    INDEX `idx_job_number` (`job_number`),
    INDEX `idx_customer` (`customer_id`),
    INDEX `idx_status` (`status`),
    INDEX `idx_dates` (`plan_start_date`, `plan_end_date`),
    INDEX `idx_owner_sale` (`owner_sale_id`),
    INDEX `idx_owner_planner` (`owner_planner_id`),
    
    CONSTRAINT `fk_job_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers`(`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_job_site` FOREIGN KEY (`site_id`) REFERENCES `sites`(`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_job_sale` FOREIGN KEY (`owner_sale_id`) REFERENCES `users`(`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_job_planner` FOREIGN KEY (`owner_planner_id`) REFERENCES `users`(`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_job_created` FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------
-- Table: job_status_history
-- IMMUTABLE - tracks all status changes
-- -------------------------------------------
CREATE TABLE IF NOT EXISTS `job_status_history` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `job_id` INT UNSIGNED NOT NULL,
    `old_status` VARCHAR(20),
    `new_status` VARCHAR(20) NOT NULL,
    `changed_by` INT UNSIGNED NOT NULL,
    `reason` TEXT COMMENT 'Required for void/cancel',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_job` (`job_id`),
    INDEX `idx_created` (`created_at`),
    CONSTRAINT `fk_jsh_job` FOREIGN KEY (`job_id`) REFERENCES `jobs`(`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_jsh_user` FOREIGN KEY (`changed_by`) REFERENCES `users`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------
-- Table: job_extensions
-- Extension requests for plan changes
-- -------------------------------------------
CREATE TABLE IF NOT EXISTS `job_extensions` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `job_id` INT UNSIGNED NOT NULL,
    `extension_type` ENUM('extend_days', 'reduce_days', 'change_dates', 'add_equipment', 'remove_equipment', 'add_manpower', 'remove_manpower', 'other') NOT NULL,
    
    -- Date changes
    `original_end_date` DATE,
    `new_end_date` DATE,
    `days_changed` INT DEFAULT 0,
    
    -- Description
    `reason` TEXT NOT NULL,
    `details` TEXT,
    
    -- Status
    `status` ENUM('Pending', 'Approved', 'Rejected') DEFAULT 'Pending',
    
    -- Approval
    `requested_by` INT UNSIGNED NOT NULL,
    `requested_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `approved_by` INT UNSIGNED,
    `approved_at` TIMESTAMP NULL,
    `rejection_reason` TEXT,
    
    PRIMARY KEY (`id`),
    INDEX `idx_job` (`job_id`),
    INDEX `idx_status` (`status`),
    CONSTRAINT `fk_ext_job` FOREIGN KEY (`job_id`) REFERENCES `jobs`(`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_ext_requested` FOREIGN KEY (`requested_by`) REFERENCES `users`(`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_ext_approved` FOREIGN KEY (`approved_by`) REFERENCES `users`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------
-- Table: job_required_certs
-- Required certificates for job (from HRM)
-- -------------------------------------------
CREATE TABLE IF NOT EXISTS `job_required_certs` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `job_id` INT UNSIGNED NOT NULL,
    `cert_name` VARCHAR(100) NOT NULL,
    `is_mandatory` TINYINT(1) DEFAULT 1,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_job` (`job_id`),
    CONSTRAINT `fk_jrc_job` FOREIGN KEY (`job_id`) REFERENCES `jobs`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- ===========================================
-- SEED DATA
-- ===========================================

-- Sample customer
INSERT INTO `customers` (`code`, `name`, `contact_name`, `phone`, `email`, `created_by`) VALUES
('CUST001', 'บริษัท ทดสอบ จำกัด', 'คุณทดสอบ', '02-123-4567', 'test@example.com', 1),
('CUST002', 'บริษัท ตัวอย่าง จำกัด', 'คุณตัวอย่าง', '02-987-6543', 'example@example.com', 1);

-- Sample sites
INSERT INTO `sites` (`customer_id`, `name`, `address`) VALUES
(1, 'สำนักงานใหญ่', 'กรุงเทพฯ'),
(1, 'สาขาเชียงใหม่', 'เชียงใหม่'),
(2, 'โรงงาน', 'สมุทรปราการ');
