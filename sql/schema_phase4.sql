-- ===========================================
-- ERP v2 Database Schema - Phase 4: Procurement
-- ===========================================
-- Tables: purchase_requests, pr_items, purchase_orders, po_items, goods_receipts, gr_items
-- ===========================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- -------------------------------------------
-- Table: purchase_requests (PR)
-- -------------------------------------------
CREATE TABLE IF NOT EXISTS `purchase_requests` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `pr_number` VARCHAR(30) NOT NULL UNIQUE,
    `job_id` INT UNSIGNED COMMENT 'Optional link to job',
    `requester_id` INT UNSIGNED NOT NULL,
    `purpose` TEXT NOT NULL COMMENT 'วัตถุประสงค์',
    `required_date` DATE COMMENT 'วันที่ต้องการ',
    `status` ENUM('Draft', 'Submitted', 'Approved', 'Rejected', 'Cancelled') NOT NULL DEFAULT 'Draft',
    `total_amount` DECIMAL(15,2) DEFAULT 0,
    `notes` TEXT,
    `submitted_at` DATETIME,
    `submitted_by` INT UNSIGNED,
    `approved_at` DATETIME,
    `approved_by` INT UNSIGNED,
    `rejected_at` DATETIME,
    `rejected_by` INT UNSIGNED,
    `reject_reason` TEXT,
    `created_by` INT UNSIGNED NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_pr_number` (`pr_number`),
    INDEX `idx_status` (`status`),
    INDEX `idx_requester` (`requester_id`),
    INDEX `idx_job` (`job_id`),
    CONSTRAINT `fk_pr_job` FOREIGN KEY (`job_id`) REFERENCES `jobs`(`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_pr_requester` FOREIGN KEY (`requester_id`) REFERENCES `users`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------
-- Table: pr_items
-- -------------------------------------------
CREATE TABLE IF NOT EXISTS `pr_items` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `pr_id` INT UNSIGNED NOT NULL,
    `item_id` INT UNSIGNED COMMENT 'Nullable for non-catalog items',
    `description` TEXT NOT NULL,
    `qty` DECIMAL(10,2) NOT NULL,
    `unit` VARCHAR(20) DEFAULT 'pcs',
    `unit_price` DECIMAL(15,2) DEFAULT 0,
    `amount` DECIMAL(15,2) DEFAULT 0,
    `notes` TEXT,
    PRIMARY KEY (`id`),
    INDEX `idx_pr` (`pr_id`),
    CONSTRAINT `fk_pri_pr` FOREIGN KEY (`pr_id`) REFERENCES `purchase_requests`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_pri_item` FOREIGN KEY (`item_id`) REFERENCES `items`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------
-- Table: purchase_orders (PO)
-- -------------------------------------------
CREATE TABLE IF NOT EXISTS `purchase_orders` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `po_number` VARCHAR(30) NOT NULL UNIQUE,
    `pr_id` INT UNSIGNED COMMENT 'Optional - from PR',
    `supplier_id` INT UNSIGNED NOT NULL,
    `po_type` ENUM('Goods', 'Service', 'Manpower') NOT NULL DEFAULT 'Goods',
    `order_date` DATE NOT NULL,
    `delivery_date` DATE,
    `status` ENUM('Draft', 'Submitted', 'Approved', 'Partially Received', 'Received', 'Cancelled', 'Voided') NOT NULL DEFAULT 'Draft',
    `payment_terms` INT DEFAULT 30 COMMENT 'Days',
    `subtotal` DECIMAL(15,2) DEFAULT 0,
    `vat_rate` DECIMAL(5,2) DEFAULT 7.00,
    `vat_amount` DECIMAL(15,2) DEFAULT 0,
    `grand_total` DECIMAL(15,2) DEFAULT 0,
    `notes` TEXT,
    `submitted_at` DATETIME,
    `submitted_by` INT UNSIGNED,
    `approved_at` DATETIME,
    `approved_by` INT UNSIGNED,
    `voided_at` DATETIME,
    `voided_by` INT UNSIGNED,
    `void_reason` TEXT,
    `created_by` INT UNSIGNED NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_po_number` (`po_number`),
    INDEX `idx_status` (`status`),
    INDEX `idx_supplier` (`supplier_id`),
    INDEX `idx_pr` (`pr_id`),
    CONSTRAINT `fk_po_pr` FOREIGN KEY (`pr_id`) REFERENCES `purchase_requests`(`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_po_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------
