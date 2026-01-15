-- ===========================================
-- ERP v2 Database Schema - Phase 3: Master Data
-- ===========================================
-- Tables: suppliers, items, serials, people, people_certs
-- ===========================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- -------------------------------------------
-- Table: suppliers
-- -------------------------------------------
CREATE TABLE IF NOT EXISTS `suppliers` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `code` VARCHAR(20) NOT NULL UNIQUE,
    `name` VARCHAR(200) NOT NULL,
    `contact_name` VARCHAR(100),
    `phone` VARCHAR(50),
    `email` VARCHAR(100),
    `address` TEXT,
    `tax_id` VARCHAR(20),
    `payment_terms` INT DEFAULT 30 COMMENT 'Days',
    `bank_name` VARCHAR(100),
    `bank_account` VARCHAR(50),
    `notes` TEXT,
    `is_active` TINYINT(1) DEFAULT 1,
    `created_by` INT UNSIGNED,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_code` (`code`),
    INDEX `idx_name` (`name`),
    INDEX `idx_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------
-- Table: items
-- 4 types: Device, Equipment, Vehicle, Consumable
-- -------------------------------------------
CREATE TABLE IF NOT EXISTS `items` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `code` VARCHAR(30) NOT NULL UNIQUE,
    `name` VARCHAR(200) NOT NULL,
    `item_type` ENUM('Device', 'Equipment', 'Vehicle', 'Consumable') NOT NULL,
    `category` VARCHAR(100) COMMENT 'Sub-category',
    `brand` VARCHAR(100),
    `model` VARCHAR(100),
    `description` TEXT,
    `unit` VARCHAR(20) DEFAULT 'pcs' COMMENT 'Unit of measure',
    `is_serialized` TINYINT(1) DEFAULT 0 COMMENT 'Track by serial number',
    `min_stock` INT DEFAULT 0,
    `cost_price` DECIMAL(15,2) DEFAULT 0,
    `rental_price_day` DECIMAL(15,2) DEFAULT 0 COMMENT 'Daily rental rate',
    `sale_price` DECIMAL(15,2) DEFAULT 0,
    `supplier_id` INT UNSIGNED COMMENT 'Default supplier',
    `is_active` TINYINT(1) DEFAULT 1,
    `created_by` INT UNSIGNED,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_code` (`code`),
    INDEX `idx_type` (`item_type`),
    INDEX `idx_name` (`name`),
    INDEX `idx_active` (`is_active`),
    CONSTRAINT `fk_item_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------
-- Table: serials
-- Serial numbers for serialized items
-- -------------------------------------------
CREATE TABLE IF NOT EXISTS `serials` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `item_id` INT UNSIGNED NOT NULL,
    `serial_number` VARCHAR(100) NOT NULL,
    `status` ENUM('Available', 'Allocated', 'Dispatched', 'InUse', 'Returned', 'Damaged', 'Lost', 'Sold') DEFAULT 'Available',
    `condition_note` TEXT,
    `purchase_date` DATE,
    `purchase_price` DECIMAL(15,2),
    `warranty_until` DATE,
    `current_job_id` INT UNSIGNED COMMENT 'If allocated/dispatched',
    `location` VARCHAR(100) COMMENT 'Current location/warehouse',
    `created_by` INT UNSIGNED,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_item_serial` (`item_id`, `serial_number`),
    INDEX `idx_serial` (`serial_number`),
    INDEX `idx_status` (`status`),
    INDEX `idx_job` (`current_job_id`),
    CONSTRAINT `fk_serial_item` FOREIGN KEY (`item_id`) REFERENCES `items`(`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_serial_job` FOREIGN KEY (`current_job_id`) REFERENCES `jobs`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------
-- Table: people
-- Employees + External Manpower
-- -------------------------------------------
CREATE TABLE IF NOT EXISTS `people` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `code` VARCHAR(20) NOT NULL UNIQUE COMMENT 'Employee ID or External ID',
    `full_name` VARCHAR(200) NOT NULL,
    `people_type` ENUM('Employee', 'External') NOT NULL,
    `position` VARCHAR(100),
    `department` VARCHAR(100),
    `phone` VARCHAR(50),
    `email` VARCHAR(100),
    `id_card` VARCHAR(20) COMMENT 'บัตรประชาชน',
    `address` TEXT,
    `daily_rate` DECIMAL(10,2) DEFAULT 0 COMMENT 'For manpower cost calculation',
    `emergency_contact` VARCHAR(200),
    `emergency_phone` VARCHAR(50),
    `supplier_id` INT UNSIGNED COMMENT 'For External: which supplier provides',
    `hire_date` DATE,
    `end_date` DATE,
    `is_active` TINYINT(1) DEFAULT 1,
    `created_by` INT UNSIGNED,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_code` (`code`),
    INDEX `idx_type` (`people_type`),
    INDEX `idx_name` (`full_name`),
    INDEX `idx_active` (`is_active`),
    CONSTRAINT `fk_people_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------
-- Table: people_certs
-- Certifications for people
-- -------------------------------------------
CREATE TABLE IF NOT EXISTS `people_certs` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `people_id` INT UNSIGNED NOT NULL,
    `cert_name` VARCHAR(100) NOT NULL,
    `cert_number` VARCHAR(50),
    `issued_by` VARCHAR(100),
    `issued_date` DATE,
    `expiry_date` DATE,
    `document_path` VARCHAR(255) COMMENT 'Uploaded certificate file',
    `is_active` TINYINT(1) DEFAULT 1,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_people` (`people_id`),
    INDEX `idx_expiry` (`expiry_date`),
    CONSTRAINT `fk_cert_people` FOREIGN KEY (`people_id`) REFERENCES `people`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- ===========================================
