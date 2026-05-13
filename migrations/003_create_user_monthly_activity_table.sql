-- Migration: Create user_monthly_activity table
-- Created: 2025-01-02
-- Description: Tracks monthly active user status based on help actions

CREATE TABLE IF NOT EXISTS `user_monthly_activity` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `uni_id` VARCHAR(36) NOT NULL COMMENT 'User ID from user_credentials',
  `month_year` VARCHAR(7) NOT NULL COMMENT 'Format: YYYY-MM (e.g., "2025-01")',
  `help_actions_count` INT(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Total verified help actions in month',
  `unique_people_helped` INT(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Count of different people helped',
  `is_active` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'True if meets criteria (4+ actions, 2+ people, constraints passed)',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Record creation timestamp',
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Last update timestamp',
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_user_month` (`uni_id`, `month_year`),
  KEY `idx_uni_id` (`uni_id`),
  KEY `idx_month_year` (`month_year`),
  KEY `idx_is_active` (`is_active`),
  CONSTRAINT `fk_user_monthly_activity_uni_id` FOREIGN KEY (`uni_id`) REFERENCES `user_credentials` (`uni_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Tracks monthly active user status based on help actions';

