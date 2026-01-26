-- ===========================================
-- ERP v2 - Dashboard Configuration Schema
-- ===========================================
-- Stores per-role dashboard widget visibility settings
-- ===========================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- -------------------------------------------
-- Table: dashboard_widgets
-- Master list of available widgets
-- -------------------------------------------
CREATE TABLE IF NOT EXISTS `dashboard_widgets` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `code` VARCHAR(50) NOT NULL UNIQUE COMMENT 'Widget unique code',
    `name` VARCHAR(100) NOT NULL,
    `description` VARCHAR(255) NULL,
    `category` ENUM('stats', 'table', 'chart', 'action', 'timeline') NOT NULL DEFAULT 'stats',
    `icon` VARCHAR(50) DEFAULT 'bi-grid',
    `icon_bg_color` VARCHAR(20) DEFAULT 'primary',
    `default_size` ENUM('S', 'M', 'L') DEFAULT 'S',
    `default_enabled` TINYINT(1) DEFAULT 1,
    `allowed_roles` JSON NULL COMMENT 'Array of role codes, null = all roles',
    `sort_order` INT DEFAULT 99,
    `is_active` TINYINT(1) DEFAULT 1,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_code` (`code`),
    INDEX `idx_category` (`category`),
    INDEX `idx_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Master list of dashboard widgets';

-- -------------------------------------------
-- Table: dashboard_role_config
-- Per-role widget configuration
-- -------------------------------------------
CREATE TABLE IF NOT EXISTS `dashboard_role_config` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `role_code` VARCHAR(10) NOT NULL,
    `widget_code` VARCHAR(50) NOT NULL,
    `is_enabled` TINYINT(1) DEFAULT 1,
    `size` ENUM('S', 'M', 'L') DEFAULT 'S',
    `position` INT DEFAULT 99,
    `custom_settings` JSON NULL COMMENT 'Widget-specific settings',
    `updated_by` INT UNSIGNED NULL,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_role_widget` (`role_code`, `widget_code`),
    INDEX `idx_role` (`role_code`),
    INDEX `idx_widget` (`widget_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Per-role dashboard widget configuration';

-- -------------------------------------------
-- Insert default widgets
-- -------------------------------------------
INSERT INTO `dashboard_widgets` (`code`, `name`, `description`, `category`, `icon`, `icon_bg_color`, `default_size`, `allowed_roles`, `sort_order`) VALUES
-- Stats widgets
('stat_total_jobs', 'Total Jobs', 'แสดงจำนวน Jobs ทั้งหมด', 'stats', 'bi-briefcase', 'primary', 'S', NULL, 1),
('stat_revenue', 'Revenue', 'ยอดรายได้เดือนนี้', 'stats', 'bi-currency-dollar', 'success', 'S', '["ADM","SAL","ACC","MGR"]', 2),
('stat_pending_approvals', 'Pending Approvals', 'รายการรออนุมัติ', 'stats', 'bi-hourglass-split', 'warning', 'S', '["ADM","MGR"]', 3),
('stat_active_users', 'Active Users', 'จำนวนผู้ใช้งานปัจจุบัน', 'stats', 'bi-people', 'info', 'S', '["ADM"]', 4),
('stat_jobs_awaiting_plan', 'Jobs Awaiting Plan', 'Jobs รอวางแผน', 'stats', 'bi-clipboard-check', 'warning', 'S', '["PLN"]', 5),
('stat_dispatches_today', 'Dispatches Today', 'Dispatch วันนี้', 'stats', 'bi-truck', 'info', 'S', '["PLN","WH"]', 6),
('stat_stock_items', 'Total Stock Items', 'จำนวนสินค้าในคลัง', 'stats', 'bi-box-seam', 'primary', 'S', '["WH"]', 7),
('stat_low_stock', 'Low Stock Alert', 'สินค้าใกล้หมด', 'stats', 'bi-exclamation-triangle', 'danger', 'S', '["WH","PUR"]', 8),
('stat_outstanding_ar', 'Outstanding AR', 'ลูกหนี้คงค้าง', 'stats', 'bi-cash-stack', 'warning', 'S', '["ACC","MGR"]', 9),
('stat_overdue_invoices', 'Overdue Invoices', 'ใบแจ้งหนี้เกินกำหนด', 'stats', 'bi-exclamation-circle', 'danger', 'S', '["ACC","MGR"]', 10),
('stat_total_people', 'Total People', 'บุคลากรทั้งหมด', 'stats', 'bi-people', 'primary', 'S', '["HR"]', 11),
('stat_timesheet_pending', 'Timesheet Pending', 'Timesheet รออนุมัติ', 'stats', 'bi-clock-history', 'warning', 'S', '["HR","MGR"]', 12),
('stat_pr_pending', 'PRs Pending', 'PR รอดำเนินการ', 'stats', 'bi-file-text', 'warning', 'S', '["PUR"]', 13),

