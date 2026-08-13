-- Migration: add is_read to ig_api_logs.
--
-- ig_api_logs (056) was created as a mirror of capital_api_logs (051), but when
-- capital_api_logs later gained an is_read flag so admins could clear handled
-- events, IG never got the same column — and it never got a read endpoint
-- either, so nobody noticed the table was write-only.
--
-- admin/GET/get_ig_api_logs.php mirrors get_capital_api_logs.php, which hides
-- read rows by default, so it needs this column to exist.
--
-- Note the Capital equivalent was applied by an UNNUMBERED migration
-- (run_capital_api_logs_is_read_migration.php), which the numbered runner skips
-- — that is exactly how the VPS ended up 500ing on the Capital log endpoints.
-- This one is numbered so `Run pending migrations` actually applies it.
--
-- Idempotent: the ADD is guarded by an information_schema check, because MySQL
-- has no ADD COLUMN IF NOT EXISTS.

SET @col_exists = (
    SELECT COUNT(*)
      FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'ig_api_logs'
       AND COLUMN_NAME  = 'is_read'
);

SET @ddl = IF(
    @col_exists = 0,
    'ALTER TABLE ig_api_logs
        ADD COLUMN is_read TINYINT(1) NOT NULL DEFAULT 0
            COMMENT ''1 = an admin has acknowledged this event'',
        ADD KEY idx_ig_api_logs_is_read (is_read)',
    'SELECT 1'
);

PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
