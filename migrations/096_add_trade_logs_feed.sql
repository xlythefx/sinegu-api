-- Migration 096: Add `feed` to trade_logs.
-- Created: 2026-10-19
--
-- binance-flask now has two signal feeds:
--   main  /binance_webhook       TradingView, every non-tester account
--   test  /binance_test_webhook  own secret, accounts owned by user_credentials.type='tester'
-- Each row records which feed produced it, so the Signal Log can tell test noise
-- from real trading and the engine-status "last signal" ignores the test feed.
--
-- DEFAULT 'main': every existing row, and every engine that does not send a feed
-- (Capital, IG, MEXC), reads as the main feed. flask/log_trade_signal.php only
-- writes the column when it exists, so the API can land before this migration.
--
-- Idempotent: guarded by INFORMATION_SCHEMA checks; also a no-op on a box without
-- trade_logs (084 not applied) instead of a bare ALTER that halts the run.

SET @has_table := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'trade_logs'
);

SET @has_feed := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'trade_logs'
    AND COLUMN_NAME = 'feed'
);
SET @ddl := IF(@has_table = 1 AND @has_feed = 0,
  'ALTER TABLE `trade_logs` ADD COLUMN `feed` VARCHAR(8) NOT NULL DEFAULT ''main'' COMMENT ''main | test (binance test feed)'' AFTER `category`',
  'DO 0'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_idx := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'trade_logs'
    AND INDEX_NAME = 'idx_trade_logs_feed_ts'
);
SET @ddl := IF(@has_table = 1 AND @has_idx = 0,
  'ALTER TABLE `trade_logs` ADD KEY `idx_trade_logs_feed_ts` (`feed`, `ts`)',
  'DO 0'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
