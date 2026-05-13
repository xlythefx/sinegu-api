-- Migration: Create ig_past_positions table
-- Created: 2026-04-24
-- Description: Stores closed IG.com positions (realized outcomes) after successful exits.

CREATE TABLE IF NOT EXISTS `ig_past_positions` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `account_id` INT NOT NULL COMMENT 'FK accounts.id',
  `api_key` VARCHAR(128) NOT NULL COMMENT 'FK ig_accounts.api_key',
  `ticker` VARCHAR(32) NOT NULL COMMENT 'Asset ticker symbol (e.g. EURUSD)',
  `epic` VARCHAR(64) DEFAULT NULL COMMENT 'IG instrument EPIC code',
  `deal_id` VARCHAR(128) DEFAULT NULL COMMENT 'Original opened deal id',
  `side` ENUM('BUY','SELL') DEFAULT NULL COMMENT 'Original position side before close',
  `size` DECIMAL(15,6) DEFAULT NULL,
  `open_price` DECIMAL(20,8) DEFAULT NULL,
  `close_price` DECIMAL(20,8) DEFAULT NULL,
  `pnl` DECIMAL(15,2) DEFAULT NULL COMMENT 'Realized PnL',
  `currency` VARCHAR(8) DEFAULT NULL,
  `opened_at` DATETIME DEFAULT NULL,
  `closed_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `close_deal_reference` VARCHAR(128) DEFAULT NULL COMMENT 'IG close deal reference',
  `strategy` VARCHAR(128) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ig_past_positions_account_id` (`account_id`),
  KEY `idx_ig_past_positions_api_key` (`api_key`),
  KEY `idx_ig_past_positions_ticker` (`ticker`),
  KEY `idx_ig_past_positions_deal_id` (`deal_id`),
  KEY `idx_ig_past_positions_closed_at` (`closed_at`),
  CONSTRAINT `fk_ig_past_positions_api_key` FOREIGN KEY (`api_key`) REFERENCES `ig_accounts` (`api_key`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='IG.com closed positions history';
