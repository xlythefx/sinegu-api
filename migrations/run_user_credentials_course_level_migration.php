<?php
/**
 * Migration: Add course_level to user_credentials (VARCHAR).
 * Usage: php run_user_credentials_course_level_migration.php
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../conn.php';

if (!$conn) {
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit;
}

$checkSql = "SELECT COUNT(*) AS cnt FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_credentials' AND COLUMN_NAME = 'course_level'";
$res = mysqli_query($conn, $checkSql);
$exists = $res && (int) mysqli_fetch_assoc($res)['cnt'] > 0;

if (!$exists) {
    $sqlFile = __DIR__ . '/020_add_user_credentials_course_level.sql';
    $sql = file_get_contents($sqlFile);
    if ($sql === false) {
        echo json_encode(['success' => false, 'error' => 'Migration file not found']);
        exit;
    }
    $sql = preg_replace('/^\s*--[^\n]*\n/m', '', $sql);
    $statements = array_filter(array_map('trim', explode(';', $sql)));
    foreach ($statements as $stmt) {
        if ($stmt === '') continue;
        if (!mysqli_query($conn, $stmt)) {
            echo json_encode(['success' => false, 'error' => mysqli_error($conn)]);
            exit;
        }
    }
}

echo json_encode(['success' => true, 'message' => 'user_credentials.course_level column added or already present']);
mysqli_close($conn);
