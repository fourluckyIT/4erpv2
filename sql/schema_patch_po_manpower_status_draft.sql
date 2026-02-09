-- Add Draft status to po_manpower
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

SET @status_type = (
    SELECT COLUMN_TYPE FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'po_manpower'
      AND COLUMN_NAME = 'status'
);
SET @needs = IF(@status_type LIKE "%'Draft'%", 0, 1);
SET @sql = IF(@needs = 1,
    "ALTER TABLE po_manpower MODIFY status ENUM('Draft','Active','Ended','Cancelled') NOT NULL DEFAULT 'Draft'",
    "SELECT 1"
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET FOREIGN_KEY_CHECKS = 1;
