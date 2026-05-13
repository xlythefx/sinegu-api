<?php
/**
 * Migration: Add status to community_posts (pending/accepted/rejected).
 * Usage: php run_community_posts_status_migration.php
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../conn.php';

if (!$conn) {
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit;
}

$checkSql = "SELECT COUNT(*) AS cnt FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'community_posts' AND COLUMN_NAME = 'status'";
$res = mysqli_query($conn, $checkSql);
$exists = $res && mysqli_fetch_assoc($res)['cnt'] > 0;

if (!$exists) {
    $sqlFile = __DIR__ . '/018_add_community_posts_status.sql';
    $sql = file_get_contents($sqlFile);
    if ($sql === false) {
        echo json_encode(['success' => false, 'error' => 'Migration file not found']);
        exit;
    }
    // Strip full-line comments so ALTER/UPDATE are not skipped
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

echo json_encode(['success' => true, 'message' => 'community_posts.status column added or already present']);
mysqli_close($conn);
