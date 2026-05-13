-- Migration: Add unrealized_pnl to binance_accounts
-- Created: 2026-05-04
-- Description: Stores totalUnrealizedProfit from GET /fapi/v3/account, updated by fetch_balances.

ALTER TABLE `binance_accounts`
ADD COLUMN `unrealized_pnl` DECIMAL(20, 8) NULL DEFAULT NULL COMMENT 'totalUnrealizedProfit from Binance /fapi/v3/account' AFTER `balance`;
