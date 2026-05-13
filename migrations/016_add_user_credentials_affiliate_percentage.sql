-- Migration: Add affiliate_percentage to user_credentials
-- Description: Adds affiliate_percentage column after unrealized_percentage (percentage for affiliate/referral commission).

ALTER TABLE `user_credentials`
ADD COLUMN `affiliate_percentage` DECIMAL(5,2) NOT NULL DEFAULT 4.00 COMMENT 'Affiliate/referral commission percentage' AFTER `unrealized_percentage`;
