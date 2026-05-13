-- Migration 067: Add `is_main` flag to payout methods
-- Allows a user to mark exactly one payout method (crypto wallet or bank wire account)
-- as their preferred / "main" payout destination. Toggled atomically by
-- users/set_main_payout_method.php.
--
-- Idempotent: uses INFORMATION_SCHEMA to skip ADD COLUMN if already present.

SET @db := DATABASE();

SET @col_exists := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'crypto_wallets' AND COLUMN_NAME = 'is_main'
);
SET @stmt := IF(@col_exists = 0,
  'ALTER TABLE `crypto_wallets` ADD COLUMN `is_main` TINYINT(1) NOT NULL DEFAULT 0 AFTER `name`',
  'SELECT 1');
PREPARE s1 FROM @stmt; EXECUTE s1; DEALLOCATE PREPARE s1;

SET @col_exists := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'bank_wire_accounts' AND COLUMN_NAME = 'is_main'
);
SET @stmt := IF(@col_exists = 0,
  'ALTER TABLE `bank_wire_accounts` ADD COLUMN `is_main` TINYINT(1) NOT NULL DEFAULT 0 AFTER `iban`',
  'SELECT 1');
PREPARE s2 FROM @stmt; EXECUTE s2; DEALLOCATE PREPARE s2;

-- Backfill: if a user has only a crypto wallet and no bank wires, mark the crypto as main.
UPDATE `crypto_wallets` cw
LEFT JOIN `bank_wire_accounts` bwa ON bwa.uni_id = cw.uni_id
SET cw.is_main = 1
WHERE bwa.id IS NULL AND cw.is_main = 0;
