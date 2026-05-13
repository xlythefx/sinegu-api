<?php
/**
 * Migration: Add unrealized_pnl to ig_accounts.
 * Usage: php 070_run_ig_accounts_unrealized_pnl_migration.php
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../conn.php';

if (!$conn) {
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit;
}

function colExists($conn, $table, $col) {
    $q = mysqli_query($conn, "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$table' AND COLUMN_NAME = '$col'");
    return $q && mysqli_num_rows($q) > 0;
}

if (colExists($conn, 'ig_accounts', 'unrealized_pnl')) {
    echo json_encode(['success' => true, 'message' => 'unrealized_pnl column already exists']);
    mysqli_close($conn);
    exit;
}

$sql = "ALTER TABLE `ig_accounts` ADD COLUMN `unrealized_pnl` DECIMAL(20, 8) NULL DEFAULT NULL COMMENT 'balance.profitLoss from IG GET /accounts' AFTER `balance`";
if (!mysqli_query($conn, $sql)) {
    echo json_encode(['success' => false, 'error' => mysqli_error($conn)]);
    mysqli_close($conn);
    exit;
}

echo json_encode(['success' => true, 'message' => 'unrealized_pnl column added to ig_accounts']);
mysqli_close($conn);
