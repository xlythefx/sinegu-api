-- Migration: Create mexc_accounts table
-- Created: 2026-09-21
-- Description: MEXC USDT-M futures accounts with API credentials and balance tracking.
-- The MEXC twin of binance_accounts, in its FINAL shape (034 + 035 + 036 + 069 folded
-- into one CREATE): api_key is the primary identifier (no integer id), uni_id ties the
-- account to user_credentials, demo=1 routes the bot to the futures testnet.
-- Read by mexc-flask/fetch_mexc_accounts.php, written by mexc-flask/update_balance.php.

CREATE TABLE IF NOT EXISTS `mexc_accounts` (
  `api_key` VARCHAR(128) NOT NULL COMMENT 'MEXC API key (primary identifier)',
  `uni_id` VARCHAR(36) NULL COMMENT 'User ID from user_credentials (owner)',
  `secret_key` VARCHAR(128) NOT NULL COMMENT 'MEXC API secret',
  `name` VARCHAR(128) NOT NULL COMMENT 'Account display name',
  `balance` DECIMAL(20,8) NULL DEFAULT NULL COMMENT 'Wallet balance = equity - unrealized (USDT asset)',
  `unrealized_pnl` DECIMAL(20,8) NULL DEFAULT NULL COMMENT 'unrealized from /private/account/asset/USDT',
  `initial_deposit` DECIMAL(20,8) NULL DEFAULT NULL COMMENT 'Initial deposit amount',
  `currency_type` VARCHAR(16) NOT NULL DEFAULT 'USDT' COMMENT 'Account currency (USDT)',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `deleted_at` DATETIME NULL DEFAULT NULL COMMENT 'Soft delete; NULL = active',
  `demo` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1=futures testnet, 0=live',
  `enabled` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1=enabled, 0=disabled',
  PRIMARY KEY (`api_key`),
  UNIQUE KEY `uq_mexc_accounts_name` (`name`),
  KEY `idx_deleted_at` (`deleted_at`),
  KEY `idx_mexc_accounts_uni_id` (`uni_id`),
  CONSTRAINT `fk_mexc_accounts_uni_id` FOREIGN KEY (`uni_id`) REFERENCES `user_credentials` (`uni_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='MEXC futures accounts';
