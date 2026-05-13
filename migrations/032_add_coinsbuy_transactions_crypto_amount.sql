-- Migration: Add crypto_amount column to coinsbuy_transactions
-- Created: 2026-02-23
-- Description: Store the actual cryptocurrency amount sent (e.g. 0.0154763 BTC)

ALTER TABLE coinsbuy_transactions
ADD COLUMN crypto_amount DECIMAL(20,8) NULL COMMENT 'Actual crypto sent, e.g. 0.0154763 BTC' AFTER crypto_currency;
