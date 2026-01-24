-- Item Types Management Table
-- Allows custom item types for planning tabs

CREATE TABLE IF NOT EXISTS `item_types` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `code` VARCHAR(50) NOT NULL UNIQUE,
    `name` VARCHAR(100) NOT NULL,
    `description` TEXT,
    `icon` VARCHAR(50) DEFAULT 'bi-box',
    `color` VARCHAR(20) DEFAULT 'secondary',
    `is_serialized` TINYINT(1) DEFAULT 0 COMMENT '1=requires serial tracking',
    `requires_return` TINYINT(1) DEFAULT 0 COMMENT '1=must return after job',
    `requires_condition_check` TINYINT(1) DEFAULT 0 COMMENT '1=needs condition check',
    `show_in_planning` TINYINT(1) DEFAULT 1 COMMENT '1=show as tab in planning',
    `planning_tab_order` INT DEFAULT 99,
    `is_system` TINYINT(1) DEFAULT 0 COMMENT '1=system type, cannot delete',
    `is_active` TINYINT(1) DEFAULT 1,
    `created_by` INT UNSIGNED,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_code` (`code`),
    INDEX `idx_active` (`is_active`),
    INDEX `idx_planning` (`show_in_planning`, `planning_tab_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert default types (system types)
INSERT INTO `item_types` (`code`, `name`, `icon`, `color`, `is_serialized`, `requires_return`, `requires_condition_check`, `show_in_planning`, `planning_tab_order`, `is_system`) VALUES
('Device', 'อุปกรณ์ (Device)', 'bi-cpu', 'primary', 1, 1, 1, 1, 1, 1),
('Equipment', 'เครื่องมือ (Equipment)', 'bi-tools', 'info', 1, 1, 0, 1, 2, 1),
('Vehicle', 'ยานพาหนะ (Vehicle)', 'bi-truck', 'warning', 1, 1, 0, 0, 3, 1),
('Consumable', 'วัสดุสิ้นเปลือง (Consumable)', 'bi-box', 'secondary', 0, 0, 0, 1, 4, 1),
('Manpower', 'แรงงาน (Manpower)', 'bi-people', 'success', 0, 0, 0, 1, 0, 1)
ON DUPLICATE KEY UPDATE name = VALUES(name);

-- Modify items table to reference item_types
-- ALTER TABLE items ADD COLUMN item_type_id INT UNSIGNED AFTER item_type;
-- ALTER TABLE items ADD FOREIGN KEY (item_type_id) REFERENCES item_types(id);
