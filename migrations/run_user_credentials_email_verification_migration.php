<?php
/**
 * Migration: Add email_verified, verification_code, verification_code_expires_at, verification_code_attempts to user_credentials.
 * Usage: php run_user_credentials_email_verification_migration.php
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../conn.php';

if (!$conn) {
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit;
}

$checkSql = "SELECT COUNT(*) AS cnt FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_credentials' AND COLUMN_NAME = 'email_verified'";
$res = mysqli_query($conn, $checkSql);
$exists = $res && (int) mysqli_fetch_assoc($res)['cnt'] > 0;

if (!$exists) {
    $sqlFile = __DIR__ . '/023_add_user_credentials_email_verification.sql';
    $sql = file_get_contents($sqlFile);
    if ($sql === false) {
        echo json_encode(['success' => false, 'error' => 'Migration file not found']);
        mysqli_close($conn);
        exit;
    }
    $sql = preg_replace('/^\s*--[^\n]*\n/m', '', $sql);
    $statements = array_filter(array_map('trim', explode(';', $sql)));
    foreach ($statements as $stmt) {
        if ($stmt === '') continue;
        if (!mysqli_query($conn, $stmt)) {
            echo json_encode(['success' => false, 'error' => mysqli_error($conn)]);
            mysqli_close($conn);
            exit;
        }
    }
}

// Backfill: mark existing users (no pending verification code) as verified
mysqli_query($conn, "UPDATE user_credentials SET email_verified = 1 WHERE verification_code IS NULL");

echo json_encode(['success' => true, 'message' => 'user_credentials email verification columns added or already present']);
mysqli_close($conn);
