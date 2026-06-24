<?php
/**
 * Migration: Add `side` column to assets (LONG / SHORT / ALL).
 * Usage: php run_assets_side_migration.php
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

if (columnExists($conn, 'assets', 'side')) {
    echo json_encode(['success' => true, 'message' => 'side column already exists']);
    mysqli_close($conn);
    exit;
}

$sql = "ALTER TABLE `assets` ADD COLUMN `side` VARCHAR(8) NOT NULL DEFAULT 'ALL' COMMENT 'Allowed trade side: LONG, SHORT, ALL' AFTER `broker`";
if (!mysqli_query($conn, $sql)) {
    echo json_encode(['success' => false, 'error' => mysqli_error($conn)]);
    mysqli_close($conn);
    exit;
}

echo json_encode(['success' => true, 'message' => 'side column added', 'added' => ['side']]);
mysqli_close($conn);
