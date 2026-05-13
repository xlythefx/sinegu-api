-- Migration 065: Create bank_wire_accounts table
-- Stores user bank wire payout accounts. Multiple allowed per user.
-- Fields vary by currency: USD uses routing+account+swift; EUR uses IBAN+swift.

CREATE TABLE IF NOT EXISTS `bank_wire_accounts` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `uni_id` VARCHAR(36) NOT NULL COMMENT 'User ID from user_credentials table (owner)',
  `label` VARCHAR(255) DEFAULT NULL COMMENT 'User-defined label, e.g. My BDO Account',
  `currency` VARCHAR(10) NOT NULL DEFAULT 'USD' COMMENT 'ISO 4217 currency code (USD, EUR, etc.)',
  `name` VARCHAR(255) NOT NULL COMMENT 'Account holder name',
  `bank_name` VARCHAR(255) NOT NULL COMMENT 'Bank name',
  `bank_address` VARCHAR(500) DEFAULT NULL COMMENT 'Bank name and address',
  `account_type` VARCHAR(64) DEFAULT NULL COMMENT 'e.g. Checking, Savings (USD only)',
  `routing_number` VARCHAR(64) DEFAULT NULL COMMENT 'US routing number (USD only)',
  `account_number` VARCHAR(255) DEFAULT NULL COMMENT 'Bank account number (USD only)',
  `swift_bic` VARCHAR(64) DEFAULT NULL COMMENT 'SWIFT/BIC code (USD and EUR)',
  `iban` VARCHAR(64) DEFAULT NULL COMMENT 'IBAN (EUR only)',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_bwa_uni_id` (`uni_id`),
  CONSTRAINT `fk_bwa_uni_id` FOREIGN KEY (`uni_id`) REFERENCES `user_credentials` (`uni_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='User bank wire payout accounts (multiple per user)';
