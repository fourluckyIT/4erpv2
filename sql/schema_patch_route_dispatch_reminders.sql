-- ===========================================
-- Patch: Route dispatch reminders
-- In-app reminders for WH dispatch (no LINE)
-- ===========================================

CREATE TABLE IF NOT EXISTS `route_dispatch_reminders` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `route_id` INT UNSIGNED NOT NULL,
    `plan_id` INT UNSIGNED NOT NULL,
    `job_id` INT UNSIGNED NOT NULL,
    `status` ENUM('Active', 'Snoozed', 'Stopped') NOT NULL DEFAULT 'Active',
    `next_due_at` DATETIME NOT NULL,
    `last_notified_at` DATETIME NULL,
    `snooze_until` DATETIME NULL,
    `snooze_by` INT UNSIGNED NULL,
    `stopped_at` DATETIME NULL,
    `stopped_by` INT UNSIGNED NULL,
    `stop_reason` VARCHAR(255) NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_route` (`route_id`),
    INDEX `idx_status_due` (`status`, `next_due_at`),
    INDEX `idx_job` (`job_id`),
    CONSTRAINT `fk_rdr_route` FOREIGN KEY (`route_id`) REFERENCES `routes`(`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_rdr_plan` FOREIGN KEY (`plan_id`) REFERENCES `plans`(`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_rdr_job` FOREIGN KEY (`job_id`) REFERENCES `jobs`(`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_rdr_snooze_user` FOREIGN KEY (`snooze_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_rdr_stop_user` FOREIGN KEY (`stopped_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='WH dispatch reminder schedule (route confirmed -> dispatch)';
