-- Migration: Enforce max 9999 on assets base_size and max_increments
-- Created: 2026-02-27
-- Description: CHECK constraints so size-related columns cannot exceed 9999.

ALTER TABLE `assets`
ADD CONSTRAINT `chk_assets_base_size_max` CHECK (`base_size` <= 9999),
ADD CONSTRAINT `chk_assets_max_increments_max` CHECK (`max_increments` <= 9999);
