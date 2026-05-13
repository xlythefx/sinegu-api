<?php
/**
 * Migration: Add api_key to ig_positions and ig_past_positions and add FK to ig_accounts.api_key.
 * Usage: visit http://localhost/sinegu-api/migrations/059_run_ig_positions_past_positions_api_key_migration.php
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../conn.php';

if (!$conn) {
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit;
}

$requiredTables = ['ig_accounts', 'ig_positions', 'ig_past_positions'];
foreach ($requiredTables as $table) {
    $checkSql = "SELECT COUNT(*) AS cnt FROM INFORMATION_SCHEMA.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '" . mysqli_real_escape_string($conn, $table) . "'";
    $res = mysqli_query($conn, $checkSql);
    $exists = $res && (int) mysqli_fetch_assoc($res)['cnt'] > 0;
    if (!$exists) {
        echo json_encode(['success' => false, 'error' => "Required table missing: {$table}"]);
        mysqli_close($conn);
        exit;
    }
}

$colCheck = "SELECT TABLE_NAME, COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME IN ('ig_positions', 'ig_past_positions')
               AND COLUMN_NAME = 'api_key'";
$colRes = mysqli_query($conn, $colCheck);
$hasPos = false;
$hasPast = false;
if ($colRes) {
    while ($row = mysqli_fetch_assoc($colRes)) {
        if ($row['TABLE_NAME'] === 'ig_positions') $hasPos = true;
        if ($row['TABLE_NAME'] === 'ig_past_positions') $hasPast = true;
    }
}

$fkCheck = "SELECT CONSTRAINT_NAME, TABLE_NAME FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA = DATABASE()
              AND REFERENCED_TABLE_NAME = 'ig_accounts'
              AND REFERENCED_COLUMN_NAME = 'api_key'
              AND TABLE_NAME IN ('ig_positions', 'ig_past_positions')
              AND COLUMN_NAME = 'api_key'";
$fkRes = mysqli_query($conn, $fkCheck);
$fkPos = false;
$fkPast = false;
if ($fkRes) {
    while ($row = mysqli_fetch_assoc($fkRes)) {
        if ($row['TABLE_NAME'] === 'ig_positions') $fkPos = true;
        if ($row['TABLE_NAME'] === 'ig_past_positions') $fkPast = true;
    }
}

if ($hasPos && $hasPast && $fkPos && $fkPast) {
    echo json_encode(['success' => true, 'message' => 'api_key columns and FKs already exist']);
    mysqli_close($conn);
    exit;
}

$sql = file_get_contents(__DIR__ . '/059_add_ig_positions_past_positions_api_key.sql');
if ($sql === false) {
    echo json_encode(['success' => false, 'error' => 'Migration file not found: 059_add_ig_positions_past_positions_api_key.sql']);
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
        echo json_encode([
            'success' => false,
            'error' => mysqli_error($conn),
            'statement' => $stmt,
        ]);
        mysqli_close($conn);
        exit;
    }
}

echo json_encode(['success' => true, 'message' => 'api_key added and linked on ig_positions + ig_past_positions']);
mysqli_close($conn);
