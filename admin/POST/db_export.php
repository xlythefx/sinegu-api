<?php
/**
 * Export a whole table or a single column, as CSV or SQL (admin Database page).
 * GET /admin/POST/db_export.php?table=X[&column=Y]&format=csv|sql
 *
 * Streams the ENTIRE table (no pagination). `table` and `column` are validated
 * against the live schema allow-list before any interpolation. Fetched via GET
 * (like download_database_backup.php) so a plain <a>/blob download works.
 *
 * On failure returns JSON { success:false, message, detail } (matching POST/ house style);
 * on success streams a file with a Content-Disposition attachment header.
 */

ini_set('display_errors', 0);
error_reporting(E_ALL);

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

function jsonError($message, $detail = null, $code = 400) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code($code);
    echo json_encode(['success' => false, 'message' => $message, 'detail' => $detail]);
    exit;
}

require_once __DIR__ . '/../../conn.php';
if (!$conn) jsonError('Database connection failed', isset($conn_error) ? $conn_error : 'Check conn.php', 500);

$table  = isset($_GET['table'])  ? trim($_GET['table'])  : '';
$column = isset($_GET['column']) && $_GET['column'] !== '' ? trim($_GET['column']) : null;
$format = isset($_GET['format']) ? strtolower(trim($_GET['format'])) : 'csv';

if ($table === '') jsonError('Missing table parameter');
if ($format !== 'csv' && $format !== 'sql') jsonError('Invalid format (use csv or sql)');

// Validate table against the live SHOW TABLES allow-list.
$allowedTables = [];
$tres = mysqli_query($conn, "SHOW TABLES");
if ($tres) {
    while ($r = mysqli_fetch_array($tres)) $allowedTables[$r[0]] = true;
    mysqli_free_result($tres);
}
if (!isset($allowedTables[$table])) jsonError('Unknown table');

// Enumerate real columns for this table (also the allow-list for `column`).
$allColumns = [];
$cres = mysqli_query($conn, "SELECT * FROM `$table` LIMIT 0");
if (!$cres) jsonError('Could not read table columns', mysqli_error($conn), 500);
while ($f = mysqli_fetch_field($cres)) $allColumns[] = $f->name;
mysqli_free_result($cres);

if ($column !== null && !in_array($column, $allColumns, true)) jsonError('Unknown column');

$exportColumns = $column !== null ? [$column] : $allColumns;
$colListSql = implode(',', array_map(function ($c) { return "`$c`"; }, $exportColumns));

$sel = mysqli_query($conn, "SELECT $colListSql FROM `$table`");
if (!$sel) jsonError('Query failed', mysqli_error($conn), 500);

$baseName = $table . ($column !== null ? '_' . $column : '') . '_' . date('Y-m-d');

if ($format === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $baseName . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, $exportColumns);
    while ($row = mysqli_fetch_assoc($sel)) {
        $line = [];
        foreach ($exportColumns as $c) {
            $line[] = $row[$c] === null ? '' : $row[$c];
        }
        fputcsv($out, $line);
    }
    fclose($out);
    mysqli_free_result($sel);
    mysqli_close($conn);
    exit;
}

// format === 'sql' — emit INSERT statements for the selected columns.
header('Content-Type: application/sql; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $baseName . '.sql"');

echo "-- Sinegu DB export: `$table`" . ($column !== null ? " (column `$column`)" : "") . " — " . date('Y-m-d H:i:s') . "\n";
echo "SET NAMES utf8mb4;\n\n";

while ($row = mysqli_fetch_assoc($sel)) {
    $vals = [];
    foreach ($exportColumns as $c) {
        $v = $row[$c];
        $vals[] = $v === null ? 'NULL' : "'" . mysqli_real_escape_string($conn, $v) . "'";
    }
    echo "INSERT INTO `$table` ($colListSql) VALUES (" . implode(',', $vals) . ");\n";
}

mysqli_free_result($sel);
mysqli_close($conn);
exit;
