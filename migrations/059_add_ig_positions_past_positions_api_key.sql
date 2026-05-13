-- Migration: Add api_key to ig_positions and ig_past_positions and link to ig_accounts.
-- Created: 2026-04-24

ALTER TABLE `ig_positions`
ADD COLUMN `api_key` VARCHAR(128) NULL DEFAULT NULL
AFTER `account_id`;

ALTER TABLE `ig_past_positions`
ADD COLUMN `api_key` VARCHAR(128) NULL DEFAULT NULL
AFTER `account_id`;

UPDATE `ig_positions` p
LEFT JOIN `ig_accounts` ia ON ia.id = p.account_id
SET p.api_key = ia.api_key
WHERE p.api_key IS NULL;

UPDATE `ig_past_positions` p
LEFT JOIN `ig_accounts` ia ON ia.id = p.account_id
SET p.api_key = ia.api_key
WHERE p.api_key IS NULL;

ALTER TABLE `ig_positions`
MODIFY COLUMN `api_key` VARCHAR(128) NOT NULL;

ALTER TABLE `ig_past_positions`
MODIFY COLUMN `api_key` VARCHAR(128) NOT NULL;

ALTER TABLE `ig_positions`
ADD KEY `idx_ig_positions_api_key` (`api_key`);

ALTER TABLE `ig_past_positions`
ADD KEY `idx_ig_past_positions_api_key` (`api_key`);

ALTER TABLE `ig_positions`
ADD CONSTRAINT `fk_ig_positions_api_key`
FOREIGN KEY (`api_key`) REFERENCES `ig_accounts` (`api_key`)
ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE `ig_past_positions`
ADD CONSTRAINT `fk_ig_past_positions_api_key`
FOREIGN KEY (`api_key`) REFERENCES `ig_accounts` (`api_key`)
ON DELETE CASCADE ON UPDATE CASCADE;
