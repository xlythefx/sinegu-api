-- Migration: Create user_profile_helps table
-- Description: "This user helped me" - requester creates pending; helped user accepts/declines.

CREATE TABLE IF NOT EXISTS `user_profile_helps` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `helper_uni_id` VARCHAR(36) NOT NULL COMMENT 'User who said "This user helped me"',
  `helped_uni_id` VARCHAR(36) NOT NULL COMMENT 'User who (allegedly) provided help',
  `status` ENUM('pending','accepted','declined') NOT NULL DEFAULT 'pending' COMMENT 'pending = awaiting confirmation; accepted = helped user confirmed; declined = helped user declined',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_profile_help` (`helper_uni_id`, `helped_uni_id`),
  KEY `idx_helped_uni_id` (`helped_uni_id`),
  KEY `idx_status` (`status`),
  CONSTRAINT `fk_profile_help_helper` FOREIGN KEY (`helper_uni_id`) REFERENCES `user_credentials` (`uni_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_profile_help_helped` FOREIGN KEY (`helped_uni_id`) REFERENCES `user_credentials` (`uni_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Profile help: "This user helped me" with pending/accept flow';
