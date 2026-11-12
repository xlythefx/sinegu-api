-- Migration 104: API-key health on binance_accounts.
-- Created: 2026-11-12
--
-- binance-flask now reports when Binance REFUSES an account's credentials (-2015
-- "Invalid API-key, IP, or permissions", -2014, -2008, -1022) and when they work
-- again, via binance-flask/report_key_status.php. Until now a dead key stayed in
-- every fan-out: each exit failed, was retried 5x, and opened a "position may still
-- be OPEN" incident, on every signal, for days.
--
--   key_status         NULL never reported | 'ok' | 'blocked'
--   key_blocked_scope  'read'  = reads are refused too: the key is dead or our IP is
--                                not on its allow-list
--                      'trade' = reads work, orders are refused: futures permission
--   key_blocked_at     start of the CURRENT refusal streak. Set only on the
--                      transition into blocked, never by a repeat report, so the
--                      grace clock cannot be pushed forward forever.
--   key_error_code / key_error_message   Binance's own code + text
--   key_checked_at     last report from the engine
--   key_disabled_at    when report_key_status.php turned the account OFF
--                      (enabled = 0) after the grace period. Kept for the admin;
--                      cleared when the key works again or the clock restarts.
--
-- The engine skips a blocked account at once; the turn-off after the grace period is
-- what frees it from the list (and tells the admin). Pattern from Pixel Alpha's
-- ExchangeAccount key_status / key_blocked_at.
--
-- Idempotent: every column guarded by an INFORMATION_SCHEMA check; a no-op on a box
-- without binance_accounts. report_key_status.php and fetch_binance_accounts.php check
-- for the columns, so the API can land before this migration.

SET @has_table := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'binance_accounts'
);

SET @has := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'binance_accounts' AND COLUMN_NAME = 'key_status');
SET @ddl := IF(@has_table = 1 AND @has = 0,
  'ALTER TABLE `binance_accounts` ADD COLUMN `key_status` VARCHAR(8) NULL DEFAULT NULL COMMENT ''ok | blocked (engine-reported, mig 104)''',
  'DO 0');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'binance_accounts' AND COLUMN_NAME = 'key_blocked_scope');
SET @ddl := IF(@has_table = 1 AND @has = 0,
  'ALTER TABLE `binance_accounts` ADD COLUMN `key_blocked_scope` VARCHAR(8) NULL DEFAULT NULL COMMENT ''read | trade''',
  'DO 0');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'binance_accounts' AND COLUMN_NAME = 'key_blocked_at');
SET @ddl := IF(@has_table = 1 AND @has = 0,
  'ALTER TABLE `binance_accounts` ADD COLUMN `key_blocked_at` DATETIME NULL DEFAULT NULL COMMENT ''start of the current refusal streak''',
  'DO 0');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'binance_accounts' AND COLUMN_NAME = 'key_error_code');
SET @ddl := IF(@has_table = 1 AND @has = 0,
  'ALTER TABLE `binance_accounts` ADD COLUMN `key_error_code` VARCHAR(16) NULL DEFAULT NULL',
  'DO 0');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'binance_accounts' AND COLUMN_NAME = 'key_error_message');
SET @ddl := IF(@has_table = 1 AND @has = 0,
  'ALTER TABLE `binance_accounts` ADD COLUMN `key_error_message` VARCHAR(255) NULL DEFAULT NULL',
  'DO 0');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'binance_accounts' AND COLUMN_NAME = 'key_checked_at');
SET @ddl := IF(@has_table = 1 AND @has = 0,
  'ALTER TABLE `binance_accounts` ADD COLUMN `key_checked_at` DATETIME NULL DEFAULT NULL',
  'DO 0');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'binance_accounts' AND COLUMN_NAME = 'key_disabled_at');
SET @ddl := IF(@has_table = 1 AND @has = 0,
  'ALTER TABLE `binance_accounts` ADD COLUMN `key_disabled_at` DATETIME NULL DEFAULT NULL COMMENT ''auto turned off (enabled=0) after the grace period''',
  'DO 0');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
