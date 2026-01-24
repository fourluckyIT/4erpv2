-- ===========================================
-- ERP v2 Database Schema - M10: Rate Cards & Job Costing
-- ===========================================
-- Following blueprint.md §13:
-- - Rate cards per job type
-- - Job cost aggregation
-- - Margin calculation (estimated vs actual)
-- ===========================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- -------------------------------------------
-- Table: rate_cards
-- Pricing templates for different job types
-- -------------------------------------------
CREATE TABLE IF NOT EXISTS `rate_cards` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `code` VARCHAR(30) NOT NULL UNIQUE,
    `name` VARCHAR(100) NOT NULL,
    `job_type` ENUM('Lumpsum', 'Dayrent', 'Manpower') NOT NULL,
    `description` TEXT NULL,
    -- Pricing
    `base_rate` DECIMAL(15,2) NOT NULL DEFAULT 0 COMMENT 'Base rate (lumpsum fixed, dayrent per day)',
    `half_day_rate` DECIMAL(15,2) NULL COMMENT 'For dayrent',
    `minimum_days` INT DEFAULT 1 COMMENT 'Minimum billing days for dayrent',
    `hourly_rate` DECIMAL(10,2) NULL COMMENT 'For manpower',
    `ot_multiplier` DECIMAL(3,2) DEFAULT 1.50 COMMENT 'OT rate = hourly * multiplier',
    -- Tax settings
    `include_vat` TINYINT(1) DEFAULT 0,
    `vat_rate` DECIMAL(5,2) DEFAULT 7.00,
    `withholding_rate` DECIMAL(5,2) DEFAULT 0,
    -- Validity
    `valid_from` DATE NULL,
    `valid_until` DATE NULL,
    `is_active` TINYINT(1) DEFAULT 1,
    -- Audit
    `created_by` INT UNSIGNED NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_code` (`code`),
    INDEX `idx_job_type` (`job_type`),
    INDEX `idx_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Rate cards for job pricing (blueprint §13.1)';

-- -------------------------------------------
-- Table: rate_card_items
-- Item-specific rates within a rate card
-- -------------------------------------------
CREATE TABLE IF NOT EXISTS `rate_card_items` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `rate_card_id` INT UNSIGNED NOT NULL,
    `item_id` INT UNSIGNED NULL COMMENT 'Specific item, NULL for category-level',
    `item_category` VARCHAR(100) NULL COMMENT 'If item_id is NULL, apply to category',
    `rate_type` ENUM('Daily', 'Hourly', 'PerUnit', 'Fixed') NOT NULL DEFAULT 'Daily',
    `rate` DECIMAL(15,2) NOT NULL,
    `min_qty` DECIMAL(10,2) DEFAULT 1,
    `notes` TEXT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_rate_card` (`rate_card_id`),
    INDEX `idx_item` (`item_id`),
    CONSTRAINT `fk_rci_card` FOREIGN KEY (`rate_card_id`) REFERENCES `rate_cards`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_rci_item` FOREIGN KEY (`item_id`) REFERENCES `items`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------
