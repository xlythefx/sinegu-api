<?php
/**
 * Migration: Create course_level_defaults and course_tracker tables.
 * Usage: php run_course_tracker_and_level_defaults_migration.php
 * Or: http://localhost/sinegu-api/migrations/run_course_tracker_and_level_defaults_migration.php
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../conn.php';

if (!$conn) {
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit;
}

$sqlFile = __DIR__ . '/024_create_course_tracker_and_level_defaults.sql';
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

echo json_encode(['success' => true, 'message' => 'course_level_defaults and course_tracker tables created']);
mysqli_close($conn);
