-- Migration: Create trade_logs table.
--
-- One row per signal, per broker — the record of what the engine DID with a
-- TradingView alert, which nothing captured before. The existing log tables
-- answer different questions and cannot substitute:
--   capital_api_logs / ig_api_logs  -> only API *errors* (rate limits, bad keys)
--   capital_retry_queue             -> only signals that were deferred
--   binance out/webhook_trades.log  -> a file on one box, unreadable by the API
--
-- `details` holds the per-account fan-out as a JSON array, so a fill, a skip or
-- a failure can be explained from the row alone without replaying the account's
-- balance at signal time:
--   [{ account, uni_id, status: filled|skipped|failed, reason, error, retryable,
--      quantity, fill_price, closed_quantity, sizing: {...}|null }, ...]
-- A gated skip writes a PARTIAL sizing block (gate inputs only), so readers must
-- never assume every key is present.
--
-- Append-only: the three Flask engines are the only writers (via
-- flask/log_trade_signal.php); the admin page is read-only.
-- Idempotent: CREATE TABLE IF NOT EXISTS.

CREATE TABLE IF NOT EXISTS trade_logs (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    broker ENUM('capital','binance','ig') NOT NULL,
    action VARCHAR(16) NOT NULL COMMENT 'BUY | SELL | EXIT | EXIT_LONG | EXIT_SHORT',
    ticker VARCHAR(64) NOT NULL,
    success TINYINT(1) NOT NULL DEFAULT 1 COMMENT '0 = at least one account failed, or the signal was rejected',
    price DECIMAL(20,8) DEFAULT NULL COMMENT 'price quoted in the alert; informational only',
    strategy VARCHAR(128) DEFAULT NULL COMMENT 'free text, matches strategies.strategy_key case-insensitively',
    leverage INT DEFAULT NULL,
    category VARCHAR(32) NOT NULL DEFAULT 'signal'
        COMMENT 'signal | rejected | manual | retry',
    target_count INT NOT NULL DEFAULT 0 COMMENT 'accounts the fan-out targeted',
    filled INT NOT NULL DEFAULT 0,
    failed INT NOT NULL DEFAULT 0,
    skipped INT NOT NULL DEFAULT 0,
    details JSON DEFAULT NULL COMMENT 'per-account fan-out; see header',
    ts DATETIME NOT NULL COMMENT 'when the engine handled the signal (UTC)',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'when the row reached the API',
    PRIMARY KEY (id),
    KEY idx_broker_ts (broker, ts),
    KEY idx_ticker (ticker),
    KEY idx_ts (ts),
    KEY idx_category (category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  COMMENT='One row per trading signal per broker, with the per-account fan-out.';
