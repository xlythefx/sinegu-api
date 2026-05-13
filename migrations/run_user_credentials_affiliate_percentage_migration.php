<?php
/**
 * Migration Script: Add affiliate_percentage to user_credentials
 *
 * Adds affiliate_percentage column after unrealized_percentage.
 *
 * Usage: php run_user_credentials_affiliate_percentage_migration.php
 * Or: http://localhost/sinegu-api/migrations/run_user_credentials_affiliate_percentage_migration.php
 */

header('Content-Type: application/json');

require_once __DIR__ . '/../conn.php';

if (!$conn) {
    echo json_encode([
        'success' => false,
        'error' => 'Database connection failed: ' . mysqli_connect_error()
    ]);
    exit;
}

$errors = [];

$checkSql = "SELECT COUNT(*) AS cnt FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_credentials' AND COLUMN_NAME = 'affiliate_percentage'";
$res = mysqli_query($conn, $checkSql);
$exists = false;
if ($res) {
    $row = mysqli_fetch_assoc($res);
    $exists = (int) $row['cnt'] > 0;
}

if (!$exists) {
    $alterSql = "ALTER TABLE `user_credentials`
                 ADD COLUMN `affiliate_percentage` DECIMAL(5,2) NOT NULL DEFAULT 4.00 COMMENT 'Affiliate/referral commission percentage' AFTER `unrealized_percentage`";
    if (!mysqli_query($conn, $alterSql)) {
        $msg = mysqli_error($conn);
        if (stripos($msg, 'Duplicate column name') === false) {
            $errors[] = $msg;
        }
    }
}

$exists = false;
$res = mysqli_query($conn, $checkSql);
if ($res) {
    $row = mysqli_fetch_assoc($res);
    $exists = (int) $row['cnt'] > 0;
}

if ($exists) {
    echo json_encode([
        'success' => true,
        'message' => 'affiliate_percentage column added or already present',
        'errors' => empty($errors) ? null : $errors
    ]);
} else {
    echo json_encode([
        'success' => false,
        'error' => 'Column was not created',
        'errors' => $errors
    ]);
}

mysqli_close($conn);
