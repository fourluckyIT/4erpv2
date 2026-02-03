-- Dashboard Widgets Configuration Tables

CREATE TABLE IF NOT EXISTS dashboard_widgets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(50) NOT NULL UNIQUE,
    name VARCHAR(100) NOT NULL,
    description TEXT,
    category ENUM('stats', 'table', 'chart', 'action', 'timeline') NOT NULL DEFAULT 'stats',
    default_enabled TINYINT(1) NOT NULL DEFAULT 1,
    default_size ENUM('S', 'M', 'L') NOT NULL DEFAULT 'M',
    sort_order INT NOT NULL DEFAULT 0,
    allowed_roles JSON DEFAULT NULL COMMENT 'NULL = all roles, or JSON array of role codes',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_category (category),
    INDEX idx_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dashboard_role_config (
    id INT AUTO_INCREMENT PRIMARY KEY,
    role_code VARCHAR(10) NOT NULL,
    widget_code VARCHAR(50) NOT NULL,
    is_enabled TINYINT(1) NOT NULL DEFAULT 1,
    size ENUM('S', 'M', 'L') DEFAULT NULL,
    position INT DEFAULT NULL,
    custom_settings JSON DEFAULT NULL,
    updated_by INT DEFAULT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_role_widget (role_code, widget_code),
    INDEX idx_role (role_code),
    FOREIGN KEY (widget_code) REFERENCES dashboard_widgets(code) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert default widgets
INSERT INTO dashboard_widgets (code, name, description, category, default_enabled, default_size, sort_order) VALUES
-- Stats
('stat_pending_jobs', 'งานรอดำเนินการ', 'จำนวน Job ที่รอ Approve/Plan', 'stats', 1, 'S', 10),
('stat_active_plans', 'Plan ที่กำลังดำเนินการ', 'จำนวน Plan ที่ Active', 'stats', 1, 'S', 20),
('stat_pending_dispatch', 'รอจัดส่ง', 'จำนวน Dispatch ที่รอจัดส่ง', 'stats', 1, 'S', 30),
('stat_pending_pr', 'PR รออนุมัติ', 'จำนวน PR ที่รอการอนุมัติ', 'stats', 1, 'S', 40),
-- Tables
('table_recent_jobs', 'งานล่าสุด', 'รายการ Job ล่าสุด 5 รายการ', 'table', 1, 'M', 100),
('table_my_tasks', 'งานของฉัน', 'งานที่ได้รับมอบหมาย', 'table', 1, 'M', 110),
('table_pending_approvals', 'รอการอนุมัติ', 'รายการที่รอการอนุมัติของฉัน', 'table', 1, 'M', 120),
-- Actions
('action_quick_create', 'สร้างด่วน', 'ปุ่มสร้าง Job/Plan/PR อย่างรวดเร็ว', 'action', 1, 'S', 200),
('action_notifications', 'การแจ้งเตือน', 'แจ้งเตือนล่าสุด', 'action', 1, 'S', 210),
-- Timeline
('timeline_activity', 'กิจกรรมล่าสุด', 'Timeline กิจกรรมในระบบ', 'timeline', 1, 'L', 300)
ON DUPLICATE KEY UPDATE name = VALUES(name);
