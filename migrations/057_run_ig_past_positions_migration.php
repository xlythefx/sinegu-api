<?php
/**
 * Migration: Create ig_past_positions table.
 * Usage: visit http://localhost/sinegu-api/migrations/057_run_ig_past_positions_migration.php
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../conn.php';

if (!$conn) {
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit;
}

$checkSql = "SELECT COUNT(*) AS cnt FROM INFORMATION_SCHEMA.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ig_past_positions'";
$res = mysqli_query($conn, $checkSql);
$exists = $res && (int) mysqli_fetch_assoc($res)['cnt'] > 0;

if ($exists) {
    echo json_encode(['success' => true, 'message' => 'ig_past_positions table already exists']);
    mysqli_close($conn);
    exit;
}

$sql = file_get_contents(__DIR__ . '/057_create_ig_past_positions_table.sql');
if (!mysqli_query($conn, $sql)) {
    echo json_encode(['success' => false, 'error' => mysqli_error($conn)]);
    mysqli_close($conn);
    exit;
}

echo json_encode(['success' => true, 'message' => 'ig_past_positions table created']);
mysqli_close($conn);
