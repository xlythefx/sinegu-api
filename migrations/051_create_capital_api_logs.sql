-- Migration: Create capital_api_logs table.
-- Append-only log of Capital.com API events (rate limits, drops) sent from Flask
-- via flask/log_capital_api_event.php. Each row optionally pairs with an email
-- alert (one per "rate-limit episode" — see _webhook_worker requeue policy).
-- Created: 2026-04-22

CREATE TABLE IF NOT EXISTS capital_api_logs (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    account_id INT UNSIGNED DEFAULT NULL,
    account_name VARCHAR(128) DEFAULT NULL,
    ticker VARCHAR(64) DEFAULT NULL,
    action VARCHAR(32) DEFAULT NULL COMMENT 'BUY/SELL/EXIT_LONG/EXIT_SHORT/EXIT',
    event_type VARCHAR(64) NOT NULL DEFAULT 'rate_limited'
        COMMENT 'rate_limited, rate_limited_dropped, etc.',
    http_status INT DEFAULT NULL,
    source VARCHAR(64) DEFAULT NULL COMMENT 'webhook_queue, trading_handler, etc.',
    message TEXT DEFAULT NULL,
    email_sent TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_account (account_id),
    KEY idx_event_type (event_type),
    KEY idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  COMMENT='Capital.com API events (rate limits, drops). Append-only.';
