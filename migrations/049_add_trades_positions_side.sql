-- Migration: Add side (BUY / SELL) to trades and positions.
-- Created: 2026-04-06

ALTER TABLE `trades`
ADD COLUMN `side` ENUM('BUY','SELL') NULL DEFAULT NULL
AFTER `ticker`;

ALTER TABLE `positions`
ADD COLUMN `side` ENUM('BUY','SELL') NULL DEFAULT NULL
AFTER `ticker`;
