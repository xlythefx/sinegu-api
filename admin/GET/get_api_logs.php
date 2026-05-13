<?php
/**
 * GET API logs for admin dashboard.
 * GET /admin/GET/get_api_logs.php?limit=100&offset=0&api_name=&action=&success=
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

$limit = isset($_GET['limit']) ? min(500, max(1, (int) $_GET['limit'])) : 100;
$offset = isset($_GET['offset']) ? max(0, (int) $_GET['offset']) : 0;
$filter_api = isset($_GET['api_name']) && $_GET['api_name'] !== '' ? trim($_GET['api_name']) : null;
$filter_action = isset($_GET['action']) && $_GET['action'] !== '' ? trim($_GET['action']) : null;
$filter_success = isset($_GET['success']) && $_GET['success'] !== '' ? (int) $_GET['success'] : null;

$where = [];
$safe_api = $filter_api !== null ? mysqli_real_escape_string($conn, $filter_api) : null;
$safe_action = $filter_action !== null ? mysqli_real_escape_string($conn, $filter_action) : null;

if ($safe_api !== null) {
    $where[] = "api_name LIKE '%" . $safe_api . "%'";
}
if ($safe_action !== null) {
    $where[] = "action = '" . $safe_action . "'";
}
if ($filter_success !== null && ($filter_success === 0 || $filter_success === 1)) {
    $where[] = 'success = ' . (int) $filter_success;
}

$where_sql = count($where) > 0 ? ' WHERE ' . implode(' AND ', $where) : '';

// Count total
$count_sql = "SELECT COUNT(*) AS cnt FROM api_logs" . $where_sql;
$count_res = mysqli_query($conn, $count_sql);
$total = $count_res ? (int) mysqli_fetch_assoc($count_res)['cnt'] : 0;

// Fetch logs (limit/offset are integers, safe)
$limit = (int) $limit;
$offset = (int) $offset;
$sql = "SELECT id, api_name, description, action, success, created_at FROM api_logs" . $where_sql . " ORDER BY created_at DESC LIMIT " . $limit . " OFFSET " . $offset;
$result = mysqli_query($conn, $sql);

if (!$result) {
    mysqli_close($conn);
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => mysqli_error($conn)]);
    exit;
}

$logs = [];
while ($row = mysqli_fetch_assoc($result)) {
    $logs[] = [
        'id' => (int) $row['id'],
        'api_name' => $row['api_name'],
        'description' => $row['description'] ?? '',
        'action' => $row['action'],
        'success' => (int) $row['success'] === 1,
        'failed' => (int) $row['success'] === 0,
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
