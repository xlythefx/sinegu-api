-- Migration: Add strategy column to positions (optional strategy name, aligned with trades.strategy).
-- Created: 2026-04-06

ALTER TABLE `positions`
ADD COLUMN `strategy` VARCHAR(100) NULL DEFAULT NULL
AFTER `increments`;
