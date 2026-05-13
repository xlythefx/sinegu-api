<?php
/**
 * Migration: Add affiliate_link to bot_config (VARCHAR).
 * Usage: php run_bot_config_affiliate_link_migration.php
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../conn.php';

if (!$conn) {
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit;
}

$checkSql = "SELECT COUNT(*) AS cnt FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bot_config' AND COLUMN_NAME = 'affiliate_link'";
$res = mysqli_query($conn, $checkSql);
$exists = $res && (int) mysqli_fetch_assoc($res)['cnt'] > 0;

if (!$exists) {
    $sqlFile = __DIR__ . '/022_add_bot_config_affiliate_link.sql';
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

echo json_encode(['success' => true, 'message' => 'bot_config.affiliate_link column added or already present']);
mysqli_close($conn);
