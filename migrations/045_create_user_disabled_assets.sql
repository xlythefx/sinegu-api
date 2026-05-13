-- Migration: Per-user disabled trading assets (Flask skips ADD/SELL for this uni_id + ticker).
-- Created: 2026-04-02
-- When a row exists, the user has opted out of that asset; global `assets.enabled` still applies.

CREATE TABLE IF NOT EXISTS `user_disabled_assets` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uni_id` VARCHAR(36) NOT NULL COMMENT 'user_credentials.uni_id',
  `ticker` VARCHAR(64) NOT NULL COMMENT 'Same as assets.ticker (typically uppercase)',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_user_disabled_asset` (`uni_id`, `ticker`),
  KEY `idx_user_disabled_assets_uni_id` (`uni_id`),
  KEY `idx_user_disabled_assets_ticker` (`ticker`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='User opted-out of trading this ticker (per-user)';
