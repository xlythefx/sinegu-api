-- Migration: Create bybit_accounts table
-- Created: 2026-11-07
-- Description: Bybit USDT-perpetual (V5, UNIFIED wallet) accounts with API credentials
-- and balance tracking. The Bybit twin of mexc_accounts (087) / binance_accounts, column
-- for column: api_key is the primary identifier (no integer id), uni_id ties the account
-- to user_credentials, demo=1 routes the bot to Bybit Demo Trading (api-demo.bybit.com,
-- which takes its own keys). Read by bybit-flask/fetch_bybit_accounts.php, written by
-- bybit-flask/update_balance.php. Bot: flask/bybit in the dashboard repo.

CREATE TABLE IF NOT EXISTS `bybit_accounts` (
  `api_key` VARCHAR(128) NOT NULL COMMENT 'Bybit API key (primary identifier)',
  `uni_id` VARCHAR(36) NULL COMMENT 'User ID from user_credentials (owner)',
  `secret_key` VARCHAR(128) NOT NULL COMMENT 'Bybit API secret',
  `name` VARCHAR(128) NOT NULL COMMENT 'Account display name',
  `balance` DECIMAL(20,8) NULL DEFAULT NULL COMMENT 'USDT walletBalance (UNIFIED wallet)',
  `unrealized_pnl` DECIMAL(20,8) NULL DEFAULT NULL COMMENT 'USDT unrealisedPnl from /v5/account/wallet-balance',
  `initial_deposit` DECIMAL(20,8) NULL DEFAULT NULL COMMENT 'Initial deposit amount',
  `currency_type` VARCHAR(16) NOT NULL DEFAULT 'USDT' COMMENT 'Account currency (USDT)',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `deleted_at` DATETIME NULL DEFAULT NULL COMMENT 'Soft delete; NULL = active',
  `demo` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1=Demo Trading (api-demo.bybit.com), 0=live',
  `enabled` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1=enabled, 0=disabled',
  PRIMARY KEY (`api_key`),
  UNIQUE KEY `uq_bybit_accounts_name` (`name`),
  KEY `idx_deleted_at` (`deleted_at`),
  KEY `idx_bybit_accounts_uni_id` (`uni_id`),
  CONSTRAINT `fk_bybit_accounts_uni_id` FOREIGN KEY (`uni_id`) REFERENCES `user_credentials` (`uni_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Bybit USDT-perpetual accounts';
