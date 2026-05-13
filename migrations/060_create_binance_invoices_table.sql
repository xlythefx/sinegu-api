-- Migration: Create binance_invoices table
-- Created: 2026-04-28
-- Description: Monthly billing periods for Binance accounts. Mirrors billing_periods structure
--              but uses api_key as the canonical broker account identifier.
--              HWM is derived from the most recent row per api_key instead of stored on the accounts table.

CREATE TABLE IF NOT EXISTS `binance_invoices` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `user_id` VARCHAR(36) NOT NULL COMMENT 'FK user_credentials.uni_id',
  `account_id` INT DEFAULT NULL COMMENT 'Unused for Binance (no integer PK); reserved for consistency',
  `api_key` VARCHAR(128) NOT NULL COMMENT 'FK binance_accounts.api_key',
  `month_year` VARCHAR(7) NOT NULL COMMENT 'Billing month in YYYY-MM format',
  `equity_start` DECIMAL(20,8) DEFAULT 0 COMMENT 'HWM at start of period',
  `equity_end` DECIMAL(20,8) DEFAULT 0 COMMENT 'Equity at end of period (balance + unrealized)',
  `realized_pnl` DECIMAL(20,8) DEFAULT 0 COMMENT 'Realized PnL in billing month',
  `unrealized_pnl` DECIMAL(20,8) DEFAULT 0 COMMENT 'Unrealized PnL snapshot at billing time',
  `deposit_amount` DECIMAL(20,8) DEFAULT 0 COMMENT 'Adjusted deposit (initial + net transfers)',
  `adjusted_equity` DECIMAL(20,8) DEFAULT 0 COMMENT 'Equity after adjustments',
  `capital_flow` DECIMAL(20,8) DEFAULT 0 COMMENT 'Net deposits/withdrawals in billing month',
  `performance_equity` DECIMAL(20,8) DEFAULT 0 COMMENT 'Previous equity + billing month trading profit',
  `hwm_before` DECIMAL(20,8) DEFAULT 0,
  `hwm_after` DECIMAL(20,8) DEFAULT 0,
  `new_realized_profit` DECIMAL(20,8) DEFAULT 0,
  `new_unrealized_profit` DECIMAL(20,8) DEFAULT 0,
  `fee_realized` DECIMAL(20,8) DEFAULT 0,
  `fee_unrealized` DECIMAL(20,8) DEFAULT 0,
  `total_fee` DECIMAL(20,8) DEFAULT 0,
  `status` ENUM('pending','paid','failed','overdue') NOT NULL DEFAULT 'pending',
  `due_date` DATE DEFAULT NULL COMMENT 'Payment due date (1st of following month + 7 days)',
  `reminder_count` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `last_reminder_sent_at` DATETIME DEFAULT NULL,
  `invoice_sent_at` DATETIME DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_binance_invoices_apikey_month` (`api_key`, `month_year`),
  KEY `idx_binance_invoices_user_month` (`user_id`, `month_year`),
  KEY `idx_binance_invoices_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Monthly billing periods for Binance broker accounts';
