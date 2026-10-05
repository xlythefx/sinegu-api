-- Migration 093: Create trade_incidents.
-- Created: 2026-10-05
--
-- One row per failed or stuck trade problem, from every broker (capital, binance,
-- ig, mexc). Bots write here through flask/log_trade_incident.php (op=open|resolve)
-- and flask/reconcile_trade_incidents.php (the positions sweep). The admin page
-- /admin/trade-incidents reads it through admin/GET/get_trade_incidents.php.
--
-- dedupe_key = broker|kind|uni_id|symbol|side (error_log: broker|error_log|signature).
-- It is UNIQUE so a repeat of the same open problem bumps occurrences instead of
-- adding a row. When a row is resolved/ignored its key gets a "|r<id>" suffix, so a
-- later recurrence of the same problem opens a NEW row instead of colliding.
--
-- kind is VARCHAR (not ENUM) so a new kind from a bot never fails an INSERT under
-- STRICT_TRANS_TABLES. broker/severity/status are ENUMs; the PHP validates them.
--
-- Idempotent: CREATE TABLE IF NOT EXISTS.

CREATE TABLE IF NOT EXISTS trade_incidents (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    broker ENUM('capital','binance','ig','mexc') NOT NULL,
    env_label VARCHAR(64) NULL,
    kind VARCHAR(32) NOT NULL
        COMMENT 'exit_failed | exit_partial | entry_failed | status_unknown | retry_scheduled | retry_gave_up | exit_pending | orphan_position | skipped_exit_open | stale_position_row | error_log',
    severity ENUM('critical','error','warning') NOT NULL DEFAULT 'error',
    status ENUM('open','retrying','resolved','ignored') NOT NULL DEFAULT 'open',
    uni_id VARCHAR(36) NULL,
    account VARCHAR(128) NULL,
    symbol VARCHAR(64) NULL,
    side VARCHAR(16) NULL COMMENT 'LONG | SHORT | empty = any/both',
    action VARCHAR(32) NULL,
    strategy VARCHAR(128) NULL,
    error_code VARCHAR(32) NULL,
    message TEXT NULL,
    details JSON NULL,
    attempts INT NOT NULL DEFAULT 0,
    occurrences INT NOT NULL DEFAULT 1,
    dedupe_key VARCHAR(191) NOT NULL,
    first_seen DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    resolved_at DATETIME NULL,
    resolved_by VARCHAR(64) NULL COMMENT 'bot | sweep | superseded | admin',
    resolution_note TEXT NULL,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY uq_trade_incidents_dedupe (dedupe_key),
    KEY idx_trade_incidents_status_last_seen (status, last_seen),
    KEY idx_trade_incidents_broker_status (broker, status),
    KEY idx_trade_incidents_uni_id (uni_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Failed / stuck trades from all brokers (admin Trade Incidents page).';