-- SEED DATA
-- ===========================================

-- Sample suppliers
INSERT INTO `suppliers` (`code`, `name`, `contact_name`, `phone`, `payment_terms`, `created_by`) VALUES
('SUP001', 'บริษัท ABC ซัพพลาย จำกัด', 'คุณสมชาย', '02-111-1111', 30, 1),
('SUP002', 'ห้างหุ้นส่วน XYZ เครื่องมือ', 'คุณสมหญิง', '02-222-2222', 45, 1);

-- Sample items
INSERT INTO `items` (`code`, `name`, `item_type`, `brand`, `unit`, `is_serialized`, `rental_price_day`, `created_by`) VALUES
('DEV001', 'iPad Pro 12.9"', 'Device', 'Apple', 'pcs', 1, 500, 1),
('DEV002', 'Samsung Galaxy Tab S9', 'Device', 'Samsung', 'pcs', 1, 400, 1),
('EQP001', 'Projector Epson EB-X51', 'Equipment', 'Epson', 'pcs', 1, 800, 1),
('VEH001', 'Toyota Hiace (รถตู้)', 'Vehicle', 'Toyota', 'คัน', 1, 2500, 1),
('CON001', 'สาย HDMI 3m', 'Consumable', 'Generic', 'เส้น', 0, 0, 1),
('CON002', 'ถ่าน AA Duracell', 'Consumable', 'Duracell', 'ก้อน', 0, 0, 1);

-- Sample serials for devices
INSERT INTO `serials` (`item_id`, `serial_number`, `status`, `location`, `created_by`) VALUES
(1, 'IPAD-2024-001', 'Available', 'คลังหลัก', 1),
(1, 'IPAD-2024-002', 'Available', 'คลังหลัก', 1),
(1, 'IPAD-2024-003', 'Available', 'คลังหลัก', 1),
(2, 'TAB-S9-001', 'Available', 'คลังหลัก', 1),
(2, 'TAB-S9-002', 'Available', 'คลังหลัก', 1),
(3, 'PROJ-EB-001', 'Available', 'คลังหลัก', 1),
(4, 'HIACE-001', 'Available', 'ลานจอดรถ', 1);

-- Sample people
INSERT INTO `people` (`code`, `full_name`, `people_type`, `position`, `department`, `phone`, `daily_rate`, `created_by`) VALUES
('EMP001', 'นายสมชาย ใจดี', 'Employee', 'Senior Technician', 'Operation', '089-111-1111', 0, 1),
('EMP002', 'นางสาวสมหญิง รักงาน', 'Employee', 'Technician', 'Operation', '089-222-2222', 0, 1),
('EXT001', 'นายแรงงาน หนึ่ง', 'External', 'Helper', NULL, '089-333-3333', 500, 1),
('EXT002', 'นายแรงงาน สอง', 'External', 'Helper', NULL, '089-444-4444', 500, 1);

-- Sample certificates
INSERT INTO `people_certs` (`people_id`, `cert_name`, `cert_number`, `expiry_date`) VALUES
(1, 'ใบขับขี่ประเภท 2', 'DL-12345678', '2027-12-31'),
(1, 'ความปลอดภัยในการทำงาน', 'SAFE-001', '2025-06-30'),
(2, 'ใบขับขี่ประเภท 1', 'DL-87654321', '2026-08-15');
