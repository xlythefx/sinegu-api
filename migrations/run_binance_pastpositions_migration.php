<?php
/**
 * Migration: Create binance_pastpositions table.
 * Usage: php run_binance_pastpositions_migration.php
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../conn.php';

if (!$conn) {
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit;
}

$checkSql = "SELECT COUNT(*) AS cnt FROM INFORMATION_SCHEMA.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'binance_pastpositions'";
$res = mysqli_query($conn, $checkSql);
$exists = $res && (int) mysqli_fetch_assoc($res)['cnt'] > 0;

if ($exists) {
    echo json_encode(['success' => true, 'message' => 'binance_pastpositions table already exists']);
    mysqli_close($conn);
    exit;
}

$sql = "CREATE TABLE binance_pastpositions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    api_key VARCHAR(128) NOT NULL COMMENT 'Binance API key (FK binance_accounts)',
    uni_id VARCHAR(36) NOT NULL COMMENT 'User ID (FK user_credentials)',
    symbol VARCHAR(32) NOT NULL COMMENT 'Trading pair',
    position_side VARCHAR(16) NOT NULL COMMENT 'LONG, SHORT, or BOTH',
    position_amt DECIMAL(30,8) NOT NULL COMMENT 'Closed quantity',
    entry_price DECIMAL(30,8) NULL DEFAULT NULL,
    exit_price DECIMAL(30,8) NULL DEFAULT NULL,
    realized_pnl DECIMAL(30,8) NULL DEFAULT NULL,
    side VARCHAR(8) NOT NULL COMMENT 'BUY or SELL (close direction)',
    order_id BIGINT NULL DEFAULT NULL COMMENT 'Binance orderId',
    closed_at DATETIME NOT NULL COMMENT 'When position was closed',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_binance_pastpositions_api_key (api_key),
    KEY idx_binance_pastpositions_uni_id (uni_id),
    KEY idx_binance_pastpositions_symbol (symbol),
    KEY idx_binance_pastpositions_closed_at (closed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Closed Binance positions (append on exit)'";

if (!mysqli_query($conn, $sql)) {
    echo json_encode(['success' => false, 'error' => mysqli_error($conn)]);
    mysqli_close($conn);
    exit;
}

echo json_encode(['success' => true, 'message' => 'binance_pastpositions table created']);
mysqli_close($conn);
