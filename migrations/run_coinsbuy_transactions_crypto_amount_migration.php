<?php
/**
 * Migration: Add crypto_amount column to coinsbuy_transactions.
 * Usage: php run_coinsbuy_transactions_crypto_amount_migration.php
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../conn.php';

if (!$conn) {
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit;
}

$checkSql = "SELECT COUNT(*) AS cnt FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
             AND TABLE_NAME = 'coinsbuy_transactions'
             AND COLUMN_NAME = 'crypto_amount'";
$res = mysqli_query($conn, $checkSql);
$exists = $res && (int) mysqli_fetch_assoc($res)['cnt'] > 0;

if ($exists) {
    echo json_encode(['success' => true, 'message' => 'crypto_amount column already exists']);
    mysqli_close($conn);
    exit;
}

$sql = "ALTER TABLE coinsbuy_transactions
        ADD COLUMN crypto_amount DECIMAL(20,8) NULL COMMENT 'Actual crypto sent, e.g. 0.0154763 BTC' AFTER crypto_currency";

if (!mysqli_query($conn, $sql)) {
    echo json_encode(['success' => false, 'error' => mysqli_error($conn)]);
    mysqli_close($conn);
    exit;
}

echo json_encode(['success' => true, 'message' => 'crypto_amount column added']);
mysqli_close($conn);
