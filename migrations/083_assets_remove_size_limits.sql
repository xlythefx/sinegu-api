-- Migration: Remove all size limits on assets (base_size / max_increments)
-- Created: 2026-07-27
--
-- Migration 037 added CHECK constraints capping base_size and max_increments at
-- 9999. Migration 038 dropped them, but was never applied on some environments,
-- so saving an asset with e.g. max_increments = 25500 violated the constraint.
-- With mysqli's default error mode that throws, PHP fatals, and the endpoint
-- returns an empty body ("Unexpected end of JSON input" in the dashboard).
--
-- 1) Drop the CHECK constraints (run via the PHP runner, which checks first —
--    MySQL has no DROP CONSTRAINT IF EXISTS).
-- 2) Widen both columns so large sizes are stored exactly.

ALTER TABLE `assets` MODIFY COLUMN `base_size` DECIMAL(20,6) NOT NULL DEFAULT 0.001000;
ALTER TABLE `assets` MODIFY COLUMN `max_increments` DECIMAL(20,3) NOT NULL DEFAULT 10.000;
