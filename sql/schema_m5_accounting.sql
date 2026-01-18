-- M5: Accounting AR Schema
-- ERP v2 - Immutable Invoices & Payments (NO DELETE allowed per agents.md §1.1)

-- AR Invoices (APPEND-ONLY, immutability enforced)
CREATE TABLE IF NOT EXISTS `ar_invoices` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `invoice_no` VARCHAR(50) NOT NULL UNIQUE,
    `job_id` INT UNSIGNED NOT NULL,
    `customer_id` INT UNSIGNED NOT NULL,
    `invoice_date` DATE NOT NULL,
    `due_date` DATE NOT NULL,
    `subtotal` DECIMAL(14,2) NOT NULL DEFAULT 0,
    `tax_rate` DECIMAL(5,2) NOT NULL DEFAULT 7.00 COMMENT 'VAT %',
    `tax_amount` DECIMAL(14,2) NOT NULL DEFAULT 0,
    `withholding_rate` DECIMAL(5,2) NOT NULL DEFAULT 0 COMMENT 'WHT %',
    `withholding_amount` DECIMAL(14,2) NOT NULL DEFAULT 0,
    `total_amount` DECIMAL(14,2) NOT NULL DEFAULT 0,
    `paid_amount` DECIMAL(14,2) NOT NULL DEFAULT 0,
    `status` ENUM('Draft', 'Issued', 'Partial', 'Paid', 'Voided') NOT NULL DEFAULT 'Draft',
    `voided_by_credit_note_id` INT UNSIGNED NULL COMMENT 'If voided, reference to credit note',
    `notes` TEXT NULL,
    `created_by` INT UNSIGNED NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `issued_at` DATETIME NULL,
    `issued_by` INT UNSIGNED NULL,
    
    INDEX `idx_inv_no` (`invoice_no`),
    INDEX `idx_inv_job` (`job_id`),
    INDEX `idx_inv_customer` (`customer_id`),
    INDEX `idx_inv_status` (`status`),
    INDEX `idx_inv_date` (`invoice_date`),
    
    CONSTRAINT `fk_inv_job` FOREIGN KEY (`job_id`) REFERENCES `jobs`(`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_inv_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers`(`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_inv_created` FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_inv_issued` FOREIGN KEY (`issued_by`) REFERENCES `users`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='AR Invoices - Immutable after Issued (agents.md §1.2)';

-- AR Invoice Lines (APPEND-ONLY)
CREATE TABLE IF NOT EXISTS `ar_invoice_lines` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `invoice_id` INT UNSIGNED NOT NULL,
    `line_no` INT NOT NULL,
    `description` VARCHAR(500) NOT NULL,
    `quantity` DECIMAL(12,2) NOT NULL DEFAULT 1,
    `unit` VARCHAR(20) NULL,
    `unit_price` DECIMAL(14,2) NOT NULL,
    `amount` DECIMAL(14,2) NOT NULL,
    `notes` TEXT NULL,
    
    INDEX `idx_invl_invoice` (`invoice_id`),
    
    CONSTRAINT `fk_invl_invoice` FOREIGN KEY (`invoice_id`) REFERENCES `ar_invoices`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='AR Invoice Lines - Immutable after parent Issued';

-- Payments (APPEND-ONLY, reversal pattern for corrections)
CREATE TABLE IF NOT EXISTS `payments` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `payment_no` VARCHAR(50) NOT NULL UNIQUE,
    `invoice_id` INT UNSIGNED NOT NULL,
    `amount` DECIMAL(14,2) NOT NULL,
    `payment_method` ENUM('Cash', 'Bank Transfer', 'Cheque', 'Credit Card', 'Other') NOT NULL,
    `payment_date` DATE NOT NULL,
    `reference_no` VARCHAR(100) NULL COMMENT 'Bank ref, cheque no, etc.',
    `status` ENUM('Posted', 'Reversed') NOT NULL DEFAULT 'Posted',
    `reverse_of_id` INT UNSIGNED NULL COMMENT 'If reversal, points to original payment',
    `reversed_by_id` INT UNSIGNED NULL COMMENT 'If reversed, points to reversal entry',
    `reversal_reason` VARCHAR(500) NULL,
    `notes` TEXT NULL,
    `created_by` INT UNSIGNED NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    INDEX `idx_pay_no` (`payment_no`),
    INDEX `idx_pay_invoice` (`invoice_id`),
    INDEX `idx_pay_status` (`status`),
    INDEX `idx_pay_date` (`payment_date`),
    INDEX `idx_pay_reverse` (`reverse_of_id`),
    
    CONSTRAINT `fk_pay_invoice` FOREIGN KEY (`invoice_id`) REFERENCES `ar_invoices`(`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_pay_user` FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_pay_reverse` FOREIGN KEY (`reverse_of_id`) REFERENCES `payments`(`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_pay_reversed_by` FOREIGN KEY (`reversed_by_id`) REFERENCES `payments`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Payments - Append-only, reversals via new entry (agents.md §1.3)';

-- Credit Notes (for invoice corrections)
CREATE TABLE IF NOT EXISTS `ar_credit_notes` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `credit_note_no` VARCHAR(50) NOT NULL UNIQUE,
    `invoice_id` INT UNSIGNED NOT NULL,
    `amount` DECIMAL(14,2) NOT NULL,
    `reason` VARCHAR(500) NOT NULL,
    `status` ENUM('Draft', 'Issued') NOT NULL DEFAULT 'Draft',
    `created_by` INT UNSIGNED NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `issued_at` DATETIME NULL,
    `issued_by` INT UNSIGNED NULL,
    
    INDEX `idx_cn_invoice` (`invoice_id`),
    
    CONSTRAINT `fk_cn_invoice` FOREIGN KEY (`invoice_id`) REFERENCES `ar_invoices`(`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_cn_created` FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Credit Notes for invoice corrections (agents.md §1.3)';
