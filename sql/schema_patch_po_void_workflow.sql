-- Add void workflow fields to purchase_orders and goods_receipts
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- purchase_orders status enum add 'Voided'
SET @po_status = (
    SELECT COLUMN_TYPE FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'purchase_orders'
      AND COLUMN_NAME = 'status'
);
SET @po_needs = IF(@po_status LIKE "%'Voided'%", 0, 1);
SET @sql = IF(@po_needs = 1,
    "ALTER TABLE purchase_orders MODIFY status ENUM('Draft','Submitted','Approved','Partially Received','Received','Cancelled','Voided') NOT NULL DEFAULT 'Draft'",
    "SELECT 1"
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- purchase_orders void fields
SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'purchase_orders' AND COLUMN_NAME = 'voided_at'
);
SET @sql = IF(@col_exists = 0,
    "ALTER TABLE purchase_orders ADD COLUMN voided_at DATETIME NULL AFTER approved_by",
    "SELECT 1"
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'purchase_orders' AND COLUMN_NAME = 'voided_by'
);
SET @sql = IF(@col_exists = 0,
    "ALTER TABLE purchase_orders ADD COLUMN voided_by INT UNSIGNED NULL AFTER voided_at",
    "SELECT 1"
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'purchase_orders' AND COLUMN_NAME = 'void_reason'
);
SET @sql = IF(@col_exists = 0,
    "ALTER TABLE purchase_orders ADD COLUMN void_reason TEXT NULL AFTER voided_by",
    "SELECT 1"
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- goods_receipts status enum add 'Voided'
SET @gr_status = (
    SELECT COLUMN_TYPE FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'goods_receipts'
      AND COLUMN_NAME = 'status'
);
SET @gr_needs = IF(@gr_status LIKE "%'Voided'%", 0, 1);
SET @sql = IF(@gr_needs = 1,
    "ALTER TABLE goods_receipts MODIFY status ENUM('Draft','Confirmed','Voided') NOT NULL DEFAULT 'Draft'",
    "SELECT 1"
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- goods_receipts void fields
SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'goods_receipts' AND COLUMN_NAME = 'voided_at'
);
SET @sql = IF(@col_exists = 0,
    "ALTER TABLE goods_receipts ADD COLUMN voided_at DATETIME NULL AFTER confirmed_by",
    "SELECT 1"
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'goods_receipts' AND COLUMN_NAME = 'voided_by'
);
SET @sql = IF(@col_exists = 0,
    "ALTER TABLE goods_receipts ADD COLUMN voided_by INT UNSIGNED NULL AFTER voided_at",
    "SELECT 1"
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'goods_receipts' AND COLUMN_NAME = 'void_reason'
);
SET @sql = IF(@col_exists = 0,
    "ALTER TABLE goods_receipts ADD COLUMN void_reason TEXT NULL AFTER voided_by",
    "SELECT 1"
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET FOREIGN_KEY_CHECKS = 1;
