<?php
/**
 * Migration Script: Add session token columns to accounts table
 *
 * Adds: cst, x_security_token, session_last_used_at for Capital.com session reuse.
 *
 * Usage: Run once from migrations folder: php run_account_session_columns_migration.php
 * Or via browser: http://localhost/sinegu-api/migrations/run_account_session_columns_migration.php
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

$sqlFile = __DIR__ . '/011_add_account_session_columns.sql';

if (!file_exists($sqlFile)) {
    echo json_encode([
        'success' => false,
        'error' => 'Migration file not found: ' . $sqlFile
    ]);
    exit;
}

// Get existing columns (MySQL 5.7 does not support ADD COLUMN IF NOT EXISTS)
$describeResult = mysqli_query($conn, "DESCRIBE `accounts`");
$existingColumns = [];
if ($describeResult) {
    while ($row = mysqli_fetch_assoc($describeResult)) {
        $existingColumns[] = $row['Field'];
    }
    mysqli_free_result($describeResult);
}

$errors = [];
$alterStatements = [
    'cst' => "ALTER TABLE `accounts` ADD COLUMN `cst` VARCHAR(512) DEFAULT NULL COMMENT 'Capital.com CST session token' AFTER `updated_at`",
    'x_security_token' => "ALTER TABLE `accounts` ADD COLUMN `x_security_token` VARCHAR(512) DEFAULT NULL COMMENT 'Capital.com X-SECURITY-TOKEN' AFTER `cst`",
    'session_last_used_at' => "ALTER TABLE `accounts` ADD COLUMN `session_last_used_at` DATETIME DEFAULT NULL COMMENT 'When session was last refreshed or used' AFTER `x_security_token`"
];

foreach ($alterStatements as $col => $statement) {
    if (in_array($col, $existingColumns)) {
        continue;
    }
    if (!mysqli_query($conn, $statement)) {
        $errors[] = mysqli_error($conn) . ' (column: ' . $col . ')';
    } else {
        $existingColumns[] = $col;
    }
}

if (!empty($errors)) {
    echo json_encode([
        'success' => false,
        'error' => 'Migration errors: ' . implode('; ', $errors),
        'errors' => $errors
    ]);
    exit;
}

// Verify columns exist on accounts
$describeResult = mysqli_query($conn, "DESCRIBE `accounts`");
$columns = [];
if ($describeResult) {
    while ($row = mysqli_fetch_assoc($describeResult)) {
        $columns[] = $row['Field'];
    }
    mysqli_free_result($describeResult);
}

$required = ['cst', 'x_security_token', 'session_last_used_at'];
$missing = array_diff($required, $columns);

if (!empty($missing)) {
    echo json_encode([
        'success' => false,
        'error' => 'Columns not found after migration: ' . implode(', ', $missing),
        'columns' => $columns
    ]);
    exit;
}

echo json_encode([
    'success' => true,
    'message' => 'Account session columns added successfully',
    'table' => 'accounts',
    'added_columns' => $required
]);

mysqli_close($conn);
