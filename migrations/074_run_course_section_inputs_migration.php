<?php
/**
 * Migration runner: 074_create_course_section_inputs.sql
 * Usage: php 074_run_course_section_inputs_migration.php
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../conn.php';

if (!$conn) {
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit;
}

function tableExists($conn, $table) {
    $q = mysqli_query($conn, "SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$table'");
    return $q && mysqli_num_rows($q) > 0;
}

if (tableExists($conn, 'course_section_inputs')) {
    echo json_encode(['success' => true, 'message' => 'course_section_inputs table already exists']);
    mysqli_close($conn);
    exit;
}

$sql = file_get_contents(__DIR__ . '/074_create_course_section_inputs.sql');
if ($sql === false) {
    echo json_encode(['success' => false, 'error' => 'Could not read SQL file']);
    mysqli_close($conn);
    exit;
}

if (!mysqli_query($conn, $sql)) {
    echo json_encode(['success' => false, 'error' => mysqli_error($conn)]);
    mysqli_close($conn);
    exit;
}

echo json_encode(['success' => true, 'message' => 'course_section_inputs table created']);
mysqli_close($conn);
