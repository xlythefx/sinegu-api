-- Migration: Allow multiple partial-release envelopes per (referrer, month).
-- Drops the unique constraint on (referrer_uni_id, month_year) and makes month_year nullable
-- so a single envelope can span multiple months when admin releases a checkbox-selected set.

ALTER TABLE `referrer_payouts`
  DROP INDEX `uq_referrer_payouts_referrer_month`;

ALTER TABLE `referrer_payouts`
  MODIFY COLUMN `month_year` CHAR(7) NULL COMMENT 'Representative period; NULL when envelope spans multiple months';
