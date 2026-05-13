<?php
/**
 * Migration: Create binance_transactions table.
 * Usage: php run_binance_transactions_migration.php
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../conn.php';

if (!$conn) {
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit;
}

$checkSql = "SELECT COUNT(*) AS cnt FROM INFORMATION_SCHEMA.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'binance_transactions'";
$res = mysqli_query($conn, $checkSql);
$exists = $res && (int) mysqli_fetch_assoc($res)['cnt'] > 0;

if ($exists) {
    echo json_encode(['success' => true, 'message' => 'binance_transactions table already exists']);
    mysqli_close($conn);
    exit;
}

$sql = "CREATE TABLE binance_transactions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    api_key VARCHAR(128) NOT NULL COMMENT 'Binance API key (FK binance_accounts)',
    uni_id VARCHAR(36) NOT NULL COMMENT 'User ID (FK user_credentials)',
    type VARCHAR(16) NOT NULL COMMENT 'DEPOSIT or WITHDRAWAL',
    amount DECIMAL(20,8) NOT NULL COMMENT 'Transaction amount (positive for deposit, stored as absolute for clarity)',
    balance_after DECIMAL(20,8) NULL DEFAULT NULL,
    tran_id BIGINT NULL DEFAULT NULL COMMENT 'Binance tranId',
    currency VARCHAR(16) NOT NULL DEFAULT 'USDT',
    transaction_time BIGINT NULL DEFAULT NULL COMMENT 'Binance time (ms)',
    info VARCHAR(64) NULL DEFAULT NULL COMMENT 'e.g. TRANSFER',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_binance_transactions_api_key (api_key),
    KEY idx_binance_transactions_uni_id (uni_id),
    KEY idx_binance_transactions_type (type),
    KEY idx_binance_transactions_tran_id (tran_id),
    KEY idx_binance_transactions_transaction_time (transaction_time)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Binance Futures wallet deposits/withdrawals'";

if (!mysqli_query($conn, $sql)) {
    echo json_encode(['success' => false, 'error' => mysqli_error($conn)]);
    mysqli_close($conn);
    exit;
}

echo json_encode(['success' => true, 'message' => 'binance_transactions table created']);
mysqli_close($conn);
