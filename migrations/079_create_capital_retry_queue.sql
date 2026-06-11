-- Migration: Create capital_retry_queue table.
-- Durable retry list for Capital.com entries/exits that were rate-limited.
-- The bot's single worker is the only consumer (preserves per-account pacing).
-- Idempotent: CREATE TABLE IF NOT EXISTS.
-- Converted from 077_run_capital_retry_queue_migration.php (2026-05-28);
-- renumbered 077 -> 079 to resolve a collision with
-- 077_add_binance_pastpositions_unique_order.sql.

CREATE TABLE IF NOT EXISTS capital_retry_queue (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    kind VARCHAR(8) NOT NULL COMMENT 'entry | exit',
    action VARCHAR(32) NOT NULL COMMENT 'BUY/SELL/EXIT/EXIT_LONG/EXIT_SHORT',
    ticker VARCHAR(64) NOT NULL,
    price DECIMAL(20,8) DEFAULT NULL,
    strategy VARCHAR(128) DEFAULT NULL,
    target_uni_ids TEXT DEFAULT NULL COMMENT 'JSON array of uni_ids still pending; NULL/empty = all accounts',
    is_demo_only TINYINT(1) NOT NULL DEFAULT 0,
    attempt_count INT NOT NULL DEFAULT 0,
    max_attempts INT NOT NULL COMMENT 'exit=12 (~1h), entry=4 (~20m)',
    next_attempt_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'due when <= NOW()',
    status VARCHAR(16) NOT NULL DEFAULT 'pending'
        COMMENT 'pending | processing | executed | failed | needs_review',
    last_error TEXT DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'first enqueue ~ signal time; used for entry staleness gate',
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_due (status, next_attempt_at),
    KEY idx_kind (kind),
    KEY idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  COMMENT='Durable retry queue for rate-limited Capital.com entries/exits.';
