<?php
/**
 * List all tables in the database (admin Database page).
 * GET /admin/GET/db_list_tables.php
 *
 * Returns every table name plus its exact row count.
 */

ini_set('display_errors', 0);
error_reporting(E_ALL);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

require_once __DIR__ . '/../../conn.php';

if (!$conn) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit;
}

$res = mysqli_query($conn, "SHOW TABLES");
if (!$res) {
    $err = mysqli_error($conn);
    mysqli_close($conn);
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $err]);
    exit;
}

$tables = [];
while ($row = mysqli_fetch_array($res)) {
    $tables[] = $row[0];
}
mysqli_free_result($res);

$out = [];
foreach ($tables as $table) {
    $rows = null;
    $safe = mysqli_real_escape_string($conn, $table);
    $cres = mysqli_query($conn, "SELECT COUNT(*) AS cnt FROM `$safe`");
    if ($cres) {
        $rows = (int) mysqli_fetch_assoc($cres)['cnt'];
        mysqli_free_result($cres);
    }
    $out[] = ['name' => $table, 'rows' => $rows];
}

mysqli_close($conn);

echo json_encode(['success' => true, 'tables' => $out]);
