<?php
/**
 * Migration: Create ig_api_logs table.
 * Usage: visit http://localhost/sinegu-api/migrations/056_run_ig_api_logs_migration.php
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../conn.php';

if (!$conn) {
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit;
}

$checkSql = "SELECT COUNT(*) AS cnt FROM INFORMATION_SCHEMA.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ig_api_logs'";
$res = mysqli_query($conn, $checkSql);
$exists = $res && (int) mysqli_fetch_assoc($res)['cnt'] > 0;

if ($exists) {
    echo json_encode(['success' => true, 'message' => 'ig_api_logs table already exists']);
    mysqli_close($conn);
    exit;
}

$sql = file_get_contents(__DIR__ . '/056_create_ig_api_logs_table.sql');
if (!mysqli_query($conn, $sql)) {
    echo json_encode(['success' => false, 'error' => mysqli_error($conn)]);
    mysqli_close($conn);
    exit;
}

echo json_encode(['success' => true, 'message' => 'ig_api_logs table created']);
mysqli_close($conn);
