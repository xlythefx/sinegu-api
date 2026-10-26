-- Migration 097: Add `size` and `entry_price` columns to trades (Capital.com closed trades).
-- Created: 2026-10-26
--
-- Why this exists: closed positions on the dashboard now show "size @ entry → exit".
-- binance_pastpositions / mexc_pastpositions (position_amt, entry_price, exit_price) and
-- ig_past_positions (size, open_price, close_price) already carry all three. `trades`
-- only stored the exit `price` and how many deals closed (`closed_positions`), so a
-- Capital row had no size and no entry.
--
-- size        = total size of the deals the exit actually closed (deals that failed to
--               close are still open and excluded).
-- entry_price = their size-weighted open level.
-- Both are sent by CapitalFlask's handle_exit -> flask/insert_trade.php. NULL on every
-- row written before that change; the dashboard shows "—" for those.
--
-- Idempotent via the INFORMATION_SCHEMA guard (MySQL has no ADD COLUMN IF NOT EXISTS),
-- matching 092, so a re-run is a no-op instead of a duplicate-column error.

SET @has_size := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'trades'
    AND COLUMN_NAME = 'size'
);

SET @ddl := IF(@has_size = 0,
  'ALTER TABLE `trades` ADD COLUMN `size` DECIMAL(20,8) NULL DEFAULT NULL COMMENT ''Total size of the closed deals; NULL before mig 097'' AFTER `price`',
  'DO 0'
);

PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_entry := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'trades'
    AND COLUMN_NAME = 'entry_price'
);

SET @ddl := IF(@has_entry = 0,
  'ALTER TABLE `trades` ADD COLUMN `entry_price` DECIMAL(20,8) NULL DEFAULT NULL COMMENT ''Size-weighted open level of the closed deals; NULL before mig 097'' AFTER `size`',
  'DO 0'
);

PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
