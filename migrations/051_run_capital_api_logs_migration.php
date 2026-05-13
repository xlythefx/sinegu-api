<?php
/**
 * Migration: Create capital_api_logs table.
 * Usage: php 051_run_capital_api_logs_migration.php
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../conn.php';

if (!$conn) {
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit;
}

$checkSql = "SELECT COUNT(*) AS cnt FROM INFORMATION_SCHEMA.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'capital_api_logs'";
$res = mysqli_query($conn, $checkSql);
$exists = $res && (int) mysqli_fetch_assoc($res)['cnt'] > 0;

if ($exists) {
    echo json_encode(['success' => true, 'message' => 'capital_api_logs table already exists']);
    mysqli_close($conn);
    exit;
}

$sql = "CREATE TABLE capital_api_logs (
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
  COMMENT='Capital.com API events (rate limits, drops). Append-only.'";

if (!mysqli_query($conn, $sql)) {
    echo json_encode(['success' => false, 'error' => mysqli_error($conn)]);
    mysqli_close($conn);
    exit;
}

echo json_encode(['success' => true, 'message' => 'capital_api_logs table created']);
mysqli_close($conn);
