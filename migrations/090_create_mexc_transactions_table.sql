-- Migration: Create mexc_transactions table
-- Created: 2026-09-21
-- Description: Futures-wallet transfers (IN = deposit, OUT = withdrawal) read from MEXC's
-- /api/v1/private/account/transfer_record by mexc-flask src/fetch_transfers.py.
-- The twin of binance_transactions (039) with the UNIQUE (api_key, tran_id) key (040)
-- inline; tran_id is MEXC's transfer_record.id.

CREATE TABLE IF NOT EXISTS `mexc_transactions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `api_key` VARCHAR(128) NOT NULL COMMENT 'MEXC API key (FK mexc_accounts)',
  `uni_id` VARCHAR(36) NOT NULL COMMENT 'User ID (FK user_credentials)',
  `type` VARCHAR(16) NOT NULL COMMENT 'DEPOSIT or WITHDRAWAL',
  `amount` DECIMAL(20,8) NOT NULL COMMENT 'Transaction amount (stored as absolute)',
  `balance_after` DECIMAL(20,8) NULL DEFAULT NULL,
  `tran_id` BIGINT NULL DEFAULT NULL COMMENT 'MEXC transfer_record.id',
  `currency` VARCHAR(16) NOT NULL DEFAULT 'USDT',
  `transaction_time` BIGINT NULL DEFAULT NULL COMMENT 'MEXC createTime (ms)',
  `info` VARCHAR(64) NULL DEFAULT NULL COMMENT 'txid when MEXC reports one',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_mexc_transactions_api_key_tran_id` (`api_key`, `tran_id`),
  KEY `idx_mexc_transactions_api_key` (`api_key`),
  KEY `idx_mexc_transactions_uni_id` (`uni_id`),
  KEY `idx_mexc_transactions_type` (`type`),
  KEY `idx_mexc_transactions_tran_id` (`tran_id`),
  KEY `idx_mexc_transactions_transaction_time` (`transaction_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='MEXC futures wallet deposits/withdrawals';
