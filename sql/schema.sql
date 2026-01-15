-- ===========================================
-- ERP v2 Database Schema - Phase 1: Foundation
-- ===========================================
-- Following agents.md rules:
-- - No DELETE/TRUNCATE on financial/stock tables
-- - Append-only audit_logs
-- - ON DELETE RESTRICT for financial relations
-- ===========================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- -------------------------------------------
-- Table: roles
-- Fixed set of roles per requirements
-- -------------------------------------------
CREATE TABLE IF NOT EXISTS `roles` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `code` VARCHAR(10) NOT NULL UNIQUE COMMENT 'ADM, SAL, PLN, PUR, HR, WH, ACC, MGR',
    `name` VARCHAR(50) NOT NULL,
    `description` TEXT,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------
-- Table: users
-- -------------------------------------------
CREATE TABLE IF NOT EXISTS `users` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `username` VARCHAR(50) NOT NULL UNIQUE,
    `email` VARCHAR(100) NOT NULL UNIQUE,
    `password_hash` VARCHAR(255) NOT NULL,
    `full_name` VARCHAR(100) NOT NULL,
    `phone` VARCHAR(20),
    `is_active` TINYINT(1) DEFAULT 1,
    `last_login_at` TIMESTAMP NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_username` (`username`),
    INDEX `idx_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------
