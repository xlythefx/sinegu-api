-- Migration: Create user_credentials table
-- Created: 2024-11-24

CREATE TABLE IF NOT EXISTS `user_credentials` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `uni_id` VARCHAR(36) NOT NULL UNIQUE COMMENT 'Unique identifier (UUID)',
  `name` VARCHAR(255) NOT NULL COMMENT 'User full name',
  `email` VARCHAR(255) NOT NULL UNIQUE COMMENT 'User email address',
  `password` VARCHAR(255) NOT NULL COMMENT 'Hashed password',
  `referral_source` VARCHAR(255) DEFAULT NULL COMMENT 'Where user heard about us',
  `status` ENUM('pending', 'active', 'suspended') NOT NULL DEFAULT 'pending' COMMENT 'User account status',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Account creation timestamp',
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Last update timestamp',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uni_id` (`uni_id`),
  UNIQUE KEY `email` (`email`),
  KEY `status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='User credentials and authentication data';

