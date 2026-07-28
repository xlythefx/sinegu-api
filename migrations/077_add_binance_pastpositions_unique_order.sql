-- Migration: Add unique (api_key, symbol, order_id) to binance_pastpositions for idempotent inserts.
-- Mirrors the (api_key, tran_id) pattern from migration 040 on binance_transactions.
-- Created: 2026-05-24
--
-- Made idempotent 2026-07-28: staging already had the index (added out-of-band)
-- while schema_migrations held no record of 077, so a plain ADD UNIQUE KEY aborted
-- the whole pending run with "Duplicate key name" and nothing after it applied.
-- MySQL has no ADD KEY IF NOT EXISTS, hence the INFORMATION_SCHEMA guard driving a
-- prepared statement. The dedupe below stays: it is required when the index is
-- genuinely missing, and is provably a no-op once the index exists (the index is
-- what prevents duplicates in the first place).

-- Dedupe any existing rows first (keep lowest id per (api_key, symbol, order_id)).
DELETE p1 FROM `binance_pastpositions` p1
INNER JOIN `binance_pastpositions` p2
  ON p1.`api_key`  = p2.`api_key`
 AND p1.`symbol`   = p2.`symbol`
 AND p1.`order_id` = p2.`order_id`
 AND p1.`id` > p2.`id`;

SET @has_key := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'binance_pastpositions'
    AND INDEX_NAME = 'uq_binance_pastpositions_api_symbol_order'
);

SET @ddl := IF(@has_key = 0,
  'ALTER TABLE `binance_pastpositions` ADD UNIQUE KEY `uq_binance_pastpositions_api_symbol_order` (`api_key`, `symbol`, `order_id`)',
  'DO 0'
);

PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
