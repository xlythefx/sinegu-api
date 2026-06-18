-- Migration: Allow decimal max_increments (Binance "Max Position Size")
-- Created: 2026-06-18
-- For Binance assets, max_increments represents a Max Position Size which can be
-- a fractional value (e.g. 0.5, 0.002). Widen the column from INT to DECIMAL(10,3)
-- so 3-decimal-place values can be stored. Existing integer rows (5 -> 5.000) are
-- preserved. Capital/IG continue to store whole numbers.

ALTER TABLE `assets` MODIFY COLUMN `max_increments` DECIMAL(10,3) NOT NULL DEFAULT 10.000;
