<?php
/**
 * Migration: Set user_credentials.course_level default to 'first'.
 * Usage: php run_course_level_default_first_migration.php
 * Or: http://localhost/sinegu-api/migrations/run_course_level_default_first_migration.php
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
    echo json_encode(['success' => false, 'error' => 'Column user_credentials.course_level does not exist. Run 020 migration first.']);
    mysqli_close($conn);
    exit;
}

$sqlFile = __DIR__ . '/021_user_credentials_course_level_default_first.sql';
$sql = file_get_contents($sqlFile);
if ($sql === false) {
    echo json_encode(['success' => false, 'error' => 'Migration file not found']);
    mysqli_close($conn);
    exit;
}
$sql = preg_replace('/^\s*--[^\n]*\n/m', '', $sql);
$statements = array_filter(array_map('trim', explode(';', $sql)));
foreach ($statements as $stmt) {
    if ($stmt === '') continue;
    if (!mysqli_query($conn, $stmt)) {
        echo json_encode(['success' => false, 'error' => mysqli_error($conn)]);
        mysqli_close($conn);
        exit;
    }
}

echo json_encode(['success' => true, 'message' => "course_level default set to 'first' and existing NULLs backfilled"]);
mysqli_close($conn);
