-- Migration: Add affiliate_link to bot_config
ALTER TABLE `bot_config`
ADD COLUMN `affiliate_link` VARCHAR(500) DEFAULT NULL COMMENT 'Affiliate/referral link for the bot' AFTER `email_sending`;
