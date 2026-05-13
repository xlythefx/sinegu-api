-- Migration 066: Add payment_method and proof_file to referrer_payouts
-- payment_method: 'crypto' (default, existing rows) or 'bank_wire'
-- proof_file: relative path to uploaded file (bank wire only)

ALTER TABLE `referrer_payouts`
  ADD COLUMN `payment_method` ENUM('crypto','bank_wire') NOT NULL DEFAULT 'crypto'
    COMMENT 'How the payout was sent' AFTER `status`,
  ADD COLUMN `proof_file` VARCHAR(500) DEFAULT NULL
    COMMENT 'Uploaded proof file path (bank wire only)' AFTER `payment_method`;