-- Table widgets
('table_recent_jobs', 'Recent Jobs', 'รายการ Jobs ล่าสุด', 'table', 'bi-list-check', 'primary', 'L', NULL, 20),
('table_pending_approvals', 'Pending Approvals Table', 'ตารางรายการรออนุมัติ', 'table', 'bi-clock-history', 'warning', 'L', '["ADM","MGR"]', 21),
('table_my_jobs', 'My Active Jobs', 'Jobs ของฉัน', 'table', 'bi-briefcase', 'primary', 'L', '["SAL"]', 22),
('table_jobs_awaiting_plan', 'Jobs Awaiting Plan', 'Jobs รอวางแผน', 'table', 'bi-calendar-plus', 'info', 'M', '["PLN"]', 23),
('table_pending_gr', 'Pending GR', 'รอรับเข้า', 'table', 'bi-box-arrow-in-down', 'warning', 'M', '["WH"]', 24),
('table_pending_returns', 'Pending Returns', 'รอรับคืน', 'table', 'bi-box-arrow-up', 'info', 'M', '["WH"]', 25),
('table_ready_to_invoice', 'Ready to Invoice', 'พร้อมออก Invoice', 'table', 'bi-clipboard-check', 'info', 'M', '["ACC"]', 26),
('table_outstanding_invoices', 'Outstanding Invoices', 'ใบแจ้งหนี้ค้างชำระ', 'table', 'bi-receipt', 'warning', 'M', '["ACC"]', 27),
('table_approved_prs', 'Approved PRs', 'PR ที่อนุมัติแล้ว', 'table', 'bi-file-check', 'success', 'M', '["PUR"]', 28),
('table_pending_manpower', 'Pending Manpower Registration', 'แรงงานรอลงทะเบียน', 'table', 'bi-person-plus', 'warning', 'M', '["HR"]', 29),
('table_timesheet_approval', 'Timesheet Approval', 'Timesheet รออนุมัติ', 'table', 'bi-clock', 'info', 'M', '["HR"]', 30),

-- Chart widgets
('chart_jobs_trend', 'Jobs Trend', 'กราฟแนวโน้ม Jobs', 'chart', 'bi-graph-up', 'info', 'M', NULL, 40),
('chart_jobs_by_status', 'Jobs by Status', 'สัดส่วน Jobs ตาม Status', 'chart', 'bi-pie-chart', 'success', 'M', NULL, 41),
('chart_revenue_trend', 'Revenue Trend', 'กราฟแนวโน้มรายได้', 'chart', 'bi-bar-chart', 'success', 'M', '["ADM","SAL","ACC","MGR"]', 42),
('chart_users_by_role', 'Users by Role', 'ผู้ใช้งานแยกตาม Role', 'chart', 'bi-pie-chart', 'primary', 'M', '["ADM"]', 43),

-- Action widgets
('action_quick_actions', 'Quick Actions Panel', 'ปุ่มลัดสำหรับสร้างรายการ', 'action', 'bi-plus-circle', 'primary', 'S', NULL, 50),

-- Timeline widgets  
('timeline_activity', 'Activity Timeline', 'ประวัติกิจกรรมล่าสุด', 'timeline', 'bi-journal-text', 'secondary', 'M', NULL, 60),
('timeline_audit_logs', 'Recent Audit Logs', 'Audit Log ล่าสุด', 'timeline', 'bi-shield-check', 'info', 'M', '["ADM","MGR"]', 61)

ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);

-- -------------------------------------------
-- Insert default role configurations
-- -------------------------------------------
-- ADM gets all widgets enabled by default
INSERT INTO `dashboard_role_config` (`role_code`, `widget_code`, `is_enabled`, `size`, `position`)
SELECT 'ADM', `code`, 1, `default_size`, `sort_order` FROM `dashboard_widgets` WHERE `is_active` = 1
ON DUPLICATE KEY UPDATE `is_enabled` = 1;

