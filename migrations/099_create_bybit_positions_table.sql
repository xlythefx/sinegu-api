-- Migration: Create bybit_positions table
-- Created: 2026-11-07
-- Description: Current open positions per Bybit account. Full replace on every tick of
-- flask/bybit src/fetch_positions.py (same as binance_positions / mexc_positions, 088).
-- Column names are the BINANCE shape on purpose: the bot's src/bybit_api.py turns
-- Bybit's /v5/position/list rows (side Buy/Sell, size, avgPrice, positionIdx) into
-- positionRisk rows (positionSide LONG/SHORT, signed positionAmt in coins, entryPrice)
-- before posting, so admin and dashboard code written against binance_positions can be
-- pointed at this table unchanged. Bybit linear size is already in coins.

CREATE TABLE IF NOT EXISTS `bybit_positions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `api_key` VARCHAR(128) NOT NULL COMMENT 'Bybit API key (FK bybit_accounts)',
  `uni_id` VARCHAR(36) NOT NULL COMMENT 'User ID (FK user_credentials)',
  `symbol` VARCHAR(32) NOT NULL COMMENT 'Ticker form (e.g. BTCUSDT)',
  `position_side` VARCHAR(16) NOT NULL COMMENT 'LONG or SHORT (always, also on one-way accounts)',
  `position_amt` DECIMAL(30,8) NOT NULL COMMENT 'Position size in COINS (+ long, - short)',
  `entry_price` DECIMAL(30,8) NULL DEFAULT NULL,
  `mark_price` DECIMAL(30,8) NULL DEFAULT NULL COMMENT 'markPrice at fetch time',
  `unrealized_profit` DECIMAL(30,8) NULL DEFAULT NULL,
  `notional` DECIMAL(30,8) NULL DEFAULT NULL,
  `initial_margin` DECIMAL(30,8) NULL DEFAULT NULL,
  `maint_margin` DECIMAL(30,8) NULL DEFAULT NULL,
  `isolated_margin` DECIMAL(30,8) NULL DEFAULT NULL,
  `isolated_wallet` DECIMAL(30,8) NULL DEFAULT NULL,
  `update_time` BIGINT NULL DEFAULT NULL COMMENT 'Bybit updatedTime (ms)',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_bybit_positions_api_key` (`api_key`),
  KEY `idx_bybit_positions_uni_id` (`uni_id`),
  KEY `idx_bybit_positions_symbol` (`symbol`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Current Bybit positions (full replace on each fetch)';
