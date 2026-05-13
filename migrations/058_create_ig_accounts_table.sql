-- Migration: Create ig_accounts table
-- Created: 2026-04-24
-- Description: Dedicated IG broker accounts used by ig-flask services.

CREATE TABLE IF NOT EXISTS `ig_accounts` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uni_id` VARCHAR(36) NOT NULL COMMENT 'FK to user_credentials.uni_id',
  `api_key` VARCHAR(128) NOT NULL COMMENT 'IG API key',
  `secret_key` VARCHAR(128) DEFAULT NULL COMMENT 'Optional (IG REST v1/v2 does not require this)',
  `name` VARCHAR(128) NOT NULL COMMENT 'Account display name',
  `email` VARCHAR(255) NOT NULL COMMENT 'IG identifier/login email',
  `password` VARCHAR(255) NOT NULL COMMENT 'IG login password',
  `balance` DECIMAL(20,8) DEFAULT NULL,
  `initial_deposit` DECIMAL(20,8) DEFAULT NULL,
  `currency_type` VARCHAR(16) DEFAULT 'USD',
  `broker_accountID` VARCHAR(64) DEFAULT NULL,
  `ig_cst` TEXT DEFAULT NULL,
  `ig_x_security_token` TEXT DEFAULT NULL,
  `ig_session_last_used_at` DATETIME DEFAULT NULL,
  `demo` TINYINT(1) NOT NULL DEFAULT 0,
  `enabled` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ig_accounts_api_key` (`api_key`),
  UNIQUE KEY `uq_ig_accounts_name` (`name`),
  KEY `idx_ig_accounts_uni_id` (`uni_id`),
  KEY `idx_ig_accounts_enabled` (`enabled`),
  KEY `idx_ig_accounts_deleted_at` (`deleted_at`),
  CONSTRAINT `fk_ig_accounts_uni_id` FOREIGN KEY (`uni_id`) REFERENCES `user_credentials` (`uni_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='IG broker accounts for ig-flask';
