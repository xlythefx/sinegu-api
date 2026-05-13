-- Migration: Remove 9999 limit and allow larger base_size (e.g. 50000)
-- Created: 2026-02-27
-- 1) Drop CHECK constraints if present (from 037) - run via PHP which checks first
-- 2) Widen base_size from decimal(10,6) to decimal(20,6)

ALTER TABLE `assets` MODIFY COLUMN `base_size` DECIMAL(20,6) NOT NULL DEFAULT 0.001000;
