<?php
/**
 * Migration: Create coinsbuy_transactions table.
 * Usage: php run_coinsbuy_transactions_migration.php
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../conn.php';

if (!$conn) {
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit;
}

$checkSql = "SELECT COUNT(*) AS cnt FROM INFORMATION_SCHEMA.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'coinsbuy_transactions'";
$res = mysqli_query($conn, $checkSql);
$exists = $res && (int) mysqli_fetch_assoc($res)['cnt'] > 0;

if ($exists) {
    echo json_encode(['success' => true, 'message' => 'coinsbuy_transactions table already exists']);
    mysqli_close($conn);
    exit;
}

$sql = "CREATE TABLE coinsbuy_transactions (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    deposit_id VARCHAR(64) NOT NULL COMMENT 'Coinsbuy deposit ID from payload',
    transfer_id VARCHAR(64) NULL COMMENT 'Transfer ID from included (if present)',
    tracking_id VARCHAR(128) NULL COMMENT 'From deposit attributes',
    billing_period_id INT NULL COMMENT 'Parsed from tracking_id (billing_123_...)',
    account_id INT NULL COMMENT 'From billing_period when found',
    user_id VARCHAR(255) NULL COMMENT 'From billing_period when found',
    outcome VARCHAR(32) NOT NULL COMMENT 'paid, failed, overpaid, canceled, blocked, amount_mismatch, not_processed, tracking_unknown, billing_period_not_found',
    deposit_status TINYINT NULL COMMENT 'Coinsbuy: 2=Created, 3=Paid, 4=Canceled, 5=Unresolved',
    transfer_status SMALLINT NULL COMMENT 'Coinsbuy: -3=Canceled, -2=Blocked, -1=Failed, 0=Created, 1=Unconfirmed, 2=Confirmed',
    amount DECIMAL(20,8) NULL COMMENT 'target_paid (USD) or transfer amount (crypto)',
    amount_currency VARCHAR(10) NULL COMMENT 'USD, EUR, BTC, ETH, etc.',
    expected_amount DECIMAL(15,2) NULL COMMENT 'billing_periods.total_fee when applicable',
    crypto_currency VARCHAR(10) NULL COMMENT 'BTC, ETH, USDT, USDC',
    transaction_hash VARCHAR(255) NULL COMMENT 'Blockchain txid',
    error_message VARCHAR(512) NULL COMMENT 'For amount_mismatch, DB errors, etc.',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_deposit_id (deposit_id),
    KEY idx_billing_period_id (billing_period_id),
    KEY idx_outcome (outcome),
    KEY idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Append-only log of Coinsbuy webhook outcomes'";

if (!mysqli_query($conn, $sql)) {
    echo json_encode(['success' => false, 'error' => mysqli_error($conn)]);
    mysqli_close($conn);
    exit;
}

echo json_encode(['success' => true, 'message' => 'coinsbuy_transactions table created']);
mysqli_close($conn);
