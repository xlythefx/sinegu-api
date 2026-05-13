-- Migration: Add demo and enabled to binance_accounts
-- Created: 2026-02-27
-- Description: demo=0 (real), enabled=1 (active by default).

ALTER TABLE `binance_accounts`
ADD COLUMN `demo` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1=demo/test, 0=live' AFTER `deleted_at`,
ADD COLUMN `enabled` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1=enabled, 0=disabled' AFTER `demo`;
