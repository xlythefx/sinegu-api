-- Migration: Create referrer_payouts table
-- Description: One row per referrer per month when admin has released USDT payout (proof + amount).

CREATE TABLE IF NOT EXISTS `referrer_payouts` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `referrer_uni_id` VARCHAR(255) NOT NULL COMMENT 'Referrer user_credentials.uni_id',
  `month_year` CHAR(7) NOT NULL COMMENT 'Period e.g. 2026-01 (same as billing_periods.month_year)',
  `total_amount` DECIMAL(12,2) NOT NULL COMMENT 'USD amount released (audit)',
  `payout_address` VARCHAR(255) NOT NULL COMMENT 'Crypto address used (e.g. USDT TRC20)',
  `tx_hash` VARCHAR(255) DEFAULT NULL COMMENT 'Blockchain transaction id as proof',
  `paid_at` DATETIME NOT NULL COMMENT 'When admin confirmed release',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_referrer_payouts_referrer_month` (`referrer_uni_id`, `month_year`),
  KEY `idx_referrer_uni_id` (`referrer_uni_id`),
  KEY `idx_month_year` (`month_year`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Referrer USDT payouts released by admin (one per referrer per month)';
