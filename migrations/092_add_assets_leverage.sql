-- Migration 092: Add `leverage` column to assets.
-- Per-asset leverage for the crypto-futures bots (Binance, MEXC).
-- Created: 2026-09-23
--
-- Why this exists: every TradingView alert sends one hardcoded leverage for ALL
-- assets ("leverage": "25" on all 2,188 signals measured on staging over 42 days).
-- The symbols whose Binance notional bracket caps below that answered -4028
-- "Leverage 25 is not valid" 154 times — and because set_leverage is fail-soft the
-- order still went out, at whatever leverage the account happened to be holding.
-- Leverage belongs next to base_size and max_increments, as a per-asset setting the
-- admin UI owns, not as a constant baked into 40 separate TradingView alerts.
--
-- NULL is meaningful and is the default: "no per-asset override, use the bot's
-- BINANCE_DEFAULT_LEVERAGE". That keeps every existing row behaving exactly as it
-- does today and leaves Capital/IG rows — which have no leverage concept here —
-- untouched. Only fill this in for an asset you want pinned to a specific value.
--
-- Whatever is stored is still a REQUEST, not a guarantee: the bot asks Binance for
-- it and steps DOWN to the symbol's real bracket ceiling on -4028, so a value set
-- too high here degrades gracefully instead of silently trading at the wrong size.
--
-- Idempotent via the INFORMATION_SCHEMA guard (MySQL has no ADD COLUMN IF NOT
-- EXISTS), matching 081 — a box that already has the column re-runs this as a no-op
-- rather than aborting the whole pending migration run on a duplicate-column error.

SET @has_leverage := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'assets'
    AND COLUMN_NAME = 'leverage'
);

SET @ddl := IF(@has_leverage = 0,
  'ALTER TABLE `assets` ADD COLUMN `leverage` SMALLINT UNSIGNED NULL DEFAULT NULL COMMENT ''Per-asset leverage for crypto futures; NULL = use the bot default'' AFTER `base_size`',
  'DO 0'
);

PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
