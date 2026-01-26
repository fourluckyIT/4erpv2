-- ===========================================
-- ERP v2 Database Schema Patch - HRM Salary & Overtime
-- ===========================================
-- Creates missing tables for HR module:
-- - people_salary_history (salary records per person)
-- - people_overtime (OT requests and tracking)
-- ===========================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- -------------------------------------------
-- Table: people_salary_history
-- Salary/wage history per person
-- -------------------------------------------
CREATE TABLE IF NOT EXISTS `people_salary_history` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `people_id` INT UNSIGNED NOT NULL,
    `effective_date` DATE NOT NULL COMMENT 'When this salary takes effect',
    `salary_type` ENUM('Monthly', 'Daily', 'Hourly') NOT NULL DEFAULT 'Daily',
    
    -- Base rates
    `base_salary` DECIMAL(12,2) DEFAULT 0 COMMENT 'Monthly salary',
    `daily_rate` DECIMAL(10,2) DEFAULT 0 COMMENT 'Daily rate',
    `hourly_rate` DECIMAL(10,2) DEFAULT 0 COMMENT 'Hourly rate',
    
    -- Allowances
    `position_allowance` DECIMAL(10,2) DEFAULT 0,
    `transport_allowance` DECIMAL(10,2) DEFAULT 0,
    `meal_allowance` DECIMAL(10,2) DEFAULT 0,
    `housing_allowance` DECIMAL(10,2) DEFAULT 0,
    `other_allowance` DECIMAL(10,2) DEFAULT 0,
    `allowance_notes` VARCHAR(255) NULL,
    
    -- OT rates
    `ot_rate_multiplier` DECIMAL(4,2) DEFAULT 1.50 COMMENT 'Normal OT multiplier',
    `holiday_rate_multiplier` DECIMAL(4,2) DEFAULT 2.00 COMMENT 'Holiday OT multiplier',
    
    -- Bank info
    `bank_name` VARCHAR(100) NULL,
    `bank_account` VARCHAR(50) NULL,
    `bank_branch` VARCHAR(100) NULL,
    
    -- Work standards
    `standard_hours_per_day` DECIMAL(4,2) DEFAULT 8.00,
    `standard_days_per_month` INT DEFAULT 22,
    
    -- Meta
    `change_reason` VARCHAR(255) NULL COMMENT 'Reason for salary change',
    `notes` TEXT NULL,
    `is_active` TINYINT(1) DEFAULT 1 COMMENT 'Only one active record per person',
    
    -- Audit
    `created_by` INT UNSIGNED NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    PRIMARY KEY (`id`),
    INDEX `idx_people` (`people_id`),
    INDEX `idx_effective` (`effective_date`),
    INDEX `idx_active` (`is_active`),
    CONSTRAINT `fk_psh_people` FOREIGN KEY (`people_id`) REFERENCES `people`(`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_psh_user` FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Salary history per person (agents.md compliant - append-only style)';

-- -------------------------------------------
-- Table: people_overtime
-- OT requests and approvals
-- -------------------------------------------
CREATE TABLE IF NOT EXISTS `people_overtime` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `people_id` INT UNSIGNED NOT NULL,
    `work_date` DATE NOT NULL,
    `ot_type` ENUM('Weekday', 'Weekend', 'Holiday') NOT NULL DEFAULT 'Weekday',
    
    -- Time tracking
    `start_time` TIME NOT NULL,
    `end_time` TIME NOT NULL,
    `break_minutes` INT DEFAULT 0,
    `total_hours` DECIMAL(5,2) NOT NULL,
    
    -- Rate calculation
    `base_rate` DECIMAL(10,2) NOT NULL COMMENT 'Hourly base rate at time of OT',
    `multiplier` DECIMAL(4,2) NOT NULL COMMENT 'OT multiplier used',
    `ot_amount` DECIMAL(12,2) NOT NULL COMMENT 'Calculated OT amount',
    
    -- Link to job (optional)
    `job_id` INT UNSIGNED NULL,
    
    -- Status workflow
    `status` ENUM('Pending', 'Approved', 'Rejected', 'Paid') NOT NULL DEFAULT 'Pending',
    `notes` TEXT NULL,
    
    -- Approval tracking
    `approved_by` INT UNSIGNED NULL,
    `approved_at` DATETIME NULL,
    `rejection_reason` TEXT NULL,
    
    -- Payment tracking
    `paid_at` DATETIME NULL,
    `payment_ref` VARCHAR(100) NULL,
    
    -- Audit
    `created_by` INT UNSIGNED NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    PRIMARY KEY (`id`),
    INDEX `idx_people` (`people_id`),
    INDEX `idx_date` (`work_date`),
    INDEX `idx_status` (`status`),
    INDEX `idx_job` (`job_id`),
    CONSTRAINT `fk_pot_people` FOREIGN KEY (`people_id`) REFERENCES `people`(`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_pot_job` FOREIGN KEY (`job_id`) REFERENCES `jobs`(`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_pot_approved` FOREIGN KEY (`approved_by`) REFERENCES `users`(`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_pot_created` FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='OT requests and tracking per person';

SET FOREIGN_KEY_CHECKS = 1;

-- ===========================================
-- Permissions for Salary & Overtime
-- ===========================================
INSERT INTO `permissions` (`code`, `name`, `entity_type`, `action`, `description`) VALUES
('SAL_VIEW', 'View Salary', 'SALARY', 'view', 'View salary details'),
('SAL_EDIT', 'Edit Salary', 'SALARY', 'edit', 'Add/Edit salary records'),
('OT_VIEW', 'View Overtime', 'OVERTIME', 'view', 'View OT records'),
('OT_CREATE', 'Create Overtime', 'OVERTIME', 'create', 'Submit OT requests'),
('OT_APPROVE', 'Approve Overtime', 'OVERTIME', 'approve', 'Approve/Reject OT requests')
ON DUPLICATE KEY UPDATE `code` = `code`;

-- Grant permissions to relevant roles
INSERT INTO `role_permissions` (`role_id`, `permission_id`)
SELECT r.id, p.id FROM roles r, permissions p 
WHERE r.code IN ('ADM', 'MGR', 'HR') AND p.entity_type IN ('SALARY', 'OVERTIME')
ON DUPLICATE KEY UPDATE `role_id` = `role_id`;
