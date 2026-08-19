-- Migration 086: Add `description` column to strategies.
-- Admin-written prose shown on the strategy details page (/admin/strategies/<key>).
-- Created: 2026-08-19
--
-- Idempotent via an INFORMATION_SCHEMA guard (MySQL has no ADD COLUMN IF NOT EXISTS),
-- same pattern as 081_add_assets_side.sql — re-running where the column already exists
-- is a no-op rather than aborting the whole pending run with "Duplicate column name".
--
-- NOTE: strategies_list.php treats `description` as a REQUIRED column and 500s with
-- STRATEGIES_SCHEMA_MISMATCH if it is absent, so run this BEFORE deploying that API change.

SET @has_description := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'strategies'
    AND COLUMN_NAME = 'description'
);

SET @ddl := IF(@has_description = 0,
  'ALTER TABLE `strategies` ADD COLUMN `description` TEXT NULL DEFAULT NULL COMMENT ''Admin-written strategy description (markdown-free plain text)'' AFTER `enabled`',
  'DO 0'
);

PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
