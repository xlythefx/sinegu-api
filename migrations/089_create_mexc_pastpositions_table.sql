-- Migration: Create mexc_pastpositions table
-- Created: 2026-09-21
-- Description: Closed MEXC trades. Inserted by the webhook on EXIT_LONG / EXIT_SHORT and
-- reconciled by mexc-flask src/fetch_past_positions.py from MEXC's order/deal history.
-- The twin of binance_pastpositions in its FINAL shape: 038 + strategy (044) + the
-- UNIQUE (api_key, symbol, order_id) key (077) that makes both writers idempotent.
-- realized_pnl is stored NET of commission, exactly like the Binance table.

CREATE TABLE IF NOT EXISTS `mexc_pastpositions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `api_key` VARCHAR(128) NOT NULL COMMENT 'MEXC API key (FK mexc_accounts)',
  `uni_id` VARCHAR(36) NOT NULL COMMENT 'User ID (FK user_credentials)',
  `symbol` VARCHAR(32) NOT NULL COMMENT 'Ticker form (e.g. BTCUSDT)',
  `position_side` VARCHAR(16) NOT NULL COMMENT 'LONG or SHORT',
  `position_amt` DECIMAL(30,8) NOT NULL COMMENT 'Closed quantity in COINS',
  `entry_price` DECIMAL(30,8) NULL DEFAULT NULL,
  `exit_price` DECIMAL(30,8) NULL DEFAULT NULL,
  `realized_pnl` DECIMAL(30,8) NULL DEFAULT NULL COMMENT 'NET of commission',
  `side` VARCHAR(8) NOT NULL COMMENT 'BUY or SELL (close direction)',
  `order_id` BIGINT NULL DEFAULT NULL COMMENT 'MEXC orderId',
  `closed_at` DATETIME NOT NULL COMMENT 'When position was closed (UTC)',
  `strategy` VARCHAR(100) NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_mexc_pastpositions_api_symbol_order` (`api_key`, `symbol`, `order_id`),
  KEY `idx_mexc_pastpositions_api_key` (`api_key`),
  KEY `idx_mexc_pastpositions_uni_id` (`uni_id`),
  KEY `idx_mexc_pastpositions_symbol` (`symbol`),
  KEY `idx_mexc_pastpositions_closed_at` (`closed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Closed MEXC positions (append on exit)';
