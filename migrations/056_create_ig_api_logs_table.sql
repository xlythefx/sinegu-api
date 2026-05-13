-- Migration: Create ig_api_logs table
-- Created: 2026-04-24
-- Description: Append-only log of IG.com API events (rate limits, auth failures, drops).
-- Mirrors capital_api_logs (migration 051).

CREATE TABLE IF NOT EXISTS `ig_api_logs` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `account_id` INT UNSIGNED DEFAULT NULL,
  `account_name` VARCHAR(128) DEFAULT NULL,
  `ticker` VARCHAR(64) DEFAULT NULL,
  `action` VARCHAR(32) DEFAULT NULL COMMENT 'BUY/SELL/EXIT_LONG/EXIT_SHORT/EXIT',
  `event_type` VARCHAR(64) NOT NULL DEFAULT 'rate_limited'
    COMMENT 'rate_limited, rate_limited_dropped, invalid_api_key, login_failed, etc.',
  `http_status` INT DEFAULT NULL,
  `source` VARCHAR(64) DEFAULT NULL COMMENT 'webhook_queue, fetch_balances, fetch_transactions, etc.',
  `message` TEXT DEFAULT NULL,
  `email_sent` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ig_api_logs_account` (`account_id`),
  KEY `idx_ig_api_logs_event_type` (`event_type`),
  KEY `idx_ig_api_logs_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  COMMENT='IG.com API events (rate limits, drops). Append-only.';
