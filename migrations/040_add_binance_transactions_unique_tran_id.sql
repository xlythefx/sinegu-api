-- Migration: Add unique (api_key, tran_id) to binance_transactions to prevent duplicate inserts.
-- Created: 2026-02-27

ALTER TABLE `binance_transactions`
ADD UNIQUE KEY `uq_binance_transactions_api_key_tran_id` (`api_key`, `tran_id`);
