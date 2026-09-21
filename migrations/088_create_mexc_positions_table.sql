-- Migration: Create mexc_positions table
-- Created: 2026-09-21
-- Description: Current open positions per MEXC account. Full replace on every tick of
-- mexc-flask src/fetch_positions.py (same as binance_positions, see 037).
-- Column names are the BINANCE shape on purpose: the bot converts MEXC rows
-- (positionType / holdVol in contracts / holdAvgPrice) into positionSide / positionAmt
-- in coins / entryPrice before posting, so admin and dashboard code written against
-- binance_positions can be pointed at this table unchanged.

CREATE TABLE IF NOT EXISTS `mexc_positions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `api_key` VARCHAR(128) NOT NULL COMMENT 'MEXC API key (FK mexc_accounts)',
  `uni_id` VARCHAR(36) NOT NULL COMMENT 'User ID (FK user_credentials)',
  `symbol` VARCHAR(32) NOT NULL COMMENT 'Ticker form (e.g. BTCUSDT, not BTC_USDT)',
  `position_side` VARCHAR(16) NOT NULL COMMENT 'LONG or SHORT (positionType 1 / 2)',
  `position_amt` DECIMAL(30,8) NOT NULL COMMENT 'Position size in COINS (+ long, - short) = holdVol x contractSize',
  `entry_price` DECIMAL(30,8) NULL DEFAULT NULL,
  `mark_price` DECIMAL(30,8) NULL DEFAULT NULL COMMENT 'fair price at fetch time',
  `unrealized_profit` DECIMAL(30,8) NULL DEFAULT NULL,
  `notional` DECIMAL(30,8) NULL DEFAULT NULL,
  `initial_margin` DECIMAL(30,8) NULL DEFAULT NULL,
  `maint_margin` DECIMAL(30,8) NULL DEFAULT NULL,
  `isolated_margin` DECIMAL(30,8) NULL DEFAULT NULL,
  `isolated_wallet` DECIMAL(30,8) NULL DEFAULT NULL,
  `update_time` BIGINT NULL DEFAULT NULL COMMENT 'MEXC updateTime (ms)',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_mexc_positions_api_key` (`api_key`),
  KEY `idx_mexc_positions_uni_id` (`uni_id`),
  KEY `idx_mexc_positions_symbol` (`symbol`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Current MEXC positions (full replace on each fetch)';
