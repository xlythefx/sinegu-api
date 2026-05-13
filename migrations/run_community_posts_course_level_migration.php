<?php
/**
 * Migration: Add course_level to community_posts.
 * Usage: php run_community_posts_course_level_migration.php
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../conn.php';

if (!$conn) {
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit;
}

$checkSql = "SELECT COUNT(*) AS cnt FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'community_posts' AND COLUMN_NAME = 'course_level'";
$res = mysqli_query($conn, $checkSql);
$exists = $res && (int) mysqli_fetch_assoc($res)['cnt'] > 0;

if (!$exists) {
    $sql = "ALTER TABLE community_posts ADD COLUMN course_level VARCHAR(20) NOT NULL DEFAULT 'general' COMMENT 'general for community feed, or first/second/third/fourth/fifth/sixth for course discussion' AFTER post_type";
    if (!mysqli_query($conn, $sql)) {
        echo json_encode(['success' => false, 'error' => mysqli_error($conn)]);
        mysqli_close($conn);
        exit;
    }
}

echo json_encode(['success' => true, 'message' => 'community_posts.course_level added or already present']);
mysqli_close($conn);