-- Table: po_items
-- -------------------------------------------
CREATE TABLE IF NOT EXISTS `po_items` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `po_id` INT UNSIGNED NOT NULL,
    `pr_item_id` INT UNSIGNED COMMENT 'Link to PR item if from PR',
    `item_id` INT UNSIGNED COMMENT 'Nullable for non-catalog items',
    `description` TEXT NOT NULL,
    `qty` DECIMAL(10,2) NOT NULL,
    `received_qty` DECIMAL(10,2) DEFAULT 0,
    `unit` VARCHAR(20) DEFAULT 'pcs',
    `manpower_duration` DECIMAL(10,2) DEFAULT NULL,
    `manpower_unit` ENUM('Day','Month') DEFAULT NULL,
    `unit_price` DECIMAL(15,2) DEFAULT 0,
    `amount` DECIMAL(15,2) DEFAULT 0,
    `notes` TEXT,
    PRIMARY KEY (`id`),
    INDEX `idx_po` (`po_id`),
    CONSTRAINT `fk_poi_po` FOREIGN KEY (`po_id`) REFERENCES `purchase_orders`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_poi_item` FOREIGN KEY (`item_id`) REFERENCES `items`(`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_poi_pr_item` FOREIGN KEY (`pr_item_id`) REFERENCES `pr_items`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------
-- Table: goods_receipts (GR)
-- -------------------------------------------
CREATE TABLE IF NOT EXISTS `goods_receipts` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `gr_number` VARCHAR(30) NOT NULL UNIQUE,
    `po_id` INT UNSIGNED NOT NULL,
    `received_date` DATE NOT NULL,
    `received_by` INT UNSIGNED NOT NULL,
    `status` ENUM('Draft', 'Confirmed', 'Voided') NOT NULL DEFAULT 'Draft',
    `notes` TEXT,
    `confirmed_at` DATETIME,
    `confirmed_by` INT UNSIGNED,
    `voided_at` DATETIME,
    `voided_by` INT UNSIGNED,
    `void_reason` TEXT,
    `created_by` INT UNSIGNED NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_gr_number` (`gr_number`),
    INDEX `idx_po` (`po_id`),
    INDEX `idx_status` (`status`),
    CONSTRAINT `fk_gr_po` FOREIGN KEY (`po_id`) REFERENCES `purchase_orders`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------
-- Table: gr_items
-- -------------------------------------------
CREATE TABLE IF NOT EXISTS `gr_items` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `gr_id` INT UNSIGNED NOT NULL,
    `po_item_id` INT UNSIGNED NOT NULL,
    `received_qty` DECIMAL(10,2) NOT NULL,
    `serial_numbers` JSON COMMENT 'For serialized items',
    `condition_note` TEXT,
    PRIMARY KEY (`id`),
    INDEX `idx_gr` (`gr_id`),
    INDEX `idx_po_item` (`po_item_id`),
    CONSTRAINT `fk_gri_gr` FOREIGN KEY (`gr_id`) REFERENCES `goods_receipts`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_gri_po_item` FOREIGN KEY (`po_item_id`) REFERENCES `po_items`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------
-- Table: po_manpower (for Manpower type PO)
-- Links PO to people
-- -------------------------------------------
CREATE TABLE IF NOT EXISTS `po_manpower` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `po_id` INT UNSIGNED NOT NULL,
    `people_id` INT UNSIGNED NOT NULL,
    `position` VARCHAR(100),
    `daily_rate` DECIMAL(10,2) DEFAULT 0,
    `contract_start` DATE,
    `contract_end` DATE,
    `work_days_per_month` INT UNSIGNED DEFAULT NULL,
    `status` ENUM('Draft', 'Active', 'Ended', 'Cancelled') DEFAULT 'Draft',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_po` (`po_id`),
    INDEX `idx_people` (`people_id`),
    CONSTRAINT `fk_pom_po` FOREIGN KEY (`po_id`) REFERENCES `purchase_orders`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_pom_people` FOREIGN KEY (`people_id`) REFERENCES `people`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- ===========================================
-- Add document number settings for PR, PO, GR
-- ===========================================
INSERT INTO `doc_number_settings` (`doc_type`, `prefix`, `current_year`, `next_number`, `padding`, `reset_yearly`) VALUES
('PR', 'PR', 2026, 1, 5, 1),
('PO', 'PO', 2026, 1, 5, 1),
('GR', 'GR', 2026, 1, 5, 1)
ON DUPLICATE KEY UPDATE `doc_type` = `doc_type`;
