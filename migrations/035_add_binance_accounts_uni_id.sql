-- Migration: Add uni_id to binance_accounts (tie to user_credentials)
-- Created: 2026-02-27
-- Description: Link each Binance account to a user via uni_id.

ALTER TABLE `binance_accounts`
ADD COLUMN `uni_id` VARCHAR(36) NULL COMMENT 'User ID from user_credentials (owner)' AFTER `api_key`,
ADD CONSTRAINT `fk_binance_accounts_uni_id` FOREIGN KEY (`uni_id`) REFERENCES `user_credentials` (`uni_id`) ON DELETE CASCADE ON UPDATE CASCADE,
ADD KEY `idx_binance_accounts_uni_id` (`uni_id`);
