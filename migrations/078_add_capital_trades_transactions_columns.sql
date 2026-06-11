-- Migration: add side / deal_id / unrealized_pnl / strategy columns to
-- capital_trades_transactions. The bot sends these on every entry/exit; without
-- them the INSERT throws (PHP 8 mysqli) and the transaction is never recorded.
-- Idempotent: each column is added only if missing (safe to re-run / partial state).
-- Converted from 078_run_capital_trades_transactions_columns_migration.php (2026-05-28).

SET @col := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'capital_trades_transactions' AND COLUMN_NAME = 'side');
SET @ddl := IF(@col = 0,
  "ALTER TABLE `capital_trades_transactions` ADD COLUMN `side` VARCHAR(8) DEFAULT NULL COMMENT 'BUY/SELL (entry) or LONG/SHORT (exit leg)'",
  'SELECT 1');
PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;

SET @col := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'capital_trades_transactions' AND COLUMN_NAME = 'deal_id');
SET @ddl := IF(@col = 0,
  "ALTER TABLE `capital_trades_transactions` ADD COLUMN `deal_id` VARCHAR(512) DEFAULT NULL COMMENT 'Capital deal id; comma-separated on exit'",
  'SELECT 1');
PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;

SET @col := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'capital_trades_transactions' AND COLUMN_NAME = 'unrealized_pnl');
SET @ddl := IF(@col = 0,
  "ALTER TABLE `capital_trades_transactions` ADD COLUMN `unrealized_pnl` DECIMAL(15,2) DEFAULT NULL COMMENT 'Open PnL snapshot at entry'",
  'SELECT 1');
PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;

SET @col := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'capital_trades_transactions' AND COLUMN_NAME = 'strategy');
SET @ddl := IF(@col = 0,
  "ALTER TABLE `capital_trades_transactions` ADD COLUMN `strategy` VARCHAR(128) DEFAULT NULL COMMENT 'Strategy label from webhook'",
  'SELECT 1');
PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;