-- Table: user_roles (M:N mapping)
-- -------------------------------------------
CREATE TABLE IF NOT EXISTS `user_roles` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED NOT NULL,
    `role_id` INT UNSIGNED NOT NULL,
    `assigned_by` INT UNSIGNED,
    `assigned_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_user_role` (`user_id`, `role_id`),
    CONSTRAINT `fk_ur_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_ur_role` FOREIGN KEY (`role_id`) REFERENCES `roles`(`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_ur_assigned` FOREIGN KEY (`assigned_by`) REFERENCES `users`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------
-- Table: permissions
-- -------------------------------------------
CREATE TABLE IF NOT EXISTS `permissions` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `code` VARCHAR(50) NOT NULL UNIQUE,
    `name` VARCHAR(100) NOT NULL,
    `entity_type` VARCHAR(30) NOT NULL COMMENT 'JOB, PO, PR, GR, INVOICE, etc.',
    `action` ENUM('view','create','edit','approve','void','cancel','extend','dispatch','close','export','print') NOT NULL,
    `description` TEXT,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_entity_action` (`entity_type`, `action`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------
-- Table: role_permissions (M:N mapping)
-- Includes status-based restrictions
-- -------------------------------------------
CREATE TABLE IF NOT EXISTS `role_permissions` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `role_id` INT UNSIGNED NOT NULL,
    `permission_id` INT UNSIGNED NOT NULL,
    `entity_status` VARCHAR(30) DEFAULT NULL COMMENT 'NULL means all statuses',
    `is_granted` TINYINT(1) DEFAULT 1,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_role_perm_status` (`role_id`, `permission_id`, `entity_status`),
    CONSTRAINT `fk_rp_role` FOREIGN KEY (`role_id`) REFERENCES `roles`(`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_rp_perm` FOREIGN KEY (`permission_id`) REFERENCES `permissions`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------
-- Table: custom_permissions
-- Admin-assigned overrides per user
-- -------------------------------------------
CREATE TABLE IF NOT EXISTS `custom_permissions` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED NOT NULL,
    `permission_id` INT UNSIGNED NOT NULL,
    `entity_status` VARCHAR(30) DEFAULT NULL,
    `is_granted` TINYINT(1) DEFAULT 1 COMMENT '1=grant, 0=revoke',
    `reason` TEXT,
    `assigned_by` INT UNSIGNED NOT NULL,
    `expires_at` TIMESTAMP NULL COMMENT 'NULL means no expiry',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_user_perm_status` (`user_id`, `permission_id`, `entity_status`),
    CONSTRAINT `fk_cp_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_cp_perm` FOREIGN KEY (`permission_id`) REFERENCES `permissions`(`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_cp_assigned` FOREIGN KEY (`assigned_by`) REFERENCES `users`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------
-- Table: audit_logs
-- APPEND-ONLY - NO UPDATE/DELETE ALLOWED
-- -------------------------------------------
CREATE TABLE IF NOT EXISTS `audit_logs` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED NOT NULL,
    `user_role` VARCHAR(10) COMMENT 'Role at time of action',
    `request_id` VARCHAR(36) NOT NULL COMMENT 'UUID trace ID for request',
    `action_name` VARCHAR(50) NOT NULL COMMENT 'create/update/approve/void/login/etc',
    `entity_type` VARCHAR(30) NOT NULL COMMENT 'JOB/PO/USER/etc',
    `entity_id` BIGINT UNSIGNED COMMENT 'ID of affected entity',
    `old_value` JSON COMMENT 'Previous values (diff)',
    `new_value` JSON COMMENT 'New values (diff)',
    `reason` TEXT COMMENT 'Required for cancel/void/override',
    `ip_address` VARCHAR(45),
    `user_agent` VARCHAR(255),
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_user` (`user_id`),
    INDEX `idx_entity` (`entity_type`, `entity_id`),
    INDEX `idx_action` (`action_name`),
    INDEX `idx_request` (`request_id`),
    INDEX `idx_created` (`created_at`),
    CONSTRAINT `fk_al_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------
-- Table: sessions
-- Active user sessions
-- -------------------------------------------
CREATE TABLE IF NOT EXISTS `sessions` (
    `id` VARCHAR(128) NOT NULL,
    `user_id` INT UNSIGNED NOT NULL,
    `ip_address` VARCHAR(45),
    `user_agent` VARCHAR(255),
    `payload` TEXT,
    `last_activity` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_user` (`user_id`),
    INDEX `idx_last_activity` (`last_activity`),
    CONSTRAINT `fk_sess_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------
-- Table: line_bindings
-- LINE OA user bindings
-- -------------------------------------------
CREATE TABLE IF NOT EXISTS `line_bindings` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED NOT NULL,
    `line_user_id` VARCHAR(50) NOT NULL UNIQUE COMMENT 'LINE userId',
    `display_name` VARCHAR(100),
    `is_active` TINYINT(1) DEFAULT 1,
    `bound_by` INT UNSIGNED NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_user` (`user_id`),
    INDEX `idx_line_user` (`line_user_id`),
    CONSTRAINT `fk_lb_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_lb_bound` FOREIGN KEY (`bound_by`) REFERENCES `users`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------
-- Table: doc_number_settings
-- Document numbering configuration
-- -------------------------------------------
CREATE TABLE IF NOT EXISTS `doc_number_settings` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `doc_type` VARCHAR(20) NOT NULL UNIQUE COMMENT 'JOB, PO, PR, GR, INV, etc.',
    `prefix` VARCHAR(10) NOT NULL,
    `current_year` YEAR NOT NULL,
    `next_number` INT UNSIGNED NOT NULL DEFAULT 1,
    `padding` TINYINT UNSIGNED DEFAULT 5 COMMENT 'Digit padding (e.g. 5 = 00001)',
    `reset_yearly` TINYINT(1) DEFAULT 1,
    `updated_by` INT UNSIGNED,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    CONSTRAINT `fk_dns_updated` FOREIGN KEY (`updated_by`) REFERENCES `users`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------
-- Table: doc_number_log
-- History of generated document numbers (immutable)
-- -------------------------------------------
CREATE TABLE IF NOT EXISTS `doc_number_log` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `doc_type` VARCHAR(20) NOT NULL,
    `doc_number` VARCHAR(30) NOT NULL,
    `entity_id` BIGINT UNSIGNED COMMENT 'ID of document that received this number',
    `generated_by` INT UNSIGNED NOT NULL,
    `generated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_doc_number` (`doc_type`, `doc_number`),
    INDEX `idx_entity` (`entity_id`),
    CONSTRAINT `fk_dnl_user` FOREIGN KEY (`generated_by`) REFERENCES `users`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- ===========================================
-- SEED DATA
-- ===========================================

-- Insert roles (fixed set)
INSERT INTO `roles` (`code`, `name`, `description`) VALUES
('ADM', 'Admin', 'System administrator with full access'),
('SAL', 'Sale', 'Sales team member'),
('PLN', 'Planner', 'Job planning and resource allocation'),
('PUR', 'Purchase', 'Procurement and purchasing'),
('HR', 'HRM', 'Human resource management'),
('WH', 'Warehouse', 'Warehouse and stock management'),
('ACC', 'Accountant', 'Accounting and finance'),
('MGR', 'Manager', 'Management with approval authority');

-- Insert default admin user (password: admin123)
INSERT INTO `users` (`username`, `email`, `password_hash`, `full_name`, `is_active`) VALUES
('admin', 'admin@erp.local', '$2y$12$sWX8RlylVoPZ.7egctNp5uuMf3jjMBMT3ekf5L.Ks9AzOH4CcDSZu', 'System Administrator', 1);

-- Assign admin role to admin user
INSERT INTO `user_roles` (`user_id`, `role_id`, `assigned_by`) VALUES
(1, 1, 1);

-- Insert document number settings
INSERT INTO `doc_number_settings` (`doc_type`, `prefix`, `current_year`, `next_number`, `padding`, `reset_yearly`) VALUES
('JOB', 'JOB-', 2026, 1, 5, 1),
('PO', 'PO-', 2026, 1, 5, 1),
('PR', 'PR-', 2026, 1, 5, 1),
('GR', 'GR-', 2026, 1, 5, 1),
('DN', 'DN-', 2026, 1, 5, 1),
('RN', 'RN-', 2026, 1, 5, 1),
('INV', 'INV-', 2026, 1, 5, 1),
('PAY', 'PAY-', 2026, 1, 5, 1);

-- Insert base permissions for JOB entity (example)
INSERT INTO `permissions` (`code`, `name`, `entity_type`, `action`, `description`) VALUES
-- JOB permissions
('JOB_VIEW', 'View Job', 'JOB', 'view', 'View job details'),
('JOB_CREATE', 'Create Job', 'JOB', 'create', 'Create new job'),
('JOB_EDIT', 'Edit Job', 'JOB', 'edit', 'Edit job details'),
('JOB_APPROVE', 'Approve Job', 'JOB', 'approve', 'Approve job'),
('JOB_VOID', 'Void Job', 'JOB', 'void', 'Void/cancel job'),
('JOB_EXTEND', 'Extend Job', 'JOB', 'extend', 'Request job extension'),
('JOB_DISPATCH', 'Dispatch Job', 'JOB', 'dispatch', 'Dispatch job resources'),
('JOB_CLOSE', 'Close Job', 'JOB', 'close', 'Close job'),
-- PR permissions
('PR_VIEW', 'View PR', 'PR', 'view', 'View purchase request'),
('PR_CREATE', 'Create PR', 'PR', 'create', 'Create purchase request'),
('PR_APPROVE', 'Approve PR', 'PR', 'approve', 'Approve purchase request'),
-- PO permissions
('PO_VIEW', 'View PO', 'PO', 'view', 'View purchase order'),
('PO_CREATE', 'Create PO', 'PO', 'create', 'Create purchase order'),
('PO_APPROVE', 'Approve PO', 'PO', 'approve', 'Approve purchase order'),
('PO_VOID', 'Void PO', 'PO', 'void', 'Void purchase order'),
-- User/Admin permissions
('USER_VIEW', 'View Users', 'USER', 'view', 'View user list'),
('USER_CREATE', 'Create User', 'USER', 'create', 'Create new user'),
('USER_EDIT', 'Edit User', 'USER', 'edit', 'Edit user details'),
('PERM_MANAGE', 'Manage Permissions', 'PERMISSION', 'edit', 'Manage permissions and roles');

-- Grant all permissions to Admin role
INSERT INTO `role_permissions` (`role_id`, `permission_id`)
SELECT 1, id FROM `permissions`;

-- Grant all permissions to Manager role
INSERT INTO `role_permissions` (`role_id`, `permission_id`)
SELECT 8, id FROM `permissions`;
