-- Migration: Add unique (api_key, symbol, order_id) to binance_pastpositions for idempotent inserts.
-- Mirrors the (api_key, tran_id) pattern from migration 040 on binance_transactions.
-- Created: 2026-05-24

-- Dedupe any existing rows first (keep lowest id per (api_key, symbol, order_id)).
DELETE p1 FROM `binance_pastpositions` p1
INNER JOIN `binance_pastpositions` p2
  ON p1.`api_key`  = p2.`api_key`
 AND p1.`symbol`   = p2.`symbol`
 AND p1.`order_id` = p2.`order_id`
 AND p1.`id` > p2.`id`;

ALTER TABLE `binance_pastpositions`
ADD UNIQUE KEY `uq_binance_pastpositions_api_symbol_order` (`api_key`, `symbol`, `order_id`);
