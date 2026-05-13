-- Migration: Add IG.com session token columns to accounts
-- Created: 2026-04-24
-- Description: Stores CST and X-SECURITY-TOKEN for IG session reuse (valid ~6h after last use).
-- Run once; MySQL 5.7 compatible.

ALTER TABLE `accounts`
ADD COLUMN IF NOT EXISTS `ig_cst` VARCHAR(512) DEFAULT NULL COMMENT 'IG.com CST session token' AFTER `session_last_used_at`;

ALTER TABLE `accounts`
ADD COLUMN IF NOT EXISTS `ig_x_security_token` VARCHAR(512) DEFAULT NULL COMMENT 'IG.com X-SECURITY-TOKEN' AFTER `ig_cst`;

ALTER TABLE `accounts`
ADD COLUMN IF NOT EXISTS `ig_session_last_used_at` DATETIME DEFAULT NULL COMMENT 'When IG session was last refreshed or used' AFTER `ig_x_security_token`;
