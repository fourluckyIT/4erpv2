-- ===========================================
-- ERP v2 Database Schema - M12: Accounts Payable (AP)
-- ===========================================
-- Following agents.md:
-- - §1.1: No DELETE on financial tables
-- - §1.2: Append-only / Immutability after issued
-- - §1.3: Reversal pattern for corrections
-- - §1.5: Mandatory audit trail
-- - §3.6: Multiple invoices per PO, partial payments
-- ===========================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- -------------------------------------------
-- Table: ap_invoices
-- Supplier invoices linked to PO/GR
-- IMMUTABLE after Approved status
-- -------------------------------------------
CREATE TABLE IF NOT EXISTS `ap_invoices` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `invoice_no` VARCHAR(50) NOT NULL UNIQUE COMMENT 'System generated: API-YYYY-NNNN',
    `supplier_invoice_no` VARCHAR(100) NULL COMMENT 'Supplier original invoice number',
    `supplier_id` INT UNSIGNED NOT NULL,
    `po_id` INT UNSIGNED NULL COMMENT 'Link to Purchase Order',
    `gr_id` INT UNSIGNED NULL COMMENT 'Link to Goods Receipt',
    `job_id` INT UNSIGNED NULL COMMENT 'Link to Job for costing',
    
    -- Dates
    `invoice_date` DATE NOT NULL,
    `received_date` DATE NULL COMMENT 'Date invoice received',
    `due_date` DATE NOT NULL,
    
    -- Amounts (THB only for now)
    `subtotal` DECIMAL(14,2) NOT NULL DEFAULT 0,
    `tax_rate` DECIMAL(5,2) NOT NULL DEFAULT 7.00 COMMENT 'VAT %',
    `tax_amount` DECIMAL(14,2) NOT NULL DEFAULT 0,
    `withholding_rate` DECIMAL(5,2) NOT NULL DEFAULT 0 COMMENT 'WHT %',
    `withholding_amount` DECIMAL(14,2) NOT NULL DEFAULT 0,
    `total_amount` DECIMAL(14,2) NOT NULL DEFAULT 0,
    `paid_amount` DECIMAL(14,2) NOT NULL DEFAULT 0,
    
    -- Status workflow: Draft -> Submitted -> Approved -> Partial/Paid -> Voided
    `status` ENUM('Draft', 'Submitted', 'Approved', 'Partial', 'Paid', 'Voided') NOT NULL DEFAULT 'Draft',
    
    -- 3-Way Matching
    `po_amount` DECIMAL(14,2) NULL COMMENT 'PO total for matching',
    `gr_amount` DECIMAL(14,2) NULL COMMENT 'GR total for matching',
    `matching_status` ENUM('Pending', 'Matched', 'Variance', 'Override') DEFAULT 'Pending',
    `matching_notes` TEXT NULL,
    
    -- Void tracking
    `voided_at` DATETIME NULL,
    `voided_by` INT UNSIGNED NULL,
    `void_reason` TEXT NULL,
    `voided_by_debit_note_id` INT UNSIGNED NULL,
    
    -- Notes
    `notes` TEXT NULL,
    
    -- Audit
    `created_by` INT UNSIGNED NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `submitted_at` DATETIME NULL,
    `submitted_by` INT UNSIGNED NULL,
    `approved_at` DATETIME NULL,
    `approved_by` INT UNSIGNED NULL,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    PRIMARY KEY (`id`),
    INDEX `idx_inv_no` (`invoice_no`),
    INDEX `idx_supplier_inv` (`supplier_invoice_no`),
    INDEX `idx_supplier` (`supplier_id`),
    INDEX `idx_po` (`po_id`),
    INDEX `idx_gr` (`gr_id`),
    INDEX `idx_job` (`job_id`),
    INDEX `idx_status` (`status`),
    INDEX `idx_due_date` (`due_date`),
    INDEX `idx_invoice_date` (`invoice_date`),
    
    CONSTRAINT `fk_api_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers`(`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_api_po` FOREIGN KEY (`po_id`) REFERENCES `purchase_orders`(`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_api_gr` FOREIGN KEY (`gr_id`) REFERENCES `goods_receipts`(`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_api_job` FOREIGN KEY (`job_id`) REFERENCES `jobs`(`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_api_created` FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_api_submitted` FOREIGN KEY (`submitted_by`) REFERENCES `users`(`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_api_approved` FOREIGN KEY (`approved_by`) REFERENCES `users`(`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_api_voided` FOREIGN KEY (`voided_by`) REFERENCES `users`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='AP Invoices - Immutable after Approved (agents.md §1.2)';

-- -------------------------------------------
-- Table: ap_invoice_lines
-- Line items for AP invoices
-- -------------------------------------------
CREATE TABLE IF NOT EXISTS `ap_invoice_lines` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `invoice_id` INT UNSIGNED NOT NULL,
    `line_no` INT NOT NULL,
    `po_item_id` INT UNSIGNED NULL COMMENT 'Link to PO item',
    `gr_item_id` INT UNSIGNED NULL COMMENT 'Link to GR item',
    `item_id` INT UNSIGNED NULL COMMENT 'Link to item master',
    `description` VARCHAR(500) NOT NULL,
    `quantity` DECIMAL(12,2) NOT NULL DEFAULT 1,
    `unit` VARCHAR(20) NULL,
    `unit_price` DECIMAL(14,2) NOT NULL,
    `amount` DECIMAL(14,2) NOT NULL,
    `cost_type` ENUM('Material', 'Manpower', 'Transport', 'Outsource', 'Other') DEFAULT 'Material',
    `notes` TEXT NULL,
    
    PRIMARY KEY (`id`),
    INDEX `idx_invoice` (`invoice_id`),
    INDEX `idx_po_item` (`po_item_id`),
    INDEX `idx_gr_item` (`gr_item_id`),
    INDEX `idx_item` (`item_id`),
    
    CONSTRAINT `fk_apil_invoice` FOREIGN KEY (`invoice_id`) REFERENCES `ap_invoices`(`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_apil_po_item` FOREIGN KEY (`po_item_id`) REFERENCES `po_items`(`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_apil_item` FOREIGN KEY (`item_id`) REFERENCES `items`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='AP Invoice Lines - Immutable after parent Approved';

-- -------------------------------------------
-- Table: ap_payments
-- Payments to suppliers (with approval workflow)
-- APPEND-ONLY, reversal pattern for corrections
-- -------------------------------------------
CREATE TABLE IF NOT EXISTS `ap_payments` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `payment_no` VARCHAR(50) NOT NULL UNIQUE COMMENT 'System generated: APP-YYYY-NNNN',
    `invoice_id` INT UNSIGNED NOT NULL,
    `amount` DECIMAL(14,2) NOT NULL,
    `payment_method` ENUM('Cash', 'Bank Transfer', 'Cheque', 'Credit Card', 'Other') NOT NULL,
    `payment_date` DATE NOT NULL,
    `reference_no` VARCHAR(100) NULL COMMENT 'Bank ref, cheque no, etc.',
    `bank_account` VARCHAR(100) NULL COMMENT 'Paid from account',
    
    -- Approval workflow: Pending -> Approved -> Posted -> Reversed
    `status` ENUM('Pending', 'Approved', 'Posted', 'Reversed') NOT NULL DEFAULT 'Pending',
    
    -- Approval tracking
    `requested_by` INT UNSIGNED NOT NULL,
    `requested_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `approved_by` INT UNSIGNED NULL,
    `approved_at` DATETIME NULL,
    `posted_by` INT UNSIGNED NULL,
    `posted_at` DATETIME NULL,
    
    -- Reversal tracking (agents.md §1.3)
    `reverse_of_id` INT UNSIGNED NULL COMMENT 'If reversal, points to original payment',
    `reversed_by_id` INT UNSIGNED NULL COMMENT 'If reversed, points to reversal entry',
    `reversal_reason` VARCHAR(500) NULL,
    `reversed_at` DATETIME NULL,
    `reversed_by` INT UNSIGNED NULL,
    
    -- Notes
    `notes` TEXT NULL,
    
    -- Audit
    `created_by` INT UNSIGNED NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    PRIMARY KEY (`id`),
    INDEX `idx_pay_no` (`payment_no`),
    INDEX `idx_invoice` (`invoice_id`),
    INDEX `idx_status` (`status`),
    INDEX `idx_payment_date` (`payment_date`),
    INDEX `idx_reverse` (`reverse_of_id`),
    
    CONSTRAINT `fk_app_invoice` FOREIGN KEY (`invoice_id`) REFERENCES `ap_invoices`(`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_app_requested` FOREIGN KEY (`requested_by`) REFERENCES `users`(`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_app_approved` FOREIGN KEY (`approved_by`) REFERENCES `users`(`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_app_posted` FOREIGN KEY (`posted_by`) REFERENCES `users`(`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_app_created` FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_app_reverse` FOREIGN KEY (`reverse_of_id`) REFERENCES `ap_payments`(`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_app_reversed_by` FOREIGN KEY (`reversed_by_id`) REFERENCES `ap_payments`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='AP Payments - Append-only, reversals via new entry (agents.md §1.3)';

-- -------------------------------------------
-- Table: ap_debit_notes
-- For invoice corrections/voids
-- -------------------------------------------
CREATE TABLE IF NOT EXISTS `ap_debit_notes` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `debit_note_no` VARCHAR(50) NOT NULL UNIQUE COMMENT 'System generated: ADN-YYYY-NNNN',
    `invoice_id` INT UNSIGNED NOT NULL,
    `amount` DECIMAL(14,2) NOT NULL,
    `reason` VARCHAR(500) NOT NULL,
    `status` ENUM('Draft', 'Issued') NOT NULL DEFAULT 'Draft',
    
    -- Audit
    `created_by` INT UNSIGNED NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `issued_at` DATETIME NULL,
    `issued_by` INT UNSIGNED NULL,
    
    PRIMARY KEY (`id`),
    INDEX `idx_dn_no` (`debit_note_no`),
    INDEX `idx_invoice` (`invoice_id`),
    
    CONSTRAINT `fk_adn_invoice` FOREIGN KEY (`invoice_id`) REFERENCES `ap_invoices`(`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_adn_created` FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_adn_issued` FOREIGN KEY (`issued_by`) REFERENCES `users`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='AP Debit Notes for invoice corrections (agents.md §1.3)';

SET FOREIGN_KEY_CHECKS = 1;

-- ===========================================
-- Document Number Settings for AP
-- ===========================================
INSERT INTO `doc_number_settings` (`doc_type`, `prefix`, `current_year`, `next_number`, `padding`, `reset_yearly`)
VALUES 
    ('API', 'API', 2026, 1, 4, 1),
    ('APP', 'APP', 2026, 1, 4, 1),
    ('ADN', 'ADN', 2026, 1, 4, 1)
ON DUPLICATE KEY UPDATE `doc_type` = `doc_type`;
