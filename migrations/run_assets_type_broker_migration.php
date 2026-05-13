<?php
/**
 * Migration: Add type and broker columns to assets.
 * Usage: php run_assets_type_broker_migration.php
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../conn.php';

if (!$conn) {
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit;
}

function columnExists($conn, $table, $col) {
    $sql = "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, 'ss', $table, $col);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $exists = $res && mysqli_num_rows($res) > 0;
    mysqli_stmt_close($stmt);
    return $exists;
}

$added = [];
if (!columnExists($conn, 'assets', 'type')) {
    $sql = "ALTER TABLE `assets` ADD COLUMN `type` VARCHAR(32) NULL COMMENT 'Asset type: stock, crypto, forex, commodity' AFTER `ticker`";
    if (!mysqli_query($conn, $sql)) {
        echo json_encode(['success' => false, 'error' => mysqli_error($conn)]);
        mysqli_close($conn);
        exit;
    }
    $added[] = 'type';
}
if (!columnExists($conn, 'assets', 'broker')) {
    $sql = "ALTER TABLE `assets` ADD COLUMN `broker` VARCHAR(64) NULL COMMENT 'Broker/exchange name (e.g. Binance, Capital.com)' AFTER `type`";
    if (!mysqli_query($conn, $sql)) {
        echo json_encode(['success' => false, 'error' => mysqli_error($conn)]);
        mysqli_close($conn);
        exit;
    }
    $added[] = 'broker';
}

if (empty($added)) {
    echo json_encode(['success' => true, 'message' => 'type and broker columns already exist']);
    mysqli_close($conn);
    exit;
}

echo json_encode(['success' => true, 'message' => 'type and broker columns added', 'added' => $added]);
mysqli_close($conn);
