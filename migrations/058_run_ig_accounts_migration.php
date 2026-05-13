<?php
/**
 * Migration: Create ig_accounts table.
 * Usage: visit http://localhost/sinegu-api/migrations/058_run_ig_accounts_migration.php
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../conn.php';

if (!$conn) {
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit;
}

$checkSql = "SELECT COUNT(*) AS cnt FROM INFORMATION_SCHEMA.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ig_accounts'";
$res = mysqli_query($conn, $checkSql);
$exists = $res && (int) mysqli_fetch_assoc($res)['cnt'] > 0;

if ($exists) {
    echo json_encode(['success' => true, 'message' => 'ig_accounts table already exists']);
    mysqli_close($conn);
    exit;
}

$sql = file_get_contents(__DIR__ . '/058_create_ig_accounts_table.sql');
if (!mysqli_query($conn, $sql)) {
    echo json_encode(['success' => false, 'error' => mysqli_error($conn)]);
    mysqli_close($conn);
    exit;
}

echo json_encode(['success' => true, 'message' => 'ig_accounts table created']);
mysqli_close($conn);
