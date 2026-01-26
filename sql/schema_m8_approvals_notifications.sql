-- ===========================================
-- ERP v2 Database Schema - M8: Approvals & Notifications
-- ===========================================
-- Following agents.md §3 (Approval Matrix) and §4 (Notifications):
-- - Approval logs append-only
-- - In-app notifications
-- - LINE OA integration audit
-- ===========================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- -------------------------------------------
-- Table: approval_requests
-- Central approval request tracking
-- -------------------------------------------
CREATE TABLE IF NOT EXISTS `approval_requests` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `request_type` ENUM(
        'purchase_threshold',
        'stock_adjust',
        'compliance_override',
        'shortage_override',
        'timesheet_exception',
        'job_extension',
        'job_void',
        'other'
    ) NOT NULL,
    `entity_type` VARCHAR(30) NOT NULL COMMENT 'PO, STOCK_ADJUST, PLAN, TIMESHEET, JOB',
    `entity_id` INT UNSIGNED NOT NULL,
    `job_id` INT UNSIGNED NULL COMMENT 'Related job if applicable',
    -- Request details
    `title` VARCHAR(255) NOT NULL,
    `description` TEXT NOT NULL,
    `impact_summary` TEXT NULL COMMENT 'Cost/stock/time impact',
    `amount` DECIMAL(15,2) NULL COMMENT 'For purchase threshold',
    -- Status
    `status` ENUM('Pending', 'Approved', 'Rejected', 'Cancelled') NOT NULL DEFAULT 'Pending',
    -- Requester
    `requested_by` INT UNSIGNED NOT NULL,
    `requested_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    -- Approver
    `approved_by` INT UNSIGNED NULL,
    `approved_at` DATETIME NULL,
    `approval_notes` TEXT NULL,
    -- Rejection
    `rejected_by` INT UNSIGNED NULL,
    `rejected_at` DATETIME NULL,
    `rejection_reason` TEXT NULL,
    -- Audit
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_type` (`request_type`),
    INDEX `idx_entity` (`entity_type`, `entity_id`),
    INDEX `idx_job` (`job_id`),
    INDEX `idx_status` (`status`),
    INDEX `idx_requested_by` (`requested_by`),
    CONSTRAINT `fk_ar_requested` FOREIGN KEY (`requested_by`) REFERENCES `users`(`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_ar_approved` FOREIGN KEY (`approved_by`) REFERENCES `users`(`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_ar_job` FOREIGN KEY (`job_id`) REFERENCES `jobs`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Central approval request tracking (agents.md §3)';

-- -------------------------------------------
-- Table: approval_logs
-- APPEND-ONLY - immutable approval history
-- -------------------------------------------
CREATE TABLE IF NOT EXISTS `approval_logs` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `approval_request_id` INT UNSIGNED NULL COMMENT 'Link to approval_requests if applicable',
    `request_type` VARCHAR(50) NOT NULL,
    `entity_type` VARCHAR(30) NOT NULL,
    `entity_id` INT UNSIGNED NOT NULL,
    `job_id` INT UNSIGNED NULL,
    -- Action details
    `action` ENUM('request', 'approve', 'reject', 'cancel', 'escalate') NOT NULL,
    `actor_id` INT UNSIGNED NOT NULL,
    `actor_role` VARCHAR(20) NULL,
    -- Details at time of action
    `title` VARCHAR(255) NOT NULL,
    `description` TEXT NULL,
    `amount` DECIMAL(15,2) NULL,
    `impact_summary` TEXT NULL,
    `notes` TEXT NULL,
    -- Immutable timestamp
    `performed_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_request` (`approval_request_id`),
    INDEX `idx_type` (`request_type`),
    INDEX `idx_entity` (`entity_type`, `entity_id`),
    INDEX `idx_actor` (`actor_id`),
    INDEX `idx_performed` (`performed_at`),
    CONSTRAINT `fk_al_request` FOREIGN KEY (`approval_request_id`) REFERENCES `approval_requests`(`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_al_actor` FOREIGN KEY (`actor_id`) REFERENCES `users`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Append-only approval logs (agents.md §1.4)';

-- -------------------------------------------
-- Table: notifications
-- In-app notifications
-- -------------------------------------------
CREATE TABLE IF NOT EXISTS `notifications` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED NOT NULL COMMENT 'Recipient',
    `type` ENUM(
        'approval_request',
        'approval_result',
        'job_status',
        'dispatch_alert',
        'shortage_alert',
        'compliance_alert',
        'timesheet_pending',
        'po_overdue',
        'system',
        'other'
    ) NOT NULL,
    `priority` ENUM('low', 'normal', 'high', 'urgent') NOT NULL DEFAULT 'normal',
    -- Content
    `title` VARCHAR(255) NOT NULL,
    `message` TEXT NOT NULL,
    `action_url` VARCHAR(500) NULL COMMENT 'Link to related page',
    -- Reference
    `entity_type` VARCHAR(30) NULL,
    `entity_id` INT UNSIGNED NULL,
    -- Status
    `is_read` TINYINT(1) DEFAULT 0,
    `read_at` DATETIME NULL,
    -- Audit
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_user` (`user_id`),
    INDEX `idx_type` (`type`),
    INDEX `idx_read` (`is_read`),
    INDEX `idx_created` (`created_at`),
    INDEX `idx_entity` (`entity_type`, `entity_id`),
    CONSTRAINT `fk_notif_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='In-app notifications (agents.md §4)';

-- -------------------------------------------
-- Table: notification_preferences
-- User notification settings
-- -------------------------------------------
CREATE TABLE IF NOT EXISTS `notification_preferences` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED NOT NULL,
    `notification_type` VARCHAR(50) NOT NULL,
    `in_app` TINYINT(1) DEFAULT 1,
    `line_oa` TINYINT(1) DEFAULT 0,
    `email` TINYINT(1) DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_user_type` (`user_id`, `notification_type`),
    CONSTRAINT `fk_np_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------
-- Table: line_notification_log
-- Audit log for LINE OA notifications sent
-- -------------------------------------------
CREATE TABLE IF NOT EXISTS `line_notification_log` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED NOT NULL,
    `line_user_id` VARCHAR(50) NOT NULL,
    `notification_id` INT UNSIGNED NULL,
    `message_type` VARCHAR(50) NOT NULL,
    `message_content` TEXT NOT NULL,
    `status` ENUM('sent', 'failed', 'pending') NOT NULL DEFAULT 'pending',
    `error_message` TEXT NULL,
    `sent_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_user` (`user_id`),
    INDEX `idx_line_user` (`line_user_id`),
    INDEX `idx_notification` (`notification_id`),
    INDEX `idx_status` (`status`),
    INDEX `idx_sent` (`sent_at`),
    CONSTRAINT `fk_lnl_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_lnl_notif` FOREIGN KEY (`notification_id`) REFERENCES `notifications`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='LINE OA notification audit (agents.md §4)';

SET FOREIGN_KEY_CHECKS = 1;

-- ===========================================
-- Permissions for Approvals
-- ===========================================
INSERT INTO `permissions` (`code`, `name`, `entity_type`, `action`, `description`) VALUES
('APPROVAL_VIEW', 'View Approvals', 'APPROVAL', 'view', 'View approval requests'),
('APPROVAL_APPROVE', 'Approve Requests', 'APPROVAL', 'approve', 'Approve/reject approval requests')
ON DUPLICATE KEY UPDATE `code` = `code`;

-- Grant approval permissions
INSERT INTO `role_permissions` (`role_id`, `permission_id`)
SELECT r.id, p.id FROM roles r, permissions p 
WHERE r.code IN ('ADM', 'MGR') AND p.entity_type = 'APPROVAL'
ON DUPLICATE KEY UPDATE `role_id` = `role_id`;
