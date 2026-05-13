<?php
/**
 * Migration: Add demo and enabled to binance_accounts.
 * Usage: php run_binance_accounts_demo_enabled_migration.php
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

if (colExists($conn, 'binance_accounts', 'demo') && colExists($conn, 'binance_accounts', 'enabled')) {
    echo json_encode(['success' => true, 'message' => 'demo and enabled columns already exist']);
    mysqli_close($conn);
    exit;
}

if (!colExists($conn, 'binance_accounts', 'demo')) {
    $sql = "ALTER TABLE `binance_accounts` ADD COLUMN `demo` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1=demo/test, 0=live' AFTER `deleted_at`";
    if (!mysqli_query($conn, $sql)) {
        echo json_encode(['success' => false, 'error' => mysqli_error($conn)]);
        mysqli_close($conn);
        exit;
    }
}
if (!colExists($conn, 'binance_accounts', 'enabled')) {
    $sql = "ALTER TABLE `binance_accounts` ADD COLUMN `enabled` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1=enabled, 0=disabled' AFTER `demo`";
    if (!mysqli_query($conn, $sql)) {
        echo json_encode(['success' => false, 'error' => mysqli_error($conn)]);
        mysqli_close($conn);
        exit;
    }
}

echo json_encode(['success' => true, 'message' => 'demo and enabled columns added']);
mysqli_close($conn);
