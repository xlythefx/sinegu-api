-- Migration: Add status column to referrer_payouts table
-- Allows tracking payment state: pending, paid, rejected

ALTER TABLE `referrer_payouts`
  ADD COLUMN `status` ENUM('pending','paid','rejected') NOT NULL DEFAULT 'paid'
    COMMENT 'Payment release status'
  AFTER `paid_at`;
