-- Phase 5: Planning & Dispatch Schema (Revised v2)

-- Plans Table
CREATE TABLE IF NOT EXISTS `plans` (
    `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    `plan_number` varchar(50) NOT NULL,
    `job_id` int(11) UNSIGNED NOT NULL,
    `plan_date` date NOT NULL,
    `status` enum('Draft','Confirmed','Cancelled') DEFAULT 'Draft',
    `notes` text DEFAULT NULL,
    `created_by` int(11) UNSIGNED NOT NULL,
    `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
    `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `plan_number` (`plan_number`),
    KEY `job_id` (`job_id`),
    KEY `created_by` (`created_by`),
    CONSTRAINT `plans_ibfk_1` FOREIGN KEY (`job_id`) REFERENCES `jobs` (`id`),
    CONSTRAINT `plans_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Plan Items Table
CREATE TABLE IF NOT EXISTS `plan_items` (
    `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    `plan_id` int(11) UNSIGNED NOT NULL,
    `item_id` int(11) UNSIGNED NOT NULL,
    `qty` decimal(10,2) NOT NULL DEFAULT 1.00,
    `notes` text DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `plan_id` (`plan_id`),
    KEY `item_id` (`item_id`),
    CONSTRAINT `plan_items_ibfk_1` FOREIGN KEY (`plan_id`) REFERENCES `plans` (`id`) ON DELETE CASCADE,
    CONSTRAINT `plan_items_ibfk_2` FOREIGN KEY (`item_id`) REFERENCES `items` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dispatch Notes (Delivery Orders) Table
CREATE TABLE IF NOT EXISTS `dispatch_notes` (
    `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    `do_number` varchar(50) NOT NULL,
    `plan_id` int(11) UNSIGNED NOT NULL,
    `vehicle_id` int(11) UNSIGNED DEFAULT NULL,
    `driver_id` int(11) UNSIGNED DEFAULT NULL,
    `dispatch_date` datetime NOT NULL,
    `status` enum('Draft','Prepared','Dispatched','Delivered','Cancelled') DEFAULT 'Draft',
    `notes` text DEFAULT NULL,
    `created_by` int(11) UNSIGNED NOT NULL,
    `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
    `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `do_number` (`do_number`),
    KEY `plan_id` (`plan_id`),
    KEY `vehicle_id` (`vehicle_id`),
    KEY `driver_id` (`driver_id`),
    KEY `created_by` (`created_by`),
    CONSTRAINT `dispatch_notes_ibfk_1` FOREIGN KEY (`plan_id`) REFERENCES `plans` (`id`),
    CONSTRAINT `dispatch_notes_ibfk_2` FOREIGN KEY (`vehicle_id`) REFERENCES `items` (`id`), -- Vehicle is an Item
    CONSTRAINT `dispatch_notes_ibfk_3` FOREIGN KEY (`driver_id`) REFERENCES `people` (`id`),
    CONSTRAINT `dispatch_notes_ibfk_4` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dispatch Items Table
CREATE TABLE IF NOT EXISTS `dispatch_items` (
    `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    `dispatch_id` int(11) UNSIGNED NOT NULL,
    `plan_item_id` int(11) UNSIGNED DEFAULT NULL,
    `item_id` int(11) UNSIGNED NOT NULL,
    `qty` decimal(10,2) NOT NULL DEFAULT 1.00,
    `serial_numbers` text DEFAULT NULL COMMENT 'JSON array of serial numbers',
    `condition_note` text DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `dispatch_id` (`dispatch_id`),
    KEY `plan_item_id` (`plan_item_id`),
    KEY `item_id` (`item_id`),
    CONSTRAINT `dispatch_items_ibfk_1` FOREIGN KEY (`dispatch_id`) REFERENCES `dispatch_notes` (`id`) ON DELETE CASCADE,
    CONSTRAINT `dispatch_items_ibfk_2` FOREIGN KEY (`plan_item_id`) REFERENCES `plan_items` (`id`),
    CONSTRAINT `dispatch_items_ibfk_3` FOREIGN KEY (`item_id`) REFERENCES `items` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Initialize Document Numbers for Phase 5
INSERT INTO `doc_number_settings` (`doc_type`, `prefix`, `current_year`, `next_number`, `padding`, `reset_yearly`) 
VALUES 
('PLN', 'PLN-', YEAR(CURRENT_DATE()), 1, 5, 1),
('DO', 'DO-', YEAR(CURRENT_DATE()), 1, 5, 1)
ON DUPLICATE KEY UPDATE updated_at = NOW();
