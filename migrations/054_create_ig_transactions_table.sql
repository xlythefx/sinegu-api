-- Migration: Create ig_transactions table
-- Created: 2026-04-24
-- Description: IG.com wallet deposits/withdrawals/transfers from history/transactions endpoint.

CREATE TABLE IF NOT EXISTS `ig_transactions` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `account_id` INT NOT NULL COMMENT 'FK accounts.id',
  `type` VARCHAR(32) NOT NULL COMMENT 'IG transaction type (e.g. DEPOSIT, WITHDRAWAL, TRADE)',
  `amount` DECIMAL(20,8) NOT NULL COMMENT 'Transaction amount (signed: + deposit, - withdrawal)',
  `currency` VARCHAR(16) NOT NULL DEFAULT 'USD',
  `reference` VARCHAR(128) DEFAULT NULL COMMENT 'IG reference ID (used for idempotency)',
  `transaction_type` VARCHAR(64) DEFAULT NULL COMMENT 'Raw IG transactionType field',
  `transaction_date` DATETIME DEFAULT NULL COMMENT 'IG transaction date (UTC)',
  `instrument_name` VARCHAR(128) DEFAULT NULL,
  `profit_and_loss` VARCHAR(64) DEFAULT NULL COMMENT 'IG profitAndLoss field (may include currency symbol)',
  `cash_transaction` TINYINT(1) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ig_transactions_account_ref` (`account_id`, `reference`),
  KEY `idx_ig_transactions_account_id` (`account_id`),
  KEY `idx_ig_transactions_type` (`type`),
  KEY `idx_ig_transactions_date` (`transaction_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='IG.com wallet transactions (deposits/withdrawals/trades)';
