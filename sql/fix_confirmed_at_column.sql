-- Fix confirmed_at column in plans table
-- Run this if the column is missing

-- For plans table
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'plans' AND COLUMN_NAME = 'confirmed_at');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE `plans` ADD COLUMN `confirmed_at` DATETIME NULL AFTER `status`', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'plans' AND COLUMN_NAME = 'confirmed_by');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE `plans` ADD COLUMN `confirmed_by` INT UNSIGNED NULL AFTER `confirmed_at`', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- For goods_receipts table
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'goods_receipts' AND COLUMN_NAME = 'confirmed_at');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE `goods_receipts` ADD COLUMN `confirmed_at` DATETIME NULL AFTER `status`', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'goods_receipts' AND COLUMN_NAME = 'confirmed_by');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE `goods_receipts` ADD COLUMN `confirmed_by` INT UNSIGNED NULL AFTER `confirmed_at`', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
