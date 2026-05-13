-- Migration: Add asset_image column to assets
-- Description: Stores path/URL to a custom ticker logo (jpg/png/webp).
--              The frontend prioritizes this over hardcoded icon URLs and falls
--              back to the gradient letter avatar if missing or fails to load.

-- Note: this MySQL build doesn't support `ADD COLUMN IF NOT EXISTS`.
-- Run the matching PHP runner (run_assets_asset_image_migration.php) which checks first.
ALTER TABLE `assets`
ADD COLUMN `asset_image` VARCHAR(500) DEFAULT NULL COMMENT 'Path/URL to custom ticker logo' AFTER `broker`;
