-- Migration: Add ai_api to bot_config (optional AI endpoint or key reference)

ALTER TABLE `bot_config`
ADD COLUMN `ai_api` VARCHAR(512) NULL DEFAULT NULL COMMENT 'AI API URL or identifier' AFTER `affiliate_link`;
