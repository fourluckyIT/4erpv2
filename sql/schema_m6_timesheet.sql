-- ===========================================
-- ERP v2 Database Schema - M6: Timesheet Module
-- ===========================================
-- Following agents.md & blueprint.md §12:
-- - Timesheet per job/day with entries per person
-- - Auto-populate from plan_assignments (manpower)
-- - Status workflow: DRAFT → CONFIRMED → HR_QUEUE
-- - Immutable after confirm (corrections only)
-- ===========================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- -------------------------------------------
-- Table: timesheets (header - one per job/site/day)
-- -------------------------------------------
CREATE TABLE IF NOT EXISTS `timesheets` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `ts_number` VARCHAR(30) NOT NULL UNIQUE COMMENT 'TS-YYYYMMDD-NNN format',
    `job_id` INT UNSIGNED NOT NULL,
    `site_id` INT UNSIGNED NULL,
    `work_date` DATE NOT NULL,
    `status` ENUM(
        'Draft',
        'Confirmed',
        'Submitted',
        'PayrollReady',
        'Returned',
        'Voided'
    ) NOT NULL DEFAULT 'Draft',
    `total_workers` INT DEFAULT 0,
    `total_hours` DECIMAL(8,2) DEFAULT 0,
    `total_ot_hours` DECIMAL(8,2) DEFAULT 0,
    `notes` TEXT,
    `supervisor_notes` TEXT,
    `hr_notes` TEXT,
    -- Confirmation tracking
    `confirmed_at` DATETIME NULL,
    `confirmed_by` INT UNSIGNED NULL,
    `submitted_at` DATETIME NULL,
    `submitted_by` INT UNSIGNED NULL,
    `payroll_ready_at` DATETIME NULL,
    `payroll_ready_by` INT UNSIGNED NULL,
    `returned_at` DATETIME NULL,
    `returned_by` INT UNSIGNED NULL,
    `return_reason` TEXT NULL,
    `voided_at` DATETIME NULL,
    `voided_by` INT UNSIGNED NULL,
    `void_reason` TEXT NULL,
    -- Audit
    `created_by` INT UNSIGNED NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_job_date` (`job_id`, `work_date`),
    INDEX `idx_ts_number` (`ts_number`),
    INDEX `idx_job` (`job_id`),
    INDEX `idx_site` (`site_id`),
    INDEX `idx_date` (`work_date`),
    INDEX `idx_status` (`status`),
    CONSTRAINT `fk_ts_job` FOREIGN KEY (`job_id`) REFERENCES `jobs`(`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_ts_site` FOREIGN KEY (`site_id`) REFERENCES `sites`(`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_ts_created` FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Timesheet header - one per job/day (blueprint §12)';

-- -------------------------------------------
-- Table: timesheet_entries (one per person per timesheet)
-- -------------------------------------------
CREATE TABLE IF NOT EXISTS `timesheet_entries` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `timesheet_id` INT UNSIGNED NOT NULL,
    `people_id` INT UNSIGNED NOT NULL,
    `is_present` TINYINT(1) DEFAULT 1 COMMENT '1=present, 0=absent',
    `absence_reason` VARCHAR(255) NULL,
    -- Time tracking
    `check_in` TIME NULL,
    `check_out` TIME NULL,
    `break_minutes` INT DEFAULT 0,
    `work_hours` DECIMAL(5,2) DEFAULT 0 COMMENT 'Normal work hours',
    `ot_hours` DECIMAL(5,2) DEFAULT 0 COMMENT 'Overtime hours',
    `ot_reason` VARCHAR(255) NULL COMMENT 'Required if OT > 0',
    -- Exceptions/Anomalies
    `is_late` TINYINT(1) DEFAULT 0,
    `late_minutes` INT DEFAULT 0,
    `missing_checkout` TINYINT(1) DEFAULT 0,
    `exception_note` TEXT NULL COMMENT 'Safety incident, special note',
    -- Cost tracking
    `daily_rate` DECIMAL(10,2) DEFAULT 0,
    `ot_rate` DECIMAL(10,2) DEFAULT 0,
    `total_cost` DECIMAL(12,2) DEFAULT 0,
    -- Source tracking
    `from_plan_assignment_id` INT UNSIGNED NULL COMMENT 'If auto-populated from plan',
    `manually_added` TINYINT(1) DEFAULT 0 COMMENT 'True if not from plan',
    `manual_add_reason` VARCHAR(255) NULL COMMENT 'Required if manually_added',
    -- Audit
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_ts_people` (`timesheet_id`, `people_id`),
    INDEX `idx_timesheet` (`timesheet_id`),
    INDEX `idx_people` (`people_id`),
    INDEX `idx_present` (`is_present`),
    CONSTRAINT `fk_tse_timesheet` FOREIGN KEY (`timesheet_id`) REFERENCES `timesheets`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_tse_people` FOREIGN KEY (`people_id`) REFERENCES `people`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Timesheet entries - one per person per timesheet';

-- -------------------------------------------
-- Table: timesheet_corrections
-- For post-confirm corrections (immutability rule)
-- -------------------------------------------
CREATE TABLE IF NOT EXISTS `timesheet_corrections` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `timesheet_id` INT UNSIGNED NOT NULL,
    `entry_id` INT UNSIGNED NULL COMMENT 'Specific entry being corrected, NULL for header',
    `correction_type` ENUM('hours', 'ot', 'attendance', 'rate', 'other') NOT NULL,
    `field_name` VARCHAR(50) NOT NULL,
    `old_value` VARCHAR(255) NULL,
    `new_value` VARCHAR(255) NOT NULL,
    `reason` TEXT NOT NULL,
    `status` ENUM('Pending', 'Approved', 'Rejected') DEFAULT 'Pending',
    `requested_by` INT UNSIGNED NOT NULL,
    `requested_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `approved_by` INT UNSIGNED NULL,
    `approved_at` DATETIME NULL,
    `rejection_reason` TEXT NULL,
    PRIMARY KEY (`id`),
    INDEX `idx_timesheet` (`timesheet_id`),
    INDEX `idx_entry` (`entry_id`),
    INDEX `idx_status` (`status`),
    CONSTRAINT `fk_tsc_timesheet` FOREIGN KEY (`timesheet_id`) REFERENCES `timesheets`(`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_tsc_entry` FOREIGN KEY (`entry_id`) REFERENCES `timesheet_entries`(`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_tsc_requested` FOREIGN KEY (`requested_by`) REFERENCES `users`(`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_tsc_approved` FOREIGN KEY (`approved_by`) REFERENCES `users`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Timesheet corrections - for immutability compliance (agents.md §1.3)';

-- -------------------------------------------
-- Table: timesheet_status_history
-- Append-only status tracking
-- -------------------------------------------
CREATE TABLE IF NOT EXISTS `timesheet_status_history` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `timesheet_id` INT UNSIGNED NOT NULL,
    `old_status` VARCHAR(20) NULL,
    `new_status` VARCHAR(20) NOT NULL,
    `changed_by` INT UNSIGNED NOT NULL,
    `reason` TEXT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_timesheet` (`timesheet_id`),
    INDEX `idx_created` (`created_at`),
    CONSTRAINT `fk_tsh_timesheet` FOREIGN KEY (`timesheet_id`) REFERENCES `timesheets`(`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_tsh_user` FOREIGN KEY (`changed_by`) REFERENCES `users`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Timesheet status history - append-only (agents.md §1.4)';

-- -------------------------------------------
-- Table: timesheet_attachments
-- Photos/documents attached to timesheets
-- -------------------------------------------
CREATE TABLE IF NOT EXISTS `timesheet_attachments` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `timesheet_id` INT UNSIGNED NOT NULL,
    `file_name` VARCHAR(255) NOT NULL,
    `file_path` VARCHAR(500) NOT NULL,
    `file_size` INT UNSIGNED NULL,
    `mime_type` VARCHAR(100) NULL,
    `description` VARCHAR(255) NULL,
    `uploaded_by` INT UNSIGNED NOT NULL,
    `uploaded_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_timesheet` (`timesheet_id`),
    CONSTRAINT `fk_tsa_timesheet` FOREIGN KEY (`timesheet_id`) REFERENCES `timesheets`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_tsa_user` FOREIGN KEY (`uploaded_by`) REFERENCES `users`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Timesheet attachments (site photos, paper timesheets)';

SET FOREIGN_KEY_CHECKS = 1;

-- ===========================================
-- Document Number Settings for Timesheet
-- ===========================================
INSERT INTO `doc_number_settings` (`doc_type`, `prefix`, `current_year`, `next_number`, `padding`, `reset_yearly`) VALUES
('TS', 'TS', 2026, 1, 3, 0)
ON DUPLICATE KEY UPDATE `doc_type` = `doc_type`;

-- ===========================================
-- Permissions for Timesheet
-- ===========================================
INSERT INTO `permissions` (`code`, `name`, `entity_type`, `action`, `description`) VALUES
('TS_VIEW', 'View Timesheet', 'TIMESHEET', 'view', 'View timesheet details'),
('TS_CREATE', 'Create Timesheet', 'TIMESHEET', 'create', 'Create new timesheet'),
('TS_EDIT', 'Edit Timesheet', 'TIMESHEET', 'edit', 'Edit timesheet entries'),
('TS_CONFIRM', 'Confirm Timesheet', 'TIMESHEET', 'approve', 'Supervisor confirm timesheet'),
('TS_HR_APPROVE', 'HR Approve Timesheet', 'TIMESHEET', 'approve', 'HR mark payroll ready'),
('TS_VOID', 'Void Timesheet', 'TIMESHEET', 'void', 'Void timesheet')
ON DUPLICATE KEY UPDATE `code` = `code`;

-- Grant timesheet permissions to relevant roles
-- Supervisor (we'll use PLN for now) can create/edit/confirm
-- HR can view and approve
-- ADM/MGR have full access
INSERT INTO `role_permissions` (`role_id`, `permission_id`)
SELECT r.id, p.id FROM roles r, permissions p 
WHERE r.code IN ('ADM', 'MGR') AND p.entity_type = 'TIMESHEET'
ON DUPLICATE KEY UPDATE `role_id` = `role_id`;

INSERT INTO `role_permissions` (`role_id`, `permission_id`)
SELECT r.id, p.id FROM roles r, permissions p 
WHERE r.code = 'HR' AND p.code IN ('TS_VIEW', 'TS_HR_APPROVE')
ON DUPLICATE KEY UPDATE `role_id` = `role_id`;

INSERT INTO `role_permissions` (`role_id`, `permission_id`)
SELECT r.id, p.id FROM roles r, permissions p 
WHERE r.code = 'PLN' AND p.code IN ('TS_VIEW', 'TS_CREATE', 'TS_EDIT', 'TS_CONFIRM')
ON DUPLICATE KEY UPDATE `role_id` = `role_id`;
