-- Migration: Add type and broker columns to assets
-- Created: 2026-02-27
-- Description: Categorize assets by type (stock, crypto, forex, etc.) and broker.

ALTER TABLE `assets`
ADD COLUMN `type` VARCHAR(32) NULL COMMENT 'Asset type: stock, crypto, forex, commodity' AFTER `ticker`,
ADD COLUMN `broker` VARCHAR(64) NULL COMMENT 'Broker/exchange name (e.g. Binance, Capital.com)' AFTER `type`;
