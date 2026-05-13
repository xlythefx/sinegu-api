-- Migration: Add referral_source column to user_credentials table
-- Created: 2024-11-24
-- Note: Run this only if the table already exists without the referral_source column

-- Check if column exists before adding (run this in a stored procedure or manually verify)
ALTER TABLE `user_credentials` 
ADD COLUMN `referral_source` VARCHAR(255) DEFAULT NULL COMMENT 'Where user heard about us' AFTER `password`;

