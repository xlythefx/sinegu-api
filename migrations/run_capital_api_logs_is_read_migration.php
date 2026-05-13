<?php
/**
 * Migration: Add is_read column to capital_api_logs (admin can hide/show read entries).
 * Usage: php run_capital_api_logs_is_read_migration.php
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../conn.php';

if (!$conn) {
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit;
}

$check = mysqli_query($conn, "SHOW COLUMNS FROM capital_api_logs LIKE 'is_read'");
if ($check && mysqli_num_rows($check) > 0) {
    echo json_encode(['success' => true, 'message' => 'Column is_read already exists']);
    mysqli_close($conn);
    exit;
}

$sql = "ALTER TABLE capital_api_logs ADD COLUMN is_read TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Admin marked entry as read/handled' AFTER email_sent, ADD INDEX idx_is_read (is_read)";
if (!mysqli_query($conn, $sql)) {
    echo json_encode(['success' => false, 'error' => mysqli_error($conn)]);
    mysqli_close($conn);
    exit;
}

echo json_encode(['success' => true, 'message' => 'Added is_read column to capital_api_logs']);
mysqli_close($conn);
