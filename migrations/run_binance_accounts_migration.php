<?php
/**
 * Migration: Create binance_accounts table.
 * Usage: php run_binance_accounts_migration.php
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../conn.php';

if (!$conn) {
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit;
}

$checkSql = "SELECT COUNT(*) AS cnt FROM INFORMATION_SCHEMA.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'binance_accounts'";
$res = mysqli_query($conn, $checkSql);
$exists = $res && (int) mysqli_fetch_assoc($res)['cnt'] > 0;

if ($exists) {
    echo json_encode(['success' => true, 'message' => 'binance_accounts table already exists']);
    mysqli_close($conn);
    exit;
}

$sql = "CREATE TABLE binance_accounts (
    api_key VARCHAR(128) NOT NULL COMMENT 'Binance API key (primary identifier)',
    secret_key VARCHAR(128) NOT NULL COMMENT 'Binance API secret',
    name VARCHAR(128) NOT NULL COMMENT 'Account display name',
    balance DECIMAL(20,8) NULL DEFAULT NULL COMMENT 'Current balance',
    initial_deposit DECIMAL(20,8) NULL DEFAULT NULL COMMENT 'Initial deposit amount',
    currency_type VARCHAR(16) NOT NULL DEFAULT 'USDT' COMMENT 'Account currency (USDT, BUSD, etc.)',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at DATETIME NULL DEFAULT NULL COMMENT 'Soft delete; NULL = active',
    PRIMARY KEY (api_key),
    UNIQUE KEY uq_binance_accounts_name (name),
    KEY idx_deleted_at (deleted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Binance exchange accounts'";

if (!mysqli_query($conn, $sql)) {
    echo json_encode(['success' => false, 'error' => mysqli_error($conn)]);
    mysqli_close($conn);
    exit;
}

echo json_encode(['success' => true, 'message' => 'binance_accounts table created']);
mysqli_close($conn);
