-- Migration: Add Capital.com session token columns to accounts
-- Description: Stores CST and X-SECURITY-TOKEN for session reuse (valid 10 min after last use)
-- Run once; runner checks for existing columns before adding (MySQL 5.7 compatible).

ALTER TABLE `accounts`
ADD COLUMN `cst` VARCHAR(512) DEFAULT NULL COMMENT 'Capital.com CST session token' AFTER `updated_at`;

ALTER TABLE `accounts`
ADD COLUMN `x_security_token` VARCHAR(512) DEFAULT NULL COMMENT 'Capital.com X-SECURITY-TOKEN' AFTER `cst`;

ALTER TABLE `accounts`
ADD COLUMN `session_last_used_at` DATETIME DEFAULT NULL COMMENT 'When session was last refreshed or used' AFTER `x_security_token`;
