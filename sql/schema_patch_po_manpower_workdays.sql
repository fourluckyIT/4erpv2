-- Add work_days_per_month to po_manpower
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

SET @col_exists = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'po_manpower'
      AND COLUMN_NAME = 'work_days_per_month'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE po_manpower ADD COLUMN work_days_per_month INT UNSIGNED DEFAULT NULL AFTER contract_end',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET FOREIGN_KEY_CHECKS = 1;
