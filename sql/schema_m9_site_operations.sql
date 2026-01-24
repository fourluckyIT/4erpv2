-- ===========================================
-- ERP v2 Database Schema - M9: Site Operations
-- ===========================================
-- Following blueprint.md §11:
-- - Site receiving confirmation
-- - Return notes
-- - Damage/Loss reports with photos
-- - Partial delivery/return support
-- ===========================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- -------------------------------------------
-- Table: site_receipts
-- Confirmation of items received at site
-- -------------------------------------------
CREATE TABLE IF NOT EXISTS `site_receipts` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `receipt_number` VARCHAR(30) NOT NULL UNIQUE,
    `route_id` INT UNSIGNED NOT NULL,
    `job_id` INT UNSIGNED NOT NULL,
    `site_id` INT UNSIGNED NULL,
    `received_date` DATE NOT NULL,
    `received_by_name` VARCHAR(100) NOT NULL COMMENT 'Name of person at site who received',
    `received_by_phone` VARCHAR(50) NULL,
    `status` ENUM('Draft', 'Confirmed', 'Disputed') NOT NULL DEFAULT 'Draft',
    `notes` TEXT NULL,
    `signature_path` VARCHAR(255) NULL COMMENT 'Digital signature image',
    -- Confirmation
    `confirmed_at` DATETIME NULL,
    `confirmed_by` INT UNSIGNED NULL,
    -- Audit
    `created_by` INT UNSIGNED NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_receipt_number` (`receipt_number`),
    INDEX `idx_route` (`route_id`),
    INDEX `idx_job` (`job_id`),
    INDEX `idx_status` (`status`),
    CONSTRAINT `fk_sr_route` FOREIGN KEY (`route_id`) REFERENCES `routes`(`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_sr_job` FOREIGN KEY (`job_id`) REFERENCES `jobs`(`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_sr_site` FOREIGN KEY (`site_id`) REFERENCES `sites`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Site receiving confirmation (blueprint §11)';

-- -------------------------------------------
-- Table: site_receipt_items
-- Items received at site (may differ from dispatched)
-- -------------------------------------------
CREATE TABLE IF NOT EXISTS `site_receipt_items` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `receipt_id` INT UNSIGNED NOT NULL,
    `route_item_id` INT UNSIGNED NULL COMMENT 'Link to dispatched item',
    `item_id` INT UNSIGNED NOT NULL,
    `serial_id` INT UNSIGNED NULL,
    `qty_expected` DECIMAL(10,2) NOT NULL DEFAULT 1,
    `qty_received` DECIMAL(10,2) NOT NULL DEFAULT 0,
    `qty_short` DECIMAL(10,2) GENERATED ALWAYS AS (`qty_expected` - `qty_received`) STORED,
    `condition_received` ENUM('Good', 'Fair', 'Damaged') DEFAULT 'Good',
    `condition_notes` TEXT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_receipt` (`receipt_id`),
    INDEX `idx_item` (`item_id`),
    INDEX `idx_serial` (`serial_id`),
    CONSTRAINT `fk_sri_receipt` FOREIGN KEY (`receipt_id`) REFERENCES `site_receipts`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_sri_route_item` FOREIGN KEY (`route_item_id`) REFERENCES `route_items`(`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_sri_item` FOREIGN KEY (`item_id`) REFERENCES `items`(`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_sri_serial` FOREIGN KEY (`serial_id`) REFERENCES `serials`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------
-- Table: return_notes
-- Items being returned from site to warehouse
-- -------------------------------------------
CREATE TABLE IF NOT EXISTS `return_notes` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `return_number` VARCHAR(30) NOT NULL UNIQUE,
    `job_id` INT UNSIGNED NOT NULL,
    `route_id` INT UNSIGNED NULL COMMENT 'If part of a return route',
    `site_id` INT UNSIGNED NULL,
    `return_date` DATE NOT NULL,
    `return_type` ENUM('Full', 'Partial', 'EndOfJob') NOT NULL DEFAULT 'Full',
    `status` ENUM('Draft', 'InTransit', 'WHReceived', 'Inspected', 'Closed', 'Disputed') NOT NULL DEFAULT 'Draft',
    `notes` TEXT NULL,
    -- Timestamps
    `in_transit_at` DATETIME NULL,
    `in_transit_by` INT UNSIGNED NULL,
    `wh_received_at` DATETIME NULL,
    `wh_received_by` INT UNSIGNED NULL,
    `inspected_at` DATETIME NULL,
    `inspected_by` INT UNSIGNED NULL,
    `closed_at` DATETIME NULL,
    `closed_by` INT UNSIGNED NULL,
    -- Audit
    `created_by` INT UNSIGNED NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_return_number` (`return_number`),
    INDEX `idx_job` (`job_id`),
    INDEX `idx_route` (`route_id`),
    INDEX `idx_status` (`status`),
    CONSTRAINT `fk_rn_job` FOREIGN KEY (`job_id`) REFERENCES `jobs`(`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_rn_route` FOREIGN KEY (`route_id`) REFERENCES `routes`(`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_rn_site` FOREIGN KEY (`site_id`) REFERENCES `sites`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Return notes for items going back to WH (blueprint §11)';

-- -------------------------------------------
-- Table: return_note_items
-- Items in return note
-- -------------------------------------------
CREATE TABLE IF NOT EXISTS `return_note_items` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `return_note_id` INT UNSIGNED NOT NULL,
    `item_id` INT UNSIGNED NOT NULL,
    `serial_id` INT UNSIGNED NULL,
    `qty_sent` DECIMAL(10,2) NOT NULL DEFAULT 1 COMMENT 'Qty sent from site',
    `qty_received` DECIMAL(10,2) NULL COMMENT 'Qty received at WH',
    `condition_out` ENUM('Good', 'Fair', 'Damaged', 'Lost') DEFAULT 'Good' COMMENT 'Condition at site',
    `condition_in` ENUM('Good', 'Fair', 'Damaged', 'Lost') NULL COMMENT 'Condition at WH',
    `inspection_notes` TEXT NULL,
    `is_loss` TINYINT(1) DEFAULT 0,
    `loss_reason` TEXT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_return_note` (`return_note_id`),
    INDEX `idx_item` (`item_id`),
    INDEX `idx_serial` (`serial_id`),
    CONSTRAINT `fk_rni_return` FOREIGN KEY (`return_note_id`) REFERENCES `return_notes`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_rni_item` FOREIGN KEY (`item_id`) REFERENCES `items`(`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_rni_serial` FOREIGN KEY (`serial_id`) REFERENCES `serials`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------
-- Table: damage_reports
-- Damage/Loss reports with accountability
-- -------------------------------------------
CREATE TABLE IF NOT EXISTS `damage_reports` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `report_number` VARCHAR(30) NOT NULL UNIQUE,
    `job_id` INT UNSIGNED NOT NULL,
    `route_id` INT UNSIGNED NULL,
    `return_note_id` INT UNSIGNED NULL,
    `item_id` INT UNSIGNED NOT NULL,
    `serial_id` INT UNSIGNED NULL,
    `qty` DECIMAL(10,2) NOT NULL DEFAULT 1,
    -- Damage details
    `incident_type` ENUM('Damage', 'Loss', 'Theft', 'Wear', 'Other') NOT NULL,
    `incident_date` DATE NOT NULL,
    `incident_location` VARCHAR(255) NULL,
    `description` TEXT NOT NULL,
    `responsible_type` ENUM('Customer', 'Employee', 'Supplier', 'Unknown', 'Normal Wear') NOT NULL,
    `responsible_name` VARCHAR(100) NULL,
    `responsible_people_id` INT UNSIGNED NULL,
    -- Financial
    `estimated_value` DECIMAL(15,2) NULL,
    `claim_amount` DECIMAL(15,2) NULL,
    `is_claimable` TINYINT(1) DEFAULT 0,
    -- Resolution
    `status` ENUM('Reported', 'UnderReview', 'Approved', 'Claimed', 'WriteOff', 'Resolved') NOT NULL DEFAULT 'Reported',
    `action_taken` ENUM('Repair', 'Replace', 'Scrap', 'Claim', 'WriteOff', 'None') NULL,
    `resolution_notes` TEXT NULL,
    `resolved_at` DATETIME NULL,
    `resolved_by` INT UNSIGNED NULL,
    -- Approval if needed
    `approved_by` INT UNSIGNED NULL,
    `approved_at` DATETIME NULL,
    -- Audit
    `reported_by` INT UNSIGNED NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_report_number` (`report_number`),
    INDEX `idx_job` (`job_id`),
    INDEX `idx_item` (`item_id`),
    INDEX `idx_serial` (`serial_id`),
    INDEX `idx_status` (`status`),
    CONSTRAINT `fk_dr_job` FOREIGN KEY (`job_id`) REFERENCES `jobs`(`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_dr_route` FOREIGN KEY (`route_id`) REFERENCES `routes`(`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_dr_return` FOREIGN KEY (`return_note_id`) REFERENCES `return_notes`(`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_dr_item` FOREIGN KEY (`item_id`) REFERENCES `items`(`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_dr_serial` FOREIGN KEY (`serial_id`) REFERENCES `serials`(`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_dr_responsible` FOREIGN KEY (`responsible_people_id`) REFERENCES `people`(`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_dr_reported` FOREIGN KEY (`reported_by`) REFERENCES `users`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Damage/Loss reports (blueprint §11)';

-- -------------------------------------------
-- Table: damage_report_photos
-- Photos attached to damage reports
-- -------------------------------------------
CREATE TABLE IF NOT EXISTS `damage_report_photos` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `damage_report_id` INT UNSIGNED NOT NULL,
    `photo_seq` TINYINT UNSIGNED NOT NULL,
    `file_path` VARCHAR(255) NOT NULL,
    `file_size` INT UNSIGNED NULL,
    `caption` VARCHAR(255) NULL,
    `uploaded_by` INT UNSIGNED NOT NULL,
    `uploaded_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_report` (`damage_report_id`),
    CONSTRAINT `fk_drp_report` FOREIGN KEY (`damage_report_id`) REFERENCES `damage_reports`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_drp_user` FOREIGN KEY (`uploaded_by`) REFERENCES `users`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- ===========================================
-- Document Number Settings
-- ===========================================
INSERT INTO `doc_number_settings` (`doc_type`, `prefix`, `current_year`, `next_number`, `padding`, `reset_yearly`) VALUES
('SR', 'SR', 2026, 1, 5, 1),
('RTN', 'RTN', 2026, 1, 5, 1),
('DMG', 'DMG', 2026, 1, 5, 1)
ON DUPLICATE KEY UPDATE `doc_type` = `doc_type`;

-- ===========================================
-- Permissions
-- ===========================================
INSERT INTO `permissions` (`code`, `name`, `entity_type`, `action`, `description`) VALUES
('SITE_RECEIPT_VIEW', 'View Site Receipt', 'SITE_RECEIPT', 'view', 'View site receipts'),
('SITE_RECEIPT_CREATE', 'Create Site Receipt', 'SITE_RECEIPT', 'create', 'Create site receipt'),
('RETURN_VIEW', 'View Return Note', 'RETURN', 'view', 'View return notes'),
('RETURN_CREATE', 'Create Return Note', 'RETURN', 'create', 'Create return note'),
('RETURN_RECEIVE', 'WH Receive Return', 'RETURN', 'edit', 'Receive return at warehouse'),
('DAMAGE_VIEW', 'View Damage Report', 'DAMAGE', 'view', 'View damage reports'),
('DAMAGE_CREATE', 'Create Damage Report', 'DAMAGE', 'create', 'Create damage report'),
('DAMAGE_APPROVE', 'Approve Damage Report', 'DAMAGE', 'approve', 'Approve damage resolution')
ON DUPLICATE KEY UPDATE `code` = `code`;

-- Grant permissions to roles
INSERT INTO `role_permissions` (`role_id`, `permission_id`)
SELECT r.id, p.id FROM roles r, permissions p 
WHERE r.code IN ('ADM', 'MGR', 'WH') AND p.entity_type IN ('SITE_RECEIPT', 'RETURN', 'DAMAGE')
ON DUPLICATE KEY UPDATE `role_id` = `role_id`;

INSERT INTO `role_permissions` (`role_id`, `permission_id`)
SELECT r.id, p.id FROM roles r, permissions p 
WHERE r.code = 'PLN' AND p.code IN ('SITE_RECEIPT_VIEW', 'RETURN_VIEW', 'DAMAGE_VIEW', 'DAMAGE_CREATE')
ON DUPLICATE KEY UPDATE `role_id` = `role_id`;
