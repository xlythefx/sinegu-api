<?php
/**
 * Migration: Create user_disabled_assets table.
 * Usage: php run_user_disabled_assets_migration.php
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../conn.php';

if (!$conn) {
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit;
}

$checkSql = "SELECT COUNT(*) AS cnt FROM INFORMATION_SCHEMA.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_disabled_assets'";
$res = mysqli_query($conn, $checkSql);
$exists = $res && (int) mysqli_fetch_assoc($res)['cnt'] > 0;

if ($exists) {
    echo json_encode(['success' => true, 'message' => 'user_disabled_assets table already exists']);
    mysqli_close($conn);
    exit;
}

$sql = "CREATE TABLE user_disabled_assets (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uni_id VARCHAR(36) NOT NULL COMMENT 'user_credentials.uni_id',
    ticker VARCHAR(64) NOT NULL COMMENT 'Same as assets.ticker (typically uppercase)',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_user_disabled_asset (uni_id, ticker),
    KEY idx_user_disabled_assets_uni_id (uni_id),
    KEY idx_user_disabled_assets_ticker (ticker)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='User opted-out of trading this ticker (per-user)'";

if (!mysqli_query($conn, $sql)) {
    echo json_encode(['success' => false, 'error' => mysqli_error($conn)]);
    mysqli_close($conn);
    exit;
}

echo json_encode(['success' => true, 'message' => 'user_disabled_assets table created']);
mysqli_close($conn);
