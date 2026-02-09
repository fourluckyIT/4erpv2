-- Add supplier_type column to suppliers
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

SET @col_exists = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'suppliers'
      AND COLUMN_NAME = 'supplier_type'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE suppliers ADD COLUMN supplier_type ENUM(\'Goods\',\'Service\',\'Manpower\') DEFAULT \'Goods\' AFTER name',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx_exists = (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'suppliers'
      AND INDEX_NAME = 'idx_supplier_type'
);
SET @sql = IF(@idx_exists = 0,
    'CREATE INDEX idx_supplier_type ON suppliers(supplier_type)',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET FOREIGN_KEY_CHECKS = 1;
