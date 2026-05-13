-- Migration: Add strategy column to trades
-- Created: 2026-03-30
-- Description: Optional text name of the trading strategy used for this trade.

ALTER TABLE `trades`
ADD COLUMN `strategy` VARCHAR(100) NULL DEFAULT NULL
AFTER `closed_positions`;