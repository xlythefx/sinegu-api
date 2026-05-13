<?php
/**
 * Migration: Create binance_positions table.
 * Usage: php run_binance_positions_migration.php
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../conn.php';

if (!$conn) {
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit;
}

$checkSql = "SELECT COUNT(*) AS cnt FROM INFORMATION_SCHEMA.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'binance_positions'";
$res = mysqli_query($conn, $checkSql);
$exists = $res && (int) mysqli_fetch_assoc($res)['cnt'] > 0;

if ($exists) {
    echo json_encode(['success' => true, 'message' => 'binance_positions table already exists']);
    mysqli_close($conn);
    exit;
}

$sql = "CREATE TABLE binance_positions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    api_key VARCHAR(128) NOT NULL COMMENT 'Binance API key (FK binance_accounts)',
    uni_id VARCHAR(36) NOT NULL COMMENT 'User ID (FK user_credentials)',
    symbol VARCHAR(32) NOT NULL COMMENT 'Trading pair (e.g. BTCUSDT)',
    position_side VARCHAR(16) NOT NULL COMMENT 'BOTH, LONG, or SHORT',
    position_amt DECIMAL(30,8) NOT NULL COMMENT 'Position size (+ long, - short)',
    entry_price DECIMAL(30,8) NULL DEFAULT NULL,
    mark_price DECIMAL(30,8) NULL DEFAULT NULL,
    unrealized_profit DECIMAL(30,8) NULL DEFAULT NULL,
    notional DECIMAL(30,8) NULL DEFAULT NULL,
    initial_margin DECIMAL(30,8) NULL DEFAULT NULL,
    maint_margin DECIMAL(30,8) NULL DEFAULT NULL,
    isolated_margin DECIMAL(30,8) NULL DEFAULT NULL,
    isolated_wallet DECIMAL(30,8) NULL DEFAULT NULL,
    update_time BIGINT NULL DEFAULT NULL COMMENT 'Binance updateTime (ms)',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_binance_positions_api_key (api_key),
    KEY idx_binance_positions_uni_id (uni_id),
    KEY idx_binance_positions_symbol (symbol)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Current Binance positions (full replace on each fetch)'";

if (!mysqli_query($conn, $sql)) {
    echo json_encode(['success' => false, 'error' => mysqli_error($conn)]);
    mysqli_close($conn);
    exit;
}

echo json_encode(['success' => true, 'message' => 'binance_positions table created']);
mysqli_close($conn);
