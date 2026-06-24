-- Migration 081: Add `side` column to assets.
-- Allowed trade side for an asset: LONG (long-only), SHORT (short-only), ALL (both).
-- Created: 2026-06-24
ALTER TABLE `assets`
  ADD COLUMN `side` VARCHAR(8) NOT NULL DEFAULT 'ALL'
  COMMENT 'Allowed trade side: LONG, SHORT, ALL' AFTER `broker`;
