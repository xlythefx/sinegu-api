-- Migration 094: make capital_retry_queue multi-broker.
-- Created: 2026-10-05
--
-- Binance and MEXC kept their retry queue only in memory, so a restart during a
-- retry silently dropped it. They now reuse capital_retry_queue (079) through the
-- same flask/retry_*.php endpoints, distinguished by a new `broker` column:
--
--   broker  VARCHAR(16) NOT NULL DEFAULT 'capital'  (+ index with status/next_attempt_at)
--   payload JSON NULL                                (extra per-job fields, e.g. leverage)
--
-- The DEFAULT 'capital' keeps every existing row, and every CapitalFlask call that
-- does not send a broker, behaving exactly as before. The retry_*.php endpoints also
-- detect whether this column exists, so CapitalFlask keeps working if the API lands
-- before this migration runs.
--
-- Idempotent: every ALTER is guarded by an INFORMATION_SCHEMA check (MySQL has no
-- ADD COLUMN IF NOT EXISTS). The guards also require the TABLE to exist — on a box
-- without capital_retry_queue (079 not applied) this whole file is a no-op instead
-- of a bare ALTER that halts the migration run.

SET @has_table := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'capital_retry_queue'
);

-- 1. broker column
SET @has_broker := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'capital_retry_queue'
    AND COLUMN_NAME = 'broker'
);
SET @ddl := IF(@has_table = 1 AND @has_broker = 0,
  'ALTER TABLE `capital_retry_queue` ADD COLUMN `broker` VARCHAR(16) NOT NULL DEFAULT ''capital'' COMMENT ''capital | binance | mexc | ig'' AFTER `id`',
  'DO 0'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 2. index used by retry_claim_due.php (broker + due scan)
SET @has_idx := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'capital_retry_queue'
    AND INDEX_NAME = 'idx_broker_due'
);
SET @ddl := IF(@has_table = 1 AND @has_idx = 0,
  'ALTER TABLE `capital_retry_queue` ADD KEY `idx_broker_due` (`broker`, `status`, `next_attempt_at`)',
  'DO 0'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 3. payload column
SET @has_payload := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'capital_retry_queue'
    AND COLUMN_NAME = 'payload'
);
SET @ddl := IF(@has_table = 1 AND @has_payload = 0,
  'ALTER TABLE `capital_retry_queue` ADD COLUMN `payload` JSON NULL COMMENT ''Extra per-job fields (e.g. leverage for binance/mexc)'' AFTER `target_uni_ids`',
  'DO 0'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
