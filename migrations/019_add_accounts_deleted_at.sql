-- Migration: Add soft-delete column to accounts (disconnect = set deleted_at; reconnect same api_key clears it)
-- Run once: ALTER TABLE accounts ADD COLUMN deleted_at DATETIME NULL DEFAULT NULL COMMENT 'Set when user disconnects; NULL = active';

ALTER TABLE `accounts`
ADD COLUMN `deleted_at` DATETIME NULL DEFAULT NULL COMMENT 'Set when user disconnects or soft-deletes. NULL = active' AFTER `session_last_used_at`;
