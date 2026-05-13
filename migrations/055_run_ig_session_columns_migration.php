<?php
/**
 * Migration: Add ig_cst, ig_x_security_token, ig_session_last_used_at to accounts.
 * Usage: visit http://localhost/sinegu-api/migrations/055_run_ig_session_columns_migration.php
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../conn.php';

if (!$conn) {
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit;
}

$results = [];

$columns = [
    'ig_cst' => "ALTER TABLE `accounts` ADD COLUMN `ig_cst` VARCHAR(512) DEFAULT NULL COMMENT 'IG.com CST session token' AFTER `session_last_used_at`",
    'ig_x_security_token' => "ALTER TABLE `accounts` ADD COLUMN `ig_x_security_token` VARCHAR(512) DEFAULT NULL COMMENT 'IG.com X-SECURITY-TOKEN' AFTER `ig_cst`",
    'ig_session_last_used_at' => "ALTER TABLE `accounts` ADD COLUMN `ig_session_last_used_at` DATETIME DEFAULT NULL COMMENT 'When IG session was last refreshed or used' AFTER `ig_x_security_token`",
];

foreach ($columns as $colName => $alterSql) {
    $checkSql = "SELECT COUNT(*) AS cnt FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'accounts' AND COLUMN_NAME = '{$colName}'";
    $res = mysqli_query($conn, $checkSql);
    $exists = $res && (int) mysqli_fetch_assoc($res)['cnt'] > 0;

    if ($exists) {
        $results[$colName] = 'already exists';
        continue;
    }

    if (!mysqli_query($conn, $alterSql)) {
        echo json_encode(['success' => false, 'error' => "Failed on {$colName}: " . mysqli_error($conn)]);
        mysqli_close($conn);
        exit;
    }
    $results[$colName] = 'added';
}

echo json_encode(['success' => true, 'columns' => $results]);
mysqli_close($conn);
