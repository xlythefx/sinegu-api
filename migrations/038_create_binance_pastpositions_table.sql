-- Migration: Create binance_pastpositions table
-- Created: 2026-02-27
-- Description: Closed trades. Insert when EXIT_LONG/EXIT_SHORT/EXIT executed.
-- api_key links to binance_accounts, uni_id links to user_credentials.

CREATE TABLE IF NOT EXISTS `binance_pastpositions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `api_key` VARCHAR(128) NOT NULL COMMENT 'Binance API key (FK binance_accounts)',
  `uni_id` VARCHAR(36) NOT NULL COMMENT 'User ID (FK user_credentials)',
  `symbol` VARCHAR(32) NOT NULL COMMENT 'Trading pair',
  `position_side` VARCHAR(16) NOT NULL COMMENT 'LONG, SHORT, or BOTH',
  `position_amt` DECIMAL(30,8) NOT NULL COMMENT 'Closed quantity',
  `entry_price` DECIMAL(30,8) NULL DEFAULT NULL,
  `exit_price` DECIMAL(30,8) NULL DEFAULT NULL,
  `realized_pnl` DECIMAL(30,8) NULL DEFAULT NULL,
  `side` VARCHAR(8) NOT NULL COMMENT 'BUY or SELL (close direction)',
  `order_id` BIGINT NULL DEFAULT NULL COMMENT 'Binance orderId',
  `closed_at` DATETIME NOT NULL COMMENT 'When position was closed',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_binance_pastpositions_api_key` (`api_key`),
  KEY `idx_binance_pastpositions_uni_id` (`uni_id`),
  KEY `idx_binance_pastpositions_symbol` (`symbol`),
  KEY `idx_binance_pastpositions_closed_at` (`closed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Closed Binance positions (append on exit)';
