-- Global strategy enable/disable (admin + Flask webhook policy).
-- Canonical keys: VWAP-Deviation, mean_reversion, trend_momentum

CREATE TABLE IF NOT EXISTS `strategies` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `strategy_key` VARCHAR(64) NOT NULL COMMENT 'Canonical key, matches webhook/trades.strategy',
  `enabled` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1 = active, 0 = paused globally',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_strategy_key` (`strategy_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Global strategy toggles';

INSERT IGNORE INTO `strategies` (`strategy_key`, `enabled`) VALUES
  ('VWAP-Deviation', 1),
  ('mean_reversion', 1),
  ('trend_momentum', 1);
