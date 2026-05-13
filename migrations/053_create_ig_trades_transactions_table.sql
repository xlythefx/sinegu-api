-- Migration: Create ig_trades_transactions table
-- Created: 2026-04-24
-- Description: Entry/Exit trade log for IG.com (mirrors capital_trades_transactions).

CREATE TABLE IF NOT EXISTS `ig_trades_transactions` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `account_id` INT NOT NULL,
  `ticker` VARCHAR(32) NOT NULL,
  `type` ENUM('entry','exit') NOT NULL,
  `price` DECIMAL(20,8) NOT NULL,
  `pnl` DECIMAL(15,2) DEFAULT NULL,
  `size` DECIMAL(15,6) DEFAULT NULL,
  `increment` INT DEFAULT NULL,
  `side` VARCHAR(8) DEFAULT NULL COMMENT 'BUY or SELL',
  `deal_id` VARCHAR(128) DEFAULT NULL COMMENT 'IG permanent deal ID',
  `unrealized_pnl` DECIMAL(15,2) DEFAULT NULL,
  `strategy` VARCHAR(128) DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ig_trades_account_id` (`account_id`),
  KEY `idx_ig_trades_ticker` (`ticker`),
  KEY `idx_ig_trades_type` (`type`),
  KEY `idx_ig_trades_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='IG.com Entry/Exit trading orders';
