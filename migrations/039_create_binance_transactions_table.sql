-- Migration: Create binance_transactions table
-- Created: 2026-02-27
-- Description: Wallet transfers (deposits/withdrawals) in/out of Binance Futures. From fetch_transfers incomeType=TRANSFER.
-- api_key = binance_accounts (account identifier), uni_id = user_credentials.

CREATE TABLE IF NOT EXISTS `binance_transactions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `api_key` VARCHAR(128) NOT NULL COMMENT 'Binance API key (FK binance_accounts)',
  `uni_id` VARCHAR(36) NOT NULL COMMENT 'User ID (FK user_credentials)',
  `type` VARCHAR(16) NOT NULL COMMENT 'DEPOSIT or WITHDRAWAL',
  `amount` DECIMAL(20,8) NOT NULL COMMENT 'Transaction amount (positive for deposit, stored as absolute for clarity)',
  `balance_after` DECIMAL(20,8) NULL DEFAULT NULL,
  `tran_id` BIGINT NULL DEFAULT NULL COMMENT 'Binance tranId',
  `currency` VARCHAR(16) NOT NULL DEFAULT 'USDT',
  `transaction_time` BIGINT NULL DEFAULT NULL COMMENT 'Binance time (ms)',
  `info` VARCHAR(64) NULL DEFAULT NULL COMMENT 'e.g. TRANSFER',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_binance_transactions_api_key` (`api_key`),
  KEY `idx_binance_transactions_uni_id` (`uni_id`),
  KEY `idx_binance_transactions_type` (`type`),
  KEY `idx_binance_transactions_tran_id` (`tran_id`),
  KEY `idx_binance_transactions_transaction_time` (`transaction_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Binance Futures wallet deposits/withdrawals';
