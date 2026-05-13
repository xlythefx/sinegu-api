<?php
/**
 * Migration: Add notes (LONGTEXT) to course_tracker.
 * Usage: php run_course_tracker_notes_migration.php
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../conn.php';

if (!$conn) {
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit;
}

$checkSql = "SELECT COUNT(*) AS cnt FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'course_tracker' AND COLUMN_NAME = 'notes'";
$res = mysqli_query($conn, $checkSql);
$exists = $res && (int) mysqli_fetch_assoc($res)['cnt'] > 0;

if (!$exists) {
    $sql = "ALTER TABLE course_tracker ADD COLUMN notes LONGTEXT NULL";
    if (!mysqli_query($conn, $sql)) {
        echo json_encode(['success' => false, 'error' => mysqli_error($conn)]);
        mysqli_close($conn);
        exit;
    }
}

echo json_encode(['success' => true, 'message' => 'course_tracker.notes added or already present']);
mysqli_close($conn);
