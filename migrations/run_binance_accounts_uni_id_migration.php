<?php
/**
 * Migration: Add uni_id to binance_accounts (tie to user_credentials).
 * Usage: php run_binance_accounts_uni_id_migration.php
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../conn.php';

if (!$conn) {
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit;
}

$checkSql = "SELECT COUNT(*) AS cnt FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
             AND TABLE_NAME = 'binance_accounts'
             AND COLUMN_NAME = 'uni_id'";
$res = mysqli_query($conn, $checkSql);
$exists = $res && (int) mysqli_fetch_assoc($res)['cnt'] > 0;

if ($exists) {
    echo json_encode(['success' => true, 'message' => 'uni_id column already exists']);
    mysqli_close($conn);
    exit;
}

$sql = "ALTER TABLE `binance_accounts`
        ADD COLUMN `uni_id` VARCHAR(36) NULL COMMENT 'User ID from user_credentials (owner)' AFTER `api_key`,
        ADD CONSTRAINT `fk_binance_accounts_uni_id` FOREIGN KEY (`uni_id`) REFERENCES `user_credentials` (`uni_id`) ON DELETE CASCADE ON UPDATE CASCADE,
        ADD KEY `idx_binance_accounts_uni_id` (`uni_id`)";

if (!mysqli_query($conn, $sql)) {
    echo json_encode(['success' => false, 'error' => mysqli_error($conn)]);
    mysqli_close($conn);
    exit;
}

echo json_encode(['success' => true, 'message' => 'uni_id column added to binance_accounts']);
mysqli_close($conn);
