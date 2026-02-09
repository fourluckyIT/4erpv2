-- Add manpower duration/unit fields to po_items
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

SET @col_duration = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'po_items'
      AND COLUMN_NAME = 'manpower_duration'
);
SET @sql = IF(@col_duration = 0,
    'ALTER TABLE po_items ADD COLUMN manpower_duration DECIMAL(10,2) DEFAULT NULL AFTER unit',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_unit = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'po_items'
      AND COLUMN_NAME = 'manpower_unit'
);
SET @sql = IF(@col_unit = 0,
    'ALTER TABLE po_items ADD COLUMN manpower_unit ENUM(\'Day\',\'Month\') DEFAULT NULL AFTER manpower_duration',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET FOREIGN_KEY_CHECKS = 1;