-- MGR gets management-focused widgets
INSERT INTO `dashboard_role_config` (`role_code`, `widget_code`, `is_enabled`, `size`, `position`) VALUES
('MGR', 'stat_total_jobs', 1, 'S', 1),
('MGR', 'stat_revenue', 1, 'S', 2),
('MGR', 'stat_pending_approvals', 1, 'S', 3),
('MGR', 'table_pending_approvals', 1, 'L', 10),
('MGR', 'chart_revenue_trend', 1, 'M', 20),
('MGR', 'timeline_audit_logs', 1, 'M', 30)
ON DUPLICATE KEY UPDATE `is_enabled` = VALUES(`is_enabled`);

-- SAL gets sales-focused widgets
INSERT INTO `dashboard_role_config` (`role_code`, `widget_code`, `is_enabled`, `size`, `position`) VALUES
('SAL', 'stat_total_jobs', 1, 'S', 1),
('SAL', 'stat_revenue', 1, 'S', 2),
('SAL', 'table_my_jobs', 1, 'L', 10),
('SAL', 'action_quick_actions', 1, 'S', 5),
('SAL', 'chart_revenue_trend', 1, 'M', 20)
ON DUPLICATE KEY UPDATE `is_enabled` = VALUES(`is_enabled`);

-- PLN gets planning widgets
INSERT INTO `dashboard_role_config` (`role_code`, `widget_code`, `is_enabled`, `size`, `position`) VALUES
('PLN', 'stat_jobs_awaiting_plan', 1, 'S', 1),
('PLN', 'stat_dispatches_today', 1, 'S', 2),
('PLN', 'table_jobs_awaiting_plan', 1, 'M', 10),
('PLN', 'chart_jobs_trend', 1, 'M', 20),
('PLN', 'timeline_activity', 1, 'M', 30)
ON DUPLICATE KEY UPDATE `is_enabled` = VALUES(`is_enabled`);

-- WH gets warehouse widgets
INSERT INTO `dashboard_role_config` (`role_code`, `widget_code`, `is_enabled`, `size`, `position`) VALUES
('WH', 'stat_stock_items', 1, 'S', 1),
('WH', 'stat_low_stock', 1, 'S', 2),
('WH', 'stat_dispatches_today', 1, 'S', 3),
('WH', 'table_pending_gr', 1, 'M', 10),
('WH', 'table_pending_returns', 1, 'M', 11)
ON DUPLICATE KEY UPDATE `is_enabled` = VALUES(`is_enabled`);

-- ACC gets accounting widgets
INSERT INTO `dashboard_role_config` (`role_code`, `widget_code`, `is_enabled`, `size`, `position`) VALUES
('ACC', 'stat_revenue', 1, 'S', 1),
('ACC', 'stat_outstanding_ar', 1, 'S', 2),
('ACC', 'stat_overdue_invoices', 1, 'S', 3),
('ACC', 'table_ready_to_invoice', 1, 'M', 10),
('ACC', 'table_outstanding_invoices', 1, 'M', 11),
('ACC', 'chart_revenue_trend', 1, 'M', 20)
ON DUPLICATE KEY UPDATE `is_enabled` = VALUES(`is_enabled`);

-- HR gets HR widgets
INSERT INTO `dashboard_role_config` (`role_code`, `widget_code`, `is_enabled`, `size`, `position`) VALUES
('HR', 'stat_total_people', 1, 'S', 1),
('HR', 'stat_timesheet_pending', 1, 'S', 2),
('HR', 'table_pending_manpower', 1, 'M', 10),
('HR', 'table_timesheet_approval', 1, 'M', 11),
('HR', 'timeline_activity', 1, 'M', 20)
ON DUPLICATE KEY UPDATE `is_enabled` = VALUES(`is_enabled`);

-- PUR gets procurement widgets
INSERT INTO `dashboard_role_config` (`role_code`, `widget_code`, `is_enabled`, `size`, `position`) VALUES
('PUR', 'stat_pr_pending', 1, 'S', 1),
('PUR', 'stat_low_stock', 1, 'S', 2),
('PUR', 'table_approved_prs', 1, 'M', 10),
('PUR', 'action_quick_actions', 1, 'S', 5),
('PUR', 'timeline_activity', 1, 'M', 20)
ON DUPLICATE KEY UPDATE `is_enabled` = VALUES(`is_enabled`);

SET FOREIGN_KEY_CHECKS = 1;
