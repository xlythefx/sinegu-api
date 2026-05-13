<?php
/**
 * Migration: Create api_logs table.
 * Usage: php run_api_logs_migration.php
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../conn.php';

if (!$conn) {
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit;
}

$checkSql = "SELECT COUNT(*) AS cnt FROM INFORMATION_SCHEMA.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'api_logs'";
$res = mysqli_query($conn, $checkSql);
$exists = $res && (int) mysqli_fetch_assoc($res)['cnt'] > 0;

if ($exists) {
    echo json_encode(['success' => true, 'message' => 'api_logs table already exists']);
    mysqli_close($conn);
    exit;
}

$sql = "CREATE TABLE api_logs (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    api_name VARCHAR(128) NOT NULL COMMENT 'e.g. users/login, flask/delete_account',
    description VARCHAR(255) DEFAULT NULL COMMENT 'Human-readable description of the call',
    action VARCHAR(64) NOT NULL DEFAULT 'unknown' COMMENT 'login, create, update, delete, fetch, etc.',
    success TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1=success, 0=failed',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_api_name (api_name),
    KEY idx_action (action),
    KEY idx_success (success),
    KEY idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='API request logs for monitoring'";

if (!mysqli_query($conn, $sql)) {
    echo json_encode(['success' => false, 'error' => mysqli_error($conn)]);
    mysqli_close($conn);
    exit;
}

echo json_encode(['success' => true, 'message' => 'api_logs table created']);
mysqli_close($conn);
