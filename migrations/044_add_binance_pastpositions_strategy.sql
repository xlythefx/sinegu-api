-- Migration: Add strategy column to binance_pastpositions
-- Created: 2026-04-01
-- Description: Optional text name of the trading strategy used for this closed position.

ALTER TABLE `binance_pastpositions`
ADD COLUMN `strategy` VARCHAR(100) NULL DEFAULT NULL
AFTER `closed_at`;

