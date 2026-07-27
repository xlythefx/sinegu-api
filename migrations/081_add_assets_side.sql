-- Migration 081: Add `side` column to assets.
-- Allowed trade side for an asset: LONG (long-only), SHORT (short-only), ALL (both).
-- Created: 2026-06-24
--
-- Made idempotent 2026-07-27: prod already had the column (added out-of-band) while
-- schema_migrations held no record of 081, so a plain ADD COLUMN aborted the whole
-- pending run with "Duplicate column name 'side'" and nothing after it applied.
-- MySQL has no ADD COLUMN IF NOT EXISTS, hence the INFORMATION_SCHEMA guard driving
-- a prepared statement. Re-running where the column already exists is now a no-op.

SET @has_side := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'assets'
    AND COLUMN_NAME = 'side'
);

SET @ddl := IF(@has_side = 0,
  'ALTER TABLE `assets` ADD COLUMN `side` VARCHAR(8) NOT NULL DEFAULT ''ALL'' COMMENT ''Allowed trade side: LONG, SHORT, ALL'' AFTER `broker`',
  'DO 0'
);

PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
