-- Migration: Create binance_accounts table
-- Created: 2026-02-27
-- Description: Binance exchange accounts with API credentials and balance tracking.

CREATE TABLE IF NOT EXISTS `binance_accounts` (
  `api_key` VARCHAR(128) NOT NULL COMMENT 'Binance API key (primary identifier)',
  `secret_key` VARCHAR(128) NOT NULL COMMENT 'Binance API secret',
  `name` VARCHAR(128) NOT NULL COMMENT 'Account display name',
  `balance` DECIMAL(20,8) NULL DEFAULT NULL COMMENT 'Current balance',
  `initial_deposit` DECIMAL(20,8) NULL DEFAULT NULL COMMENT 'Initial deposit amount',
  `currency_type` VARCHAR(16) NOT NULL DEFAULT 'USDT' COMMENT 'Account currency (USDT, BUSD, etc.)',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `deleted_at` DATETIME NULL DEFAULT NULL COMMENT 'Soft delete; NULL = active',
  PRIMARY KEY (`api_key`),
  UNIQUE KEY `uq_binance_accounts_name` (`name`),
  KEY `idx_deleted_at` (`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Binance exchange accounts';
