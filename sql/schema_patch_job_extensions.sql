-- Schema patch: Job Extensions table
-- ERP v2 - Extension system for changes after Planned status (lockpoint)

CREATE TABLE IF NOT EXISTS job_extensions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    job_id INT UNSIGNED NOT NULL,
    extension_type ENUM('date_extension', 'scope_change', 'budget_increase', 'resource_change', 'other') NOT NULL,
    reason TEXT NOT NULL,
    requested_changes TEXT NOT NULL,
    new_end_date DATE NULL,
    additional_budget DECIMAL(15,2) DEFAULT 0,
    status ENUM('Pending', 'Approved', 'Rejected') NOT NULL DEFAULT 'Pending',
    requested_by INT UNSIGNED NOT NULL,
    approved_by INT UNSIGNED NULL,
    approved_at DATETIME NULL,
    approval_notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (job_id) REFERENCES jobs(id),
    FOREIGN KEY (requested_by) REFERENCES users(id),
    FOREIGN KEY (approved_by) REFERENCES users(id),
    
    INDEX idx_job_extensions_job (job_id),
    INDEX idx_job_extensions_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
