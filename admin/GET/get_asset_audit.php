<?php
/**
 * GET trading-asset change history (asset_audit) for the admin dashboard.
 * GET /admin/GET/get_asset_audit.php?limit=50&offset=0&asset_id=&ticker=&action=
 *
 * Returns each row with `changes` / `old_values` / `new_values` decoded back to JSON.
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

// Procedural error handling: make mysqli return false on failure instead of throwing
// (PHP 8.1+ defaults to throwing), so a missing table degrades to clean JSON, not an empty 500.
if (function_exists('mysqli_report')) {
    mysqli_report(MYSQLI_REPORT_OFF);
}

if (!$conn) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit;
}

$limit = isset($_GET['limit']) ? min(500, max(1, (int) $_GET['limit'])) : 50;
$offset = isset($_GET['offset']) ? max(0, (int) $_GET['offset']) : 0;
$filter_asset_id = isset($_GET['asset_id']) && $_GET['asset_id'] !== '' ? (int) $_GET['asset_id'] : null;
$filter_ticker = isset($_GET['ticker']) && $_GET['ticker'] !== '' ? trim($_GET['ticker']) : null;
$filter_action = isset($_GET['action']) && $_GET['action'] !== '' ? trim($_GET['action']) : null;

// If the audit table hasn't been migrated yet (e.g. on a freshly deployed VPS),
// return an empty result instead of erroring — the history is simply empty until then.
$table_check = mysqli_query($conn, "SHOW TABLES LIKE 'asset_audit'");
if (!$table_check || mysqli_num_rows($table_check) === 0) {
    mysqli_close($conn);
    echo json_encode([
        'success' => true,
        'logs' => [],
        'total' => 0,
        'limit' => $limit,
        'offset' => $offset,
    ]);
    exit;
}

$where = [];
if ($filter_asset_id !== null) {
    $where[] = 'asset_id = ' . (int) $filter_asset_id;
}
if ($filter_ticker !== null) {
    $safe_ticker = mysqli_real_escape_string($conn, $filter_ticker);
    $where[] = "ticker LIKE '%" . $safe_ticker . "%'";
}
if ($filter_action !== null && in_array($filter_action, ['create', 'update', 'delete'], true)) {
    $where[] = "action = '" . mysqli_real_escape_string($conn, $filter_action) . "'";
}

$where_sql = count($where) > 0 ? ' WHERE ' . implode(' AND ', $where) : '';

// Count total
$count_sql = "SELECT COUNT(*) AS cnt FROM asset_audit" . $where_sql;
$count_res = mysqli_query($conn, $count_sql);
$total = $count_res ? (int) mysqli_fetch_assoc($count_res)['cnt'] : 0;

// Fetch rows (limit/offset are ints, safe)
$limit = (int) $limit;
$offset = (int) $offset;
$sql = "SELECT id, asset_id, ticker, action, changes, old_values, new_values, created_at FROM asset_audit"
    . $where_sql . " ORDER BY created_at DESC, id DESC LIMIT " . $limit . " OFFSET " . $offset;
$result = mysqli_query($conn, $sql);

if (!$result) {
    mysqli_close($conn);
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => mysqli_error($conn)]);
    exit;
}

$decode = static function ($json) {
    if ($json === null || $json === '') {
        return null;
    }
    $decoded = json_decode($json, true);
    return (json_last_error() === JSON_ERROR_NONE) ? $decoded : null;
};

$logs = [];
while ($row = mysqli_fetch_assoc($result)) {
    $logs[] = [
        'id' => (int) $row['id'],
        'asset_id' => $row['asset_id'] !== null ? (int) $row['asset_id'] : null,
        'ticker' => $row['ticker'],
        'action' => $row['action'],
        'changes' => $decode($row['changes']),
        'old_values' => $decode($row['old_values']),
        'new_values' => $decode($row['new_values']),
        'created_at' => $row['created_at'],
    ];
}

mysqli_close($conn);

echo json_encode([
    'success' => true,
    'logs' => $logs,
    'total' => $total,
    'limit' => $limit,
    'offset' => $offset,
]);
