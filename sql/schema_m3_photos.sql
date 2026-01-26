-- Route Status History for M3 (Evidence)
CREATE TABLE IF NOT EXISTS `route_status_history` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `route_id` INT UNSIGNED NOT NULL,
    `from_status` VARCHAR(50) NOT NULL,
    `to_status` VARCHAR(50) NOT NULL,
    `reason` TEXT COMMENT 'Optional notes/reason',
    `created_by` INT UNSIGNED NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_route_hist` (`route_id`),
    INDEX `idx_created_at` (`created_at`),
    CONSTRAINT `fk_rsh_route` FOREIGN KEY (`route_id`) REFERENCES `routes`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
