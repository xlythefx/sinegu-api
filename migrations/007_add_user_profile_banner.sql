-- Migration: Add user_profile and user_banner columns to user_credentials
-- Created: 2025-01-05
-- Description: Adds profile image and banner image columns for user profiles

-- Add user_profile column (stores path/URL to profile image)
ALTER TABLE `user_credentials`
ADD COLUMN IF NOT EXISTS `user_profile` VARCHAR(500) DEFAULT NULL COMMENT 'Path/URL to user profile image' AFTER `unrealized_percentage`;

-- Add user_banner column (stores path/URL to banner image)
ALTER TABLE `user_credentials`
ADD COLUMN IF NOT EXISTS `user_banner` VARCHAR(500) DEFAULT NULL COMMENT 'Path/URL to user banner image' AFTER `user_profile`;

