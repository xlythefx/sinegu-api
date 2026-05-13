<?php
/**
 * Migration: Add side ENUM(BUY, SELL) to trades and positions.
 * Usage: php run_trades_positions_side_migration.php
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../conn.php';

if (!$conn) {
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit;
}

$columnExists = static function ($conn, $table, $column) {
    $t = mysqli_real_escape_string($conn, $table);
    $c = mysqli_real_escape_string($conn, $column);
    $sql = "SELECT COUNT(*) AS cnt FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$t}' AND COLUMN_NAME = '{$c}'";
    $res = mysqli_query($conn, $sql);
    return $res && (int) mysqli_fetch_assoc($res)['cnt'] > 0;
};

$messages = [];

if (!$columnExists($conn, 'trades', 'side')) {
    $sql = "ALTER TABLE `trades` ADD COLUMN `side` ENUM('BUY','SELL') NULL DEFAULT NULL AFTER `ticker`";
    if (!mysqli_query($conn, $sql)) {
        echo json_encode(['success' => false, 'error' => 'trades: ' . mysqli_error($conn)]);
        mysqli_close($conn);
        exit;
    }
    $messages[] = 'trades.side added';
} else {
    $messages[] = 'trades.side already exists';
}

if (!$columnExists($conn, 'positions', 'side')) {
    $sql = "ALTER TABLE `positions` ADD COLUMN `side` ENUM('BUY','SELL') NULL DEFAULT NULL AFTER `ticker`";
    if (!mysqli_query($conn, $sql)) {
        echo json_encode(['success' => false, 'error' => 'positions: ' . mysqli_error($conn)]);
        mysqli_close($conn);
        exit;
    }
    $messages[] = 'positions.side added';
} else {
    $messages[] = 'positions.side already exists';
}

echo json_encode(['success' => true, 'message' => implode('; ', $messages)]);
mysqli_close($conn);
