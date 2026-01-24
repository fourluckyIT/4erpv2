-- ===========================================
-- ERP v2 - People Salary & Employment Terms
-- ===========================================
-- Support for HR to manage salary, daily rates, OT calculation
-- and employment terms after initial hiring
-- ===========================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- -------------------------------------------
-- Table: people_salary_history
-- Track salary changes over time (append-only)
-- -------------------------------------------
CREATE TABLE IF NOT EXISTS `people_salary_history` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `people_id` INT UNSIGNED NOT NULL,
    `effective_date` DATE NOT NULL COMMENT 'Date this salary becomes effective',
    `salary_type` ENUM('Monthly', 'Daily', 'Hourly') NOT NULL DEFAULT 'Monthly',
    
    -- Salary amounts
    `base_salary` DECIMAL(10,2) DEFAULT 0 COMMENT 'Monthly base salary',
    `daily_rate` DECIMAL(10,2) DEFAULT 0 COMMENT 'Daily rate (for daily workers)',
    `hourly_rate` DECIMAL(10,2) DEFAULT 0 COMMENT 'Hourly rate',
    
    -- Allowances
    `position_allowance` DECIMAL(10,2) DEFAULT 0,
    `transport_allowance` DECIMAL(10,2) DEFAULT 0,
    `meal_allowance` DECIMAL(10,2) DEFAULT 0,
    `housing_allowance` DECIMAL(10,2) DEFAULT 0,
    `other_allowance` DECIMAL(10,2) DEFAULT 0,
    `allowance_notes` TEXT NULL,
    
    -- OT rates
    `ot_rate_multiplier` DECIMAL(4,2) DEFAULT 1.5 COMMENT 'OT multiplier (e.g., 1.5x, 2x)',
    `holiday_rate_multiplier` DECIMAL(4,2) DEFAULT 2.0 COMMENT 'Holiday work multiplier',
    
    -- Payment info
    `payment_frequency` ENUM('Monthly', 'BiWeekly', 'Weekly', 'Daily') DEFAULT 'Monthly',
    `bank_name` VARCHAR(100) NULL,
    `bank_account` VARCHAR(50) NULL,
    `bank_branch` VARCHAR(100) NULL,
    
    -- Working hours
    `standard_hours_per_day` DECIMAL(4,2) DEFAULT 8.00,
    `standard_days_per_week` INT DEFAULT 5,
    `standard_days_per_month` INT DEFAULT 22 COMMENT 'For monthly salary to daily rate conversion',
    
    -- Status
    `is_active` TINYINT(1) DEFAULT 1 COMMENT 'Current active salary record',
    `end_date` DATE NULL COMMENT 'When this salary period ended',
    `change_reason` VARCHAR(255) NULL COMMENT 'Reason for salary change',
    `notes` TEXT NULL,
    
    -- Audit
    `created_by` INT UNSIGNED NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `approved_by` INT UNSIGNED NULL,
    `approved_at` DATETIME NULL,
    
    PRIMARY KEY (`id`),
    INDEX `idx_people` (`people_id`),
    INDEX `idx_effective` (`effective_date`),
    INDEX `idx_active` (`is_active`),
    CONSTRAINT `fk_psh_people` FOREIGN KEY (`people_id`) REFERENCES `people`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='People salary history - append-only for audit trail';

-- -------------------------------------------
-- Table: people_employment_terms
-- Employment contract terms and conditions
-- -------------------------------------------
CREATE TABLE IF NOT EXISTS `people_employment_terms` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `people_id` INT UNSIGNED NOT NULL,
    `effective_date` DATE NOT NULL,
    
    -- Contract info
    `employment_type` ENUM('Permanent', 'Contract', 'Daily', 'Probation', 'Freelance') NOT NULL,
    `contract_start_date` DATE NULL,
    `contract_end_date` DATE NULL,
    `probation_end_date` DATE NULL,
    
    -- Work schedule
    `work_schedule_type` ENUM('Fixed', 'Shift', 'Flexible', 'Project') DEFAULT 'Fixed',
    `work_start_time` TIME NULL COMMENT 'Standard start time',
    `work_end_time` TIME NULL COMMENT 'Standard end time',
    `break_duration_minutes` INT DEFAULT 60,
    
    -- Leave entitlements
    `annual_leave_days` INT DEFAULT 0,
    `sick_leave_days` INT DEFAULT 0,
    `personal_leave_days` INT DEFAULT 0,
    
    -- Social security
    `social_security_number` VARCHAR(50) NULL,
    `social_security_rate_employer` DECIMAL(5,2) DEFAULT 5.00 COMMENT 'Employer contribution %',
    `social_security_rate_employee` DECIMAL(5,2) DEFAULT 5.00 COMMENT 'Employee contribution %',
    `social_security_max_salary` DECIMAL(10,2) DEFAULT 15000.00,
    
    -- Tax
    `tax_id` VARCHAR(50) NULL,
    `withholding_tax_rate` DECIMAL(5,2) DEFAULT 0 COMMENT 'WHT %',
    
    -- Status
    `is_active` TINYINT(1) DEFAULT 1,
    `end_date` DATE NULL,
    `termination_reason` TEXT NULL,
    `notes` TEXT NULL,
    
    -- Audit
    `created_by` INT UNSIGNED NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    PRIMARY KEY (`id`),
    INDEX `idx_people` (`people_id`),
    INDEX `idx_effective` (`effective_date`),
    INDEX `idx_type` (`employment_type`),
    INDEX `idx_active` (`is_active`),
    CONSTRAINT `fk_pet_people` FOREIGN KEY (`people_id`) REFERENCES `people`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Employment terms and contract details';

