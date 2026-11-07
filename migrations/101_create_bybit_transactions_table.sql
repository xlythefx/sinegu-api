-- Migration: Create bybit_transactions table
-- Created: 2026-11-07
-- Description: UNIFIED-wallet transfers (TRANSFER_IN = deposit, TRANSFER_OUT = withdrawal)
-- read from Bybit's /v5/account/transaction-log by flask/bybit src/fetch_transfers.py.
-- The twin of mexc_transactions (090) / binance_transactions with the UNIQUE
-- (api_key, tran_id) key inline, and ONE difference: `tran_id` is VARCHAR(64), because
-- Bybit's transaction-log `id` is a string (the bot sends it as one, cut to 64 chars).
-- `info` holds the Bybit log type (TRANSFER_IN / TRANSFER_OUT).

CREATE TABLE IF NOT EXISTS `bybit_transactions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `api_key` VARCHAR(128) NOT NULL COMMENT 'Bybit API key (FK bybit_accounts)',
  `uni_id` VARCHAR(36) NOT NULL COMMENT 'User ID (FK user_credentials)',
  `type` VARCHAR(16) NOT NULL COMMENT 'DEPOSIT or WITHDRAWAL',
  `amount` DECIMAL(20,8) NOT NULL COMMENT 'Transaction amount (stored as absolute)',
  `balance_after` DECIMAL(20,8) NULL DEFAULT NULL,
  `tran_id` VARCHAR(64) NULL DEFAULT NULL COMMENT 'Bybit transaction-log id (a STRING)',
  `currency` VARCHAR(16) NOT NULL DEFAULT 'USDT',
  `transaction_time` BIGINT NULL DEFAULT NULL COMMENT 'Bybit transactionTime (ms)',
  `info` VARCHAR(64) NULL DEFAULT NULL COMMENT 'TRANSFER_IN or TRANSFER_OUT',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_bybit_transactions_api_key_tran_id` (`api_key`, `tran_id`),
  KEY `idx_bybit_transactions_api_key` (`api_key`),
  KEY `idx_bybit_transactions_uni_id` (`uni_id`),
  KEY `idx_bybit_transactions_type` (`type`),
  KEY `idx_bybit_transactions_tran_id` (`tran_id`),
  KEY `idx_bybit_transactions_transaction_time` (`transaction_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Bybit wallet deposits/withdrawals';
