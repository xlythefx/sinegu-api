<?php
/**
 * Migration: Create strategies table + seed defaults.
 * Usage: php run_strategies_migration.php
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../conn.php';

if (!$conn) {
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit;
}

$checkSql = "SELECT COUNT(*) AS cnt FROM INFORMATION_SCHEMA.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'strategies'";
$res = mysqli_query($conn, $checkSql);
$exists = $res && (int) mysqli_fetch_assoc($res)['cnt'] > 0;

if ($exists) {
    echo json_encode(['success' => true, 'message' => 'strategies table already exists']);
    mysqli_close($conn);
    exit;
}

$sql = "CREATE TABLE strategies (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    strategy_key VARCHAR(64) NOT NULL COMMENT 'Canonical key, matches webhook/trades.strategy',
    enabled TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1 = active, 0 = paused globally',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_strategy_key (strategy_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Global strategy toggles'";

if (!mysqli_query($conn, $sql)) {
    echo json_encode(['success' => false, 'error' => mysqli_error($conn)]);
    mysqli_close($conn);
    exit;
}

$seed = [
    ['VWAP-Deviation', 1],
    ['mean_reversion', 1],
    ['trend_momentum', 1],
];
$ins = mysqli_prepare($conn, 'INSERT INTO strategies (strategy_key, enabled) VALUES (?, ?)');
foreach ($seed as $row) {
    mysqli_stmt_bind_param($ins, 'si', $row[0], $row[1]);
    mysqli_stmt_execute($ins);
}
mysqli_stmt_close($ins);

echo json_encode(['success' => true, 'message' => 'strategies table created and seeded']);
mysqli_close($conn);