-- -------------------------------------------
-- Table: people_overtime
-- OT records for calculation
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
    
    -- Calculation
    `base_rate` DECIMAL(10,2) NOT NULL COMMENT 'Hourly base rate',
    `multiplier` DECIMAL(4,2) NOT NULL COMMENT 'OT multiplier applied',
    `ot_amount` DECIMAL(10,2) NOT NULL COMMENT 'Calculated OT pay',
    
    -- Reference
    `job_id` INT UNSIGNED NULL COMMENT 'If OT is for specific job',
    `timesheet_id` INT UNSIGNED NULL COMMENT 'Link to timesheet',
    `notes` TEXT NULL,
    
    -- Approval workflow
    `status` ENUM('Pending', 'Approved', 'Rejected', 'Paid') DEFAULT 'Pending',
    `approved_by` INT UNSIGNED NULL,
    `approved_at` DATETIME NULL,
    `rejection_reason` TEXT NULL,
    
    -- Audit
    `created_by` INT UNSIGNED NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    PRIMARY KEY (`id`),
    INDEX `idx_people` (`people_id`),
    INDEX `idx_date` (`work_date`),
    INDEX `idx_status` (`status`),
    INDEX `idx_job` (`job_id`),
    CONSTRAINT `fk_pot_people` FOREIGN KEY (`people_id`) REFERENCES `people`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_pot_job` FOREIGN KEY (`job_id`) REFERENCES `jobs`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Overtime records for payroll calculation';

-- -------------------------------------------
-- Table: people_payroll
-- Monthly payroll calculation results
-- -------------------------------------------
CREATE TABLE IF NOT EXISTS `people_payroll` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `people_id` INT UNSIGNED NOT NULL,
    `payroll_period_start` DATE NOT NULL,
    `payroll_period_end` DATE NOT NULL,
    `payment_date` DATE NULL,
    
    -- Income
    `base_salary` DECIMAL(10,2) DEFAULT 0,
    `allowances_total` DECIMAL(10,2) DEFAULT 0,
    `ot_amount` DECIMAL(10,2) DEFAULT 0,
    `bonus` DECIMAL(10,2) DEFAULT 0,
    `other_income` DECIMAL(10,2) DEFAULT 0,
    `gross_income` DECIMAL(10,2) NOT NULL,
    
    -- Deductions
    `social_security_employee` DECIMAL(10,2) DEFAULT 0,
    `withholding_tax` DECIMAL(10,2) DEFAULT 0,
    `advance_deduction` DECIMAL(10,2) DEFAULT 0,
    `other_deduction` DECIMAL(10,2) DEFAULT 0,
    `total_deduction` DECIMAL(10,2) DEFAULT 0,
    
    -- Net
    `net_salary` DECIMAL(10,2) NOT NULL,
    
    -- Employer costs
    `social_security_employer` DECIMAL(10,2) DEFAULT 0,
    `provident_fund_employer` DECIMAL(10,2) DEFAULT 0,
    `total_employer_cost` DECIMAL(10,2) DEFAULT 0,
    
    -- Working days
    `working_days` INT DEFAULT 0,
    `absent_days` INT DEFAULT 0,
    `leave_days` INT DEFAULT 0,
    `ot_hours` DECIMAL(5,2) DEFAULT 0,
    
    -- Status
    `status` ENUM('Draft', 'Calculated', 'Approved', 'Paid') DEFAULT 'Draft',
    `approved_by` INT UNSIGNED NULL,
    `approved_at` DATETIME NULL,
    `paid_at` DATETIME NULL,
    `payment_method` ENUM('Bank', 'Cash', 'Cheque') NULL,
    `payment_reference` VARCHAR(100) NULL,
    
    `notes` TEXT NULL,
    
    -- Audit
    `created_by` INT UNSIGNED NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    PRIMARY KEY (`id`),
    INDEX `idx_people` (`people_id`),
    INDEX `idx_period` (`payroll_period_start`, `payroll_period_end`),
    INDEX `idx_status` (`status`),
    UNIQUE KEY `uk_people_period` (`people_id`, `payroll_period_start`, `payroll_period_end`),
    CONSTRAINT `fk_pp_people` FOREIGN KEY (`people_id`) REFERENCES `people`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Monthly payroll calculation and payment records';

SET FOREIGN_KEY_CHECKS = 1;

-- ===========================================
-- Sample data / helpers
-- ===========================================

-- Trigger to auto-deactivate previous salary record when new one is added
DELIMITER $$
CREATE TRIGGER `trg_salary_history_deactivate` 
BEFORE INSERT ON `people_salary_history`
FOR EACH ROW
BEGIN
    IF NEW.is_active = 1 THEN
        UPDATE people_salary_history 
        SET is_active = 0, end_date = NEW.effective_date
        WHERE people_id = NEW.people_id AND is_active = 1;
    END IF;
END$$
DELIMITER ;

-- Trigger to auto-deactivate previous employment terms when new one is added
DELIMITER $$
CREATE TRIGGER `trg_employment_terms_deactivate` 
BEFORE INSERT ON `people_employment_terms`
FOR EACH ROW
BEGIN
    IF NEW.is_active = 1 THEN
        UPDATE people_employment_terms 
        SET is_active = 0, end_date = NEW.effective_date
        WHERE people_id = NEW.people_id AND is_active = 1;
    END IF;
END$$
DELIMITER ;
