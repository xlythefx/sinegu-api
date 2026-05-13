-- Migration: Add unrealized_pnl to ig_accounts
-- Created: 2026-05-04
-- Description: Stores balance.profitLoss from IG GET /accounts, updated by update_account_balance.php.

ALTER TABLE `ig_accounts`
ADD COLUMN `unrealized_pnl` DECIMAL(20, 8) NULL DEFAULT NULL COMMENT 'balance.profitLoss from IG GET /accounts' AFTER `balance`;
