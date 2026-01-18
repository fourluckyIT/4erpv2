-- M4: Warehouse & Stock Movements Schema
-- ERP v2 - Append-Only Stock Ledger (NO DELETE allowed per agents.md §1.1)

-- Stock Movements Ledger (APPEND-ONLY)
CREATE TABLE IF NOT EXISTS `stock_movements` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `movement_type` ENUM('GI_JOB', 'GR_PO', 'RETURN_JOB', 'WH_RECEIVE', 'ADJUSTMENT', 'REVERSAL') NOT NULL,
    `item_id` INT UNSIGNED NOT NULL,
    `serial_id` INT UNSIGNED NULL COMMENT 'For serialized items',
    `qty` DECIMAL(12,2) NOT NULL COMMENT 'Positive=in, Negative=out',
    `from_location` ENUM('WH', 'SITE', 'IN_TRANSIT', 'SUPPLIER', 'VOID') DEFAULT NULL,
    `to_location` ENUM('WH', 'SITE', 'IN_TRANSIT', 'SUPPLIER', 'VOID') DEFAULT NULL,
    `reference_table` VARCHAR(50) NOT NULL COMMENT 'routes, goods_receipts, etc.',
    `reference_id` INT UNSIGNED NOT NULL,
    `reverse_of_id` INT UNSIGNED NULL COMMENT 'If reversal, points to original movement',
    `notes` TEXT NULL,
    `created_by` INT UNSIGNED NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    INDEX `idx_sm_item` (`item_id`),
    INDEX `idx_sm_serial` (`serial_id`),
    INDEX `idx_sm_type` (`movement_type`),
    INDEX `idx_sm_ref` (`reference_table`, `reference_id`),
    INDEX `idx_sm_reverse` (`reverse_of_id`),
    
    CONSTRAINT `fk_sm_item` FOREIGN KEY (`item_id`) REFERENCES `items`(`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_sm_serial` FOREIGN KEY (`serial_id`) REFERENCES `serials`(`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_sm_user` FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_sm_reverse` FOREIGN KEY (`reverse_of_id`) REFERENCES `stock_movements`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Append-only stock ledger - NO DELETE/UPDATE allowed (agents.md §1.1)';

-- Add location tracking to items if not exists
-- ALTER TABLE `items` ADD COLUMN IF NOT EXISTS `location` ENUM('WH', 'SITE', 'IN_TRANSIT') DEFAULT 'WH';
