-- Migration: Create bybit_pastpositions table
-- Created: 2026-11-07
-- Description: Closed Bybit trades. Inserted by the webhook on EXIT_LONG / EXIT_SHORT and
-- reconciled by flask/bybit src/fetch_past_positions.py from /v5/position/closed-pnl.
-- The twin of mexc_pastpositions (089) / binance_pastpositions with ONE deliberate
-- difference: `order_id` is VARCHAR(64), not BIGINT.
--
-- Why: Bybit V5 order ids are strings, and on linear (USDT-perp) contracts they are
-- UUIDs — Bybit's own Get Closed PnL example returns
-- "orderId": "5a373bfe-188d-4913-9c81-d57ab5be8068". Cast to an integer that becomes 5
-- (or 0), every close of an account collides on the UNIQUE key below and INSERT IGNORE
-- silently keeps only the first one. The bot never int()s an id either.
--
-- UNIQUE (api_key, symbol, order_id) makes both writers idempotent (089 / 077).
-- realized_pnl is stored NET of commission, exactly like the Binance and MEXC tables.

CREATE TABLE IF NOT EXISTS `bybit_pastpositions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `api_key` VARCHAR(128) NOT NULL COMMENT 'Bybit API key (FK bybit_accounts)',
  `uni_id` VARCHAR(36) NOT NULL COMMENT 'User ID (FK user_credentials)',
  `symbol` VARCHAR(32) NOT NULL COMMENT 'Ticker form (e.g. BTCUSDT)',
  `position_side` VARCHAR(16) NOT NULL COMMENT 'LONG or SHORT',
  `position_amt` DECIMAL(30,8) NOT NULL COMMENT 'Closed quantity in COINS',
  `entry_price` DECIMAL(30,8) NULL DEFAULT NULL,
  `exit_price` DECIMAL(30,8) NULL DEFAULT NULL,
  `realized_pnl` DECIMAL(30,8) NULL DEFAULT NULL COMMENT 'NET of commission',
  `side` VARCHAR(8) NOT NULL COMMENT 'BUY or SELL (close direction)',
  `order_id` VARCHAR(64) NULL DEFAULT NULL COMMENT 'Bybit orderId (a STRING, often a UUID)',
  `closed_at` DATETIME NOT NULL COMMENT 'When position was closed (UTC)',
  `strategy` VARCHAR(100) NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_bybit_pastpositions_api_symbol_order` (`api_key`, `symbol`, `order_id`),
  KEY `idx_bybit_pastpositions_api_key` (`api_key`),
  KEY `idx_bybit_pastpositions_uni_id` (`uni_id`),
  KEY `idx_bybit_pastpositions_symbol` (`symbol`),
  KEY `idx_bybit_pastpositions_closed_at` (`closed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Closed Bybit positions (append on exit)';
