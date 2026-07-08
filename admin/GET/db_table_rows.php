<?php
/**
 * View rows of a single table, paginated (admin Database page).
 * GET /admin/GET/db_table_rows.php?table=X&limit=50&offset=0
 *
 * Validates `table` against the SHOW TABLES allow-list before use.
 * Returns column names, the paginated rows, and the total row count.
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

$table = isset($_GET['table']) ? trim($_GET['table']) : '';
if ($table === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing table parameter']);
    exit;
}

// Validate table against the SHOW TABLES allow-list — never trust the identifier.
$allowed = [];
$tres = mysqli_query($conn, "SHOW TABLES");
if ($tres) {
    while ($r = mysqli_fetch_array($tres)) {
        $allowed[$r[0]] = true;
    }
    mysqli_free_result($tres);
}
if (!isset($allowed[$table])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Unknown table']);
    exit;
}

$limit = isset($_GET['limit']) ? min(500, max(1, (int) $_GET['limit'])) : 50;
$offset = isset($_GET['offset']) ? max(0, (int) $_GET['offset']) : 0;

$count_res = mysqli_query($conn, "SELECT COUNT(*) AS cnt FROM `$table`");
$total = $count_res ? (int) mysqli_fetch_assoc($count_res)['cnt'] : 0;
if ($count_res) {
    mysqli_free_result($count_res);
}

$sql = "SELECT * FROM `$table` LIMIT " . (int) $limit . " OFFSET " . (int) $offset;
$result = mysqli_query($conn, $sql);

if (!$result) {
    $err = mysqli_error($conn);
    mysqli_close($conn);
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $err]);
    exit;
}

$columns = [];
while ($f = mysqli_fetch_field($result)) {
    $columns[] = $f->name;
}

$rows = [];
while ($row = mysqli_fetch_assoc($result)) {
    $rows[] = $row;
}

mysqli_free_result($result);
mysqli_close($conn);

echo json_encode([
    'success' => true,
    'table' => $table,
    'columns' => $columns,
    'rows' => $rows,
    'total' => $total,
    'limit' => $limit,
    'offset' => $offset,
]);
