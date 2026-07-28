-- NewUI v4.0 - Additional tables for the dashboard
-- Run this against the newui database AFTER cloning tables from tickets.

CREATE TABLE IF NOT EXISTS `dashboard_layouts` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `layout_name` VARCHAR(50) NOT NULL DEFAULT 'default',
    `layout_json` TEXT NOT NULL COMMENT 'GridStack serialized layout',
    -- No DEFAULT here: MySQL 8.0 rejects a literal DEFAULT on TEXT/BLOB/JSON
    -- ("BLOB, TEXT, GEOMETRY or JSON column 'hidden_widgets' can't have a
    -- default value"), which aborted this CREATE and left the whole table
    -- missing on MySQL 8.0 installs. MariaDB permits it, which is why it
    -- shipped unnoticed. api/layout.php supplies hidden_widgets explicitly on
    -- every insert, so the default was never used. Reported in
    -- openises/TicketsCAD#5 by @rjonesbsink.
    `hidden_widgets` TEXT COMMENT 'JSON array of hidden widget IDs',
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `idx_user_layout` (`user_id`, `layout_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
