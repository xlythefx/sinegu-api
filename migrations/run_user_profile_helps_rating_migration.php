<?php
/**
 * Migration: Add rating column to user_profile_helps (1-10, optional).
 * Usage: php run_user_profile_helps_rating_migration.php
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../conn.php';

if (!$conn) {
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit;
}

$check = mysqli_query($conn, "SHOW COLUMNS FROM user_profile_helps LIKE 'rating'");
if ($check && mysqli_num_rows($check) > 0) {
    echo json_encode(['success' => true, 'message' => 'Column rating already exists']);
    mysqli_close($conn);
    exit;
}

$sql = "ALTER TABLE user_profile_helps ADD COLUMN rating TINYINT UNSIGNED NULL DEFAULT NULL COMMENT '1-10 help experience rating from helped user' AFTER status";
if (!mysqli_query($conn, $sql)) {
    echo json_encode(['success' => false, 'error' => mysqli_error($conn)]);
    mysqli_close($conn);
    exit;
}

echo json_encode(['success' => true, 'message' => 'Added rating column to user_profile_helps']);
mysqli_close($conn);
