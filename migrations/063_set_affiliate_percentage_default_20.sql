-- Migration 063
-- Set affiliate_percentage default to 20.00 and backfill legacy 4.00 rows.

ALTER TABLE `user_credentials`
MODIFY COLUMN `affiliate_percentage` DECIMAL(5,2) NOT NULL DEFAULT 20.00 COMMENT 'Affiliate/referral commission percentage';

-- Optional backfill: move old defaulted users from 4.00 to 20.00.
UPDATE `user_credentials`
SET `affiliate_percentage` = 20.00
WHERE `affiliate_percentage` = 4.00;
