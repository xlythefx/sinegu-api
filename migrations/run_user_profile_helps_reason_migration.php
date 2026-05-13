<?php
/**
 * Migration: Add reason column to user_profile_helps (optional description of help).
 * Usage: php run_user_profile_helps_reason_migration.php
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../conn.php';

if (!$conn) {
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit;
}

$check = mysqli_query($conn, "SHOW COLUMNS FROM user_profile_helps LIKE 'reason'");
if ($check && mysqli_num_rows($check) > 0) {
    echo json_encode(['success' => true, 'message' => 'Column reason already exists']);
    mysqli_close($conn);
    exit;
}

$sql = "ALTER TABLE user_profile_helps ADD COLUMN reason VARCHAR(500) NULL DEFAULT NULL COMMENT 'Optional: what help they provided' AFTER rating";
if (!mysqli_query($conn, $sql)) {
    echo json_encode(['success' => false, 'error' => mysqli_error($conn)]);
    mysqli_close($conn);
    exit;
}

echo json_encode(['success' => true, 'message' => 'Added reason column to user_profile_helps']);
mysqli_close($conn);
