-- Migration: Add deal_id to positions (broker deal identifier for the open position row).
-- Created: 2026-04-06

ALTER TABLE `positions`
ADD COLUMN `deal_id` VARCHAR(255) NULL DEFAULT NULL
AFTER `ticker`;
