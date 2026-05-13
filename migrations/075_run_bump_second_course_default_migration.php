<?php
/**
 * Migration runner: 075_bump_second_course_default_duration.sql
 * Usage: php 075_run_bump_second_course_default_migration.php
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../conn.php';

if (!$conn) {
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit;
}

$sql = "UPDATE course_level_defaults SET duration_minutes = 600 WHERE level = 'second'";
if (!mysqli_query($conn, $sql)) {
    echo json_encode(['success' => false, 'error' => mysqli_error($conn)]);
    mysqli_close($conn);
    exit;
}

$affected = mysqli_affected_rows($conn);
echo json_encode([
    'success' => true,
    'message' => "second-course default duration set to 600 minutes",
    'affected_rows' => $affected,
]);
mysqli_close($conn);
