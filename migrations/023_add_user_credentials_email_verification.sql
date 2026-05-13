-- Migration: Add email verification columns to user_credentials
ALTER TABLE `user_credentials`
ADD COLUMN `email_verified` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = email verified, 0 = pending' AFTER `status`,
ADD COLUMN `verification_code` VARCHAR(6) DEFAULT NULL COMMENT '6-digit email verification code' AFTER `email_verified`,
ADD COLUMN `verification_code_expires_at` DATETIME DEFAULT NULL AFTER `verification_code`,
ADD COLUMN `verification_code_attempts` INT NOT NULL DEFAULT 0 AFTER `verification_code_expires_at`;
