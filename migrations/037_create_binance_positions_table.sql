-- Migration: Create binance_positions table
-- Created: 2026-02-27
-- Description: Current open positions per Binance account. Full replace every 10s from fetch_positions.
-- api_key links to binance_accounts, uni_id links to user_credentials (universal platform key).

CREATE TABLE IF NOT EXISTS `binance_positions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `api_key` VARCHAR(128) NOT NULL COMMENT 'Binance API key (FK binance_accounts)',
  `uni_id` VARCHAR(36) NOT NULL COMMENT 'User ID (FK user_credentials)',
  `symbol` VARCHAR(32) NOT NULL COMMENT 'Trading pair (e.g. BTCUSDT)',
  `position_side` VARCHAR(16) NOT NULL COMMENT 'BOTH, LONG, or SHORT',
  `position_amt` DECIMAL(30,8) NOT NULL COMMENT 'Position size (+ long, - short)',
  `entry_price` DECIMAL(30,8) NULL DEFAULT NULL,
  `mark_price` DECIMAL(30,8) NULL DEFAULT NULL,
  `unrealized_profit` DECIMAL(30,8) NULL DEFAULT NULL,
  `notional` DECIMAL(30,8) NULL DEFAULT NULL,
  `initial_margin` DECIMAL(30,8) NULL DEFAULT NULL,
  `maint_margin` DECIMAL(30,8) NULL DEFAULT NULL,
  `isolated_margin` DECIMAL(30,8) NULL DEFAULT NULL,
  `isolated_wallet` DECIMAL(30,8) NULL DEFAULT NULL,
  `update_time` BIGINT NULL DEFAULT NULL COMMENT 'Binance updateTime (ms)',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_binance_positions_api_key` (`api_key`),
  KEY `idx_binance_positions_uni_id` (`uni_id`),
  KEY `idx_binance_positions_symbol` (`symbol`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Current Binance positions (full replace on each fetch)';