-- Table: job_costs
-- Aggregated costs per job (updated periodically)
-- -------------------------------------------
CREATE TABLE IF NOT EXISTS `job_costs` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `job_id` INT UNSIGNED NOT NULL UNIQUE,
    -- Estimated (from quotation/contract)
    `estimated_revenue` DECIMAL(15,2) DEFAULT 0,
    `estimated_material_cost` DECIMAL(15,2) DEFAULT 0,
    `estimated_manpower_cost` DECIMAL(15,2) DEFAULT 0,
    `estimated_transport_cost` DECIMAL(15,2) DEFAULT 0,
    `estimated_other_cost` DECIMAL(15,2) DEFAULT 0,
    `estimated_total_cost` DECIMAL(15,2) GENERATED ALWAYS AS (
        `estimated_material_cost` + `estimated_manpower_cost` + 
        `estimated_transport_cost` + `estimated_other_cost`
    ) STORED,
    `estimated_margin` DECIMAL(15,2) GENERATED ALWAYS AS (
        `estimated_revenue` - (`estimated_material_cost` + `estimated_manpower_cost` + 
        `estimated_transport_cost` + `estimated_other_cost`)
    ) STORED,
    `estimated_margin_pct` DECIMAL(5,2) GENERATED ALWAYS AS (
        CASE WHEN `estimated_revenue` > 0 
        THEN ((`estimated_revenue` - (`estimated_material_cost` + `estimated_manpower_cost` + 
               `estimated_transport_cost` + `estimated_other_cost`)) / `estimated_revenue` * 100)
        ELSE 0 END
    ) STORED,
    -- Actual (calculated from transactions)
    `actual_revenue` DECIMAL(15,2) DEFAULT 0 COMMENT 'From invoices',
    `actual_material_cost` DECIMAL(15,2) DEFAULT 0 COMMENT 'From stock movements',
    `actual_manpower_cost` DECIMAL(15,2) DEFAULT 0 COMMENT 'From timesheets',
    `actual_transport_cost` DECIMAL(15,2) DEFAULT 0 COMMENT 'From routes/PO',
    `actual_other_cost` DECIMAL(15,2) DEFAULT 0,
    `actual_total_cost` DECIMAL(15,2) GENERATED ALWAYS AS (
        `actual_material_cost` + `actual_manpower_cost` + 
        `actual_transport_cost` + `actual_other_cost`
    ) STORED,
    `actual_margin` DECIMAL(15,2) GENERATED ALWAYS AS (
        `actual_revenue` - (`actual_material_cost` + `actual_manpower_cost` + 
        `actual_transport_cost` + `actual_other_cost`)
    ) STORED,
    `actual_margin_pct` DECIMAL(5,2) GENERATED ALWAYS AS (
        CASE WHEN `actual_revenue` > 0 
        THEN ((`actual_revenue` - (`actual_material_cost` + `actual_manpower_cost` + 
               `actual_transport_cost` + `actual_other_cost`)) / `actual_revenue` * 100)
        ELSE 0 END
    ) STORED,
    -- Tracking
    `last_calculated_at` DATETIME NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_job` (`job_id`),
    CONSTRAINT `fk_jc_job` FOREIGN KEY (`job_id`) REFERENCES `jobs`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Job costing summary (blueprint §13.3)';

-- -------------------------------------------
-- Table: job_cost_lines
-- Detailed cost line items per job
-- -------------------------------------------
CREATE TABLE IF NOT EXISTS `job_cost_lines` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `job_id` INT UNSIGNED NOT NULL,
    `cost_type` ENUM('Material', 'Manpower', 'Transport', 'Outsource', 'Other') NOT NULL,
    `description` VARCHAR(500) NOT NULL,
    `quantity` DECIMAL(12,2) DEFAULT 1,
    `unit` VARCHAR(20) NULL,
    `unit_cost` DECIMAL(15,2) NOT NULL,
    `total_cost` DECIMAL(15,2) GENERATED ALWAYS AS (`quantity` * `unit_cost`) STORED,
    -- Source reference
    `source_type` VARCHAR(50) NULL COMMENT 'TIMESHEET, STOCK_MOVEMENT, PO, ROUTE',
    `source_id` INT UNSIGNED NULL,
    -- Dates
    `cost_date` DATE NOT NULL,
    `notes` TEXT NULL,
    `created_by` INT UNSIGNED NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_job` (`job_id`),
    INDEX `idx_type` (`cost_type`),
    INDEX `idx_date` (`cost_date`),
    INDEX `idx_source` (`source_type`, `source_id`),
    CONSTRAINT `fk_jcl_job` FOREIGN KEY (`job_id`) REFERENCES `jobs`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Detailed job cost lines';

SET FOREIGN_KEY_CHECKS = 1;

-- ===========================================
-- Seed Rate Cards
-- ===========================================
INSERT INTO `rate_cards` (`code`, `name`, `job_type`, `base_rate`, `half_day_rate`, `minimum_days`, `hourly_rate`, `ot_multiplier`) VALUES
('RC-LUMP-STD', 'Lumpsum Standard', 'Lumpsum', 0, NULL, 1, NULL, 1.50),
('RC-DAY-STD', 'Dayrent Standard', 'Dayrent', 5000, 3000, 1, NULL, 1.50),
('RC-DAY-PREM', 'Dayrent Premium', 'Dayrent', 8000, 5000, 1, NULL, 1.50),
('RC-MP-STD', 'Manpower Standard', 'Manpower', 0, NULL, 1, 100, 1.50),
('RC-MP-TECH', 'Manpower Technician', 'Manpower', 0, NULL, 1, 150, 1.50)
ON DUPLICATE KEY UPDATE `code` = `code`;
