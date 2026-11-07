-- Migration 102: allow broker = 'bybit' in trade_logs and trade_incidents.
-- Created: 2026-11-07
--
-- Both tables keep broker as an ENUM — trade_logs since 084 (+ 'mexc' in 091),
-- trade_incidents since 093. The Bybit bot (flask/bybit) posts its signal log through
-- flask/log_trade_signal.php and its incidents through flask/log_trade_incident.php
-- with broker 'bybit'; under STRICT_TRANS_TABLES a value outside the ENUM refuses the
-- whole INSERT, so the signal or incident would never be recorded.
-- (capital_retry_queue.broker is VARCHAR(16) since 094 and needs no change.)
--
-- Appending a value to an ENUM is an in-place metadata change. Each MODIFY only runs
-- when the table exists, the column does not already allow 'bybit', and its current
-- type is one of the known shapes — so a re-run, a box without the table, or a column
-- someone has since widened differently is a no-op rather than an ALTER that could drop
-- a value or halt the migration run.

-- 1. trade_logs.broker
SET @cur := (
  SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'trade_logs'
    AND COLUMN_NAME = 'broker'
);
SET @ddl := IF(@cur IN ('enum(''capital'',''binance'',''ig'')', 'enum(''capital'',''binance'',''ig'',''mexc'')'),
  'ALTER TABLE `trade_logs` MODIFY `broker` ENUM(''capital'',''binance'',''ig'',''mexc'',''bybit'') NOT NULL',
  'DO 0'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 2. trade_incidents.broker
SET @cur := (
  SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'trade_incidents'
    AND COLUMN_NAME = 'broker'
);
SET @ddl := IF(@cur = 'enum(''capital'',''binance'',''ig'',''mexc'')',
  'ALTER TABLE `trade_incidents` MODIFY `broker` ENUM(''capital'',''binance'',''ig'',''mexc'',''bybit'') NOT NULL',
  'DO 0'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
