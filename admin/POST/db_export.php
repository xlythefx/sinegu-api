<?php
/**
 * Export a table (or a single column), as CSV or SQL (admin Database page).
 * GET /admin/POST/db_export.php?table=X[&column=Y]&format=csv|sql[&limit=N&order=first|last]
 *
 * Streams the whole table by default. `limit` + `order` narrow it to the
 * first/last N rows (ordered by primary key, or the first column if none).
 * `table` and `column` are validated against the live schema allow-list before
 * any interpolation. Fetched via GET (like download_database_backup.php).
 *
 * On failure returns JSON { success:false, message, detail }; on success streams
 * a file with a Content-Disposition attachment header.
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

function csvRow($out, $cols, $row) {
    $line = [];
    foreach ($cols as $c) $line[] = $row[$c] === null ? '' : $row[$c];
    fputcsv($out, $line);
}

function sqlRow($conn, $table, $colListSql, $cols, $row) {
    $vals = [];
    foreach ($cols as $c) {
        $v = $row[$c];
        $vals[] = $v === null ? 'NULL' : "'" . mysqli_real_escape_string($conn, $v) . "'";
    }
    return "INSERT INTO `$table` ($colListSql) VALUES (" . implode(',', $vals) . ");\n";
}

require_once __DIR__ . '/../../conn.php';
if (!$conn) jsonError('Database connection failed', isset($conn_error) ? $conn_error : 'Check conn.php', 500);

$table  = isset($_GET['table'])  ? trim($_GET['table'])  : '';
$column = isset($_GET['column']) && $_GET['column'] !== '' ? trim($_GET['column']) : null;
$format = isset($_GET['format']) ? strtolower(trim($_GET['format'])) : 'csv';
$limit  = isset($_GET['limit']) ? min(100000, max(0, (int) $_GET['limit'])) : 0; // 0 = all rows
$order  = (isset($_GET['order']) && strtolower(trim($_GET['order'])) === 'last') ? 'last' : 'first';

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

// Ordering column for first/last: primary key, else the first column.
$orderCol = $allColumns[0];
$kres = mysqli_query($conn, "SHOW KEYS FROM `$table` WHERE Key_name = 'PRIMARY'");
if ($kres) {
    $k = mysqli_fetch_assoc($kres);
    if ($k && isset($k['Column_name'])) $orderCol = $k['Column_name'];
    mysqli_free_result($kres);
}

$sql = "SELECT $colListSql FROM `$table`";
$reverse = false;
if ($limit > 0) {
    $dir = $order === 'last' ? 'DESC' : 'ASC';
    $sql .= " ORDER BY `" . mysqli_real_escape_string($conn, $orderCol) . "` $dir LIMIT " . (int) $limit;
    $reverse = ($order === 'last'); // buffer + flip so "last N" reads oldest→newest
}

$sel = mysqli_query($conn, $sql);
if (!$sel) jsonError('Query failed', mysqli_error($conn), 500);

// When taking the last N, buffer (bounded by $limit) then reverse to natural order.
$buffer = null;
if ($reverse) {
    $buffer = [];
    while ($r = mysqli_fetch_assoc($sel)) $buffer[] = $r;
    $buffer = array_reverse($buffer);
}

$scopeSuffix = $limit > 0 ? '_' . $order . $limit : '';
$baseName = $table . ($column !== null ? '_' . $column : '') . $scopeSuffix . '_' . date('Y-m-d');

if ($format === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $baseName . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, $exportColumns);
    if ($buffer !== null) {
        foreach ($buffer as $row) csvRow($out, $exportColumns, $row);
    } else {
        while ($row = mysqli_fetch_assoc($sel)) csvRow($out, $exportColumns, $row);
    }
    fclose($out);
    mysqli_free_result($sel);
    mysqli_close($conn);
    exit;
}

// format === 'sql'
header('Content-Type: application/sql; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $baseName . '.sql"');

echo "-- Sinegu DB export: `$table`" . ($column !== null ? " (column `$column`)" : "")
    . ($limit > 0 ? " [" . $order . " " . $limit . "]" : "") . " — " . date('Y-m-d H:i:s') . "\n";
echo "SET NAMES utf8mb4;\n\n";

if ($buffer !== null) {
    foreach ($buffer as $row) echo sqlRow($conn, $table, $colListSql, $exportColumns, $row);
} else {
    while ($row = mysqli_fetch_assoc($sel)) echo sqlRow($conn, $table, $colListSql, $exportColumns, $row);
}

mysqli_free_result($sel);
mysqli_close($conn);
exit;
