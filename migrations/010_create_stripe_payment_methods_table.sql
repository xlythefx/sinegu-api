-- Migration: Create stripe_payment_methods table
-- Created: 2026-01-28
-- Description: Creates table for storing Stripe payment methods linked to users for auto-charging

-- Table: stripe_payment_methods
-- Stores Stripe payment methods linked to users via uni_id for automated invoice charging
CREATE TABLE IF NOT EXISTS `stripe_payment_methods` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `uni_id` VARCHAR(36) NOT NULL COMMENT 'User ID from user_credentials table',
  `stripe_customer_id` VARCHAR(255) NOT NULL COMMENT 'Stripe Customer ID',
  `stripe_payment_method_id` VARCHAR(255) NOT NULL COMMENT 'Stripe PaymentMethod ID',
  `payment_method_type` VARCHAR(50) NOT NULL DEFAULT 'card' COMMENT 'Payment method type (card, etc.)',
  `card_brand` VARCHAR(50) DEFAULT NULL COMMENT 'Card brand (visa, mastercard, amex, etc.)',
  `card_last4` VARCHAR(4) DEFAULT NULL COMMENT 'Last 4 digits of card',
  `card_exp_month` INT(2) DEFAULT NULL COMMENT 'Card expiration month (1-12)',
  `card_exp_year` INT(4) DEFAULT NULL COMMENT 'Card expiration year',
  `is_default` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Default payment method flag (1 = default, 0 = not default)',
  `is_active` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Active flag (1 = active, 0 = inactive/deleted)',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Creation timestamp',
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Last update timestamp',
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_stripe_payment_method` (`stripe_payment_method_id`),
  KEY `idx_uni_id` (`uni_id`),
  KEY `idx_stripe_customer_id` (`stripe_customer_id`),
  KEY `idx_is_default` (`uni_id`, `is_default`),
  KEY `idx_is_active` (`uni_id`, `is_active`),
  CONSTRAINT `fk_stripe_payment_methods_uni_id` FOREIGN KEY (`uni_id`) REFERENCES `user_credentials` (`uni_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Stripe payment methods for automated invoice charging';
