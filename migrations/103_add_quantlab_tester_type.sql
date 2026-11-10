-- Migration 103: Add the `quantlab_tester` account type to user_credentials.type.
-- Created: 2026-11-10
--
-- A third signal feed, next to main and test: QuantLab's alerts post to
-- /binance_quantlab_webhook and /bybit_quantlab_webhook, and only accounts owned by a
-- `quantlab_tester` trade them (fetch_binance_accounts.php / fetch_bybit_accounts.php
-- hand the engine "feed": "quantlab" for those; the engine only filters). Like
-- `tester`, the type gets the admin portal and is never invoiced or counted as a
-- customer — both of those key off `type = 'user'` and need nothing here.
--
-- The column is an ENUM (095), so a value the PHP accepts but the column does not
-- would fail every save with "Data truncated". Appending a value at the END of an
-- ENUM is a metadata-only change in InnoDB: no table rebuild, no row touched, every
-- existing value keeps its index.
--
-- Idempotent: only runs while the column exists and does not list the value yet.

SET @needs := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'user_credentials'
    AND COLUMN_NAME = 'type'
    AND COLUMN_TYPE NOT LIKE '%''quantlab_tester''%'
);

SET @ddl := IF(@needs = 1,
  'ALTER TABLE `user_credentials` MODIFY COLUMN `type` ENUM(''user'',''admin'',''developer'',''collaborator'',''tester'',''quantlab_tester'') NOT NULL DEFAULT ''user'' COMMENT ''Account type: user | admin | developer | collaborator | tester | quantlab_tester''',
  'DO 0'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
