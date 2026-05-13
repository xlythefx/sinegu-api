<?php
/**
 * Migration: Add strategy column to positions.
 * Usage: php run_positions_strategy_migration.php
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../conn.php';

if (!$conn) {
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit;
}

$checkSql = "SELECT COUNT(*) AS cnt FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'positions' AND COLUMN_NAME = 'strategy'";
$res = mysqli_query($conn, $checkSql);
$exists = $res && (int) mysqli_fetch_assoc($res)['cnt'] > 0;

if ($exists) {
    echo json_encode(['success' => true, 'message' => 'positions.strategy already exists']);
    mysqli_close($conn);
    exit;
}

$sqlFile = __DIR__ . '/050_add_positions_strategy.sql';
$sql = file_get_contents($sqlFile);
if ($sql === false) {
    echo json_encode(['success' => false, 'error' => 'Migration file not found: 050_add_positions_strategy.sql']);
    mysqli_close($conn);
    exit;
}

$sql = preg_replace('/^\s*--[^\n]*\n/m', '', $sql);
$statements = array_filter(array_map('trim', explode(';', $sql)));
foreach ($statements as $stmt) {
    if ($stmt === '') {
        continue;
    }
    if (!mysqli_query($conn, $stmt)) {
        echo json_encode(['success' => false, 'error' => mysqli_error($conn)]);
        mysqli_close($conn);
        exit;
    }
}

echo json_encode(['success' => true, 'message' => 'positions.strategy column added']);
mysqli_close($conn);
