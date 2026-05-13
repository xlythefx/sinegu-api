<?php
/**
 * Migration: Enforce max 9999 on assets base_size and max_increments (CHECK constraints).
 * Usage: php run_assets_size_max_9999_migration.php
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../conn.php';

if (!$conn) {
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit;
}

$checkSql = "SELECT COUNT(*) AS cnt FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'assets'
             AND CONSTRAINT_NAME = 'chk_assets_base_size_max'";
$res = mysqli_query($conn, $checkSql);
$exists = $res && (int) mysqli_fetch_assoc($res)['cnt'] > 0;

if ($exists) {
    echo json_encode(['success' => true, 'message' => 'CHECK constraints already exist']);
    mysqli_close($conn);
    exit;
}

$sql = "ALTER TABLE `assets`
        ADD CONSTRAINT `chk_assets_base_size_max` CHECK (`base_size` <= 9999),
        ADD CONSTRAINT `chk_assets_max_increments_max` CHECK (`max_increments` <= 9999)";

if (!mysqli_query($conn, $sql)) {
    echo json_encode(['success' => false, 'error' => mysqli_error($conn)]);
    mysqli_close($conn);
    exit;
}

echo json_encode(['success' => true, 'message' => 'base_size and max_increments capped at 9999']);
mysqli_close($conn);
