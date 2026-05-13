-- Migration: Create ig_positions table
-- Created: 2026-04-24
-- Description: Current open IG.com positions per account. Full upsert every 3 min from fetch_active_positions.

CREATE TABLE IF NOT EXISTS `ig_positions` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `account_id` INT NOT NULL COMMENT 'FK accounts.id',
  `api_key` VARCHAR(128) NOT NULL COMMENT 'FK ig_accounts.api_key',
  `epic` VARCHAR(64) NOT NULL COMMENT 'IG instrument EPIC code',
  `ticker` VARCHAR(32) NOT NULL COMMENT 'Asset ticker symbol (e.g. EURUSD)',
  `deal_id` VARCHAR(128) DEFAULT NULL COMMENT 'IG permanent deal ID',
  `direction` ENUM('BUY','SELL') DEFAULT NULL,
  `size` DECIMAL(15,6) DEFAULT NULL,
  `level` DECIMAL(20,8) DEFAULT NULL COMMENT 'Open price / level',
  `stop_level` DECIMAL(20,8) DEFAULT NULL,
  `limit_level` DECIMAL(20,8) DEFAULT NULL,
  `unrealized_pnl` DECIMAL(15,2) DEFAULT 0.00,
  `currency` VARCHAR(8) DEFAULT NULL,
  `strategy` VARCHAR(128) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ig_positions_account_deal` (`account_id`, `deal_id`),
  KEY `idx_ig_positions_account_id` (`account_id`),
  KEY `idx_ig_positions_api_key` (`api_key`),
  KEY `idx_ig_positions_ticker` (`ticker`),
  KEY `idx_ig_positions_epic` (`epic`),
  KEY `idx_ig_positions_deal_id` (`deal_id`),
  CONSTRAINT `fk_ig_positions_api_key` FOREIGN KEY (`api_key`) REFERENCES `ig_accounts` (`api_key`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='IG.com open positions (upserted on each fetch)';
