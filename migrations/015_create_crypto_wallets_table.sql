-- Migration: Create crypto_wallets table
-- Description: One crypto wallet per user (uni_id), with network, address, and optional name.

-- Table: crypto_wallets
-- Stores user crypto wallet (one per user) linked via uni_id to user_credentials
CREATE TABLE IF NOT EXISTS `crypto_wallets` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `uni_id` VARCHAR(36) NOT NULL COMMENT 'User ID from user_credentials table (owner)',
  `network` VARCHAR(64) NOT NULL COMMENT 'Blockchain/network id (e.g. ethereum, polygon, bitcoin, solana)',
  `address` VARCHAR(255) NOT NULL COMMENT 'Wallet public address',
  `name` VARCHAR(255) DEFAULT NULL COMMENT 'User-defined name for the wallet',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Creation timestamp',
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Last update timestamp',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_crypto_wallets_uni_id` (`uni_id`),
  KEY `idx_uni_id` (`uni_id`),
  KEY `idx_network` (`network`),
  CONSTRAINT `fk_crypto_wallets_uni_id` FOREIGN KEY (`uni_id`) REFERENCES `user_credentials` (`uni_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='User crypto wallets (one per user)';
