-- Migration: Create referrer_payout_items table
-- Description: Per-invoice line items for an admin-released referrer payout. Each row = one
-- (referrer, referred_user, broker, month_year) pair. Enables partial / per-invoice releases
-- across capital_com (billing_periods), binance (binance_invoices), and ig (ig_invoices).

CREATE TABLE IF NOT EXISTS `referrer_payout_items` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `payout_id` INT(11) UNSIGNED NOT NULL COMMENT 'FK referrer_payouts.id (envelope/transaction)',
  `referrer_uni_id` VARCHAR(255) NOT NULL,
  `referred_user_uni_id` VARCHAR(255) NOT NULL,
  `broker` ENUM('capital_com','binance','ig') NOT NULL,
  `month_year` CHAR(7) NOT NULL COMMENT 'YYYY-MM',
  `fee_paid` DECIMAL(20,8) NOT NULL COMMENT 'Underlying invoice total_fee that was paid',
  `commission` DECIMAL(20,8) NOT NULL COMMENT 'fee_paid * affiliate_percentage / 100 at release time',
  `released_at` DATETIME NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_rpi_referral_invoice` (`referrer_uni_id`, `referred_user_uni_id`, `broker`, `month_year`),
  KEY `idx_rpi_payout` (`payout_id`),
  KEY `idx_rpi_referrer` (`referrer_uni_id`),
  CONSTRAINT `fk_rpi_payout` FOREIGN KEY (`payout_id`) REFERENCES `referrer_payouts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Per-invoice line items released within a referrer payout envelope';
