-- Migration: Add reason column to user_profile_helps (optional description of what help was provided).
-- Created for "Rate the help experience" flow.

ALTER TABLE `user_profile_helps`
ADD COLUMN `reason` VARCHAR(500) NULL DEFAULT NULL COMMENT 'Optional: what help they provided' AFTER `rating`;
