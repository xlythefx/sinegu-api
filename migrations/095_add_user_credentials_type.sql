-- Migration 095: Add `type` (account type) to user_credentials.
-- Created: 2026-10-19
--
-- Until now admin access was a hardcoded email list in the dashboard bundle
-- (AdminRouteGuard, allowed-admin-switch-emails, allowed-api-keys-emails) and one
-- more in admin/POST/chatbot.php. The account's type now lives on the row, the
-- same shape as Pixel Alpha's user_credentials.type:
--
--   user          a customer — the default; the ONLY type that is invoiced or
--                 counted in customer stats
--   admin         full admin portal (labelled "Administrator" in the UI)
--   developer     admin + the developer-only pages (API Keys, Database)
--   collaborator  full admin portal for now (a label; may be narrowed later)
--   tester        admin portal, and its Binance accounts trade ONLY the separate
--                 test feed (/binance_test_webhook) — never the main TradingView
--                 webhook. See binance-flask CLAUDE.md "Tester type + test feed".
--
-- ENUM, so a typo can never be stored. Everyone defaults to 'user'; the seed below
-- promotes the two operators who held admin access through the old email list, so
-- the portal is not locked out the moment this lands. LOWER() because Dima's row is
-- stored with a capital D. The seed only touches rows still at 'user', so re-running
-- it never overwrites a type set later from the Users page.
--
-- Idempotent: the column and index are guarded by INFORMATION_SCHEMA checks (MySQL
-- has no ADD COLUMN IF NOT EXISTS), matching 092/094.

SET @has_type := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'user_credentials'
    AND COLUMN_NAME = 'type'
);

SET @ddl := IF(@has_type = 0,
  'ALTER TABLE `user_credentials` ADD COLUMN `type` ENUM(''user'',''admin'',''developer'',''collaborator'',''tester'') NOT NULL DEFAULT ''user'' COMMENT ''Account type: user | admin | developer | collaborator | tester'' AFTER `status`',
  'DO 0'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_idx := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'user_credentials'
    AND INDEX_NAME = 'idx_user_credentials_type'
);
SET @ddl := IF(@has_idx = 0,
  'ALTER TABLE `user_credentials` ADD KEY `idx_user_credentials_type` (`type`)',
  'DO 0'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

UPDATE `user_credentials`
SET `type` = 'admin'
WHERE LOWER(`email`) IN ('eliascaffi.ec@gmail.com', 'dimamarko2@gmail.com')
  AND `type` = 'user';
