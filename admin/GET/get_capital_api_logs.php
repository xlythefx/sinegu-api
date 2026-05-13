<?php
/**
 * GET Capital.com API event logs (capital_api_logs) for admin.
 * GET /admin/GET/get_capital_api_logs.php?limit=500&offset=0&q=search+text
 * Legacy: event_type, source, ticker (ignored when q is set)
 *
 * Joins: capital_api_logs.account_id -> accounts.id -> accounts.uni_id = user_credentials.uni_id
 * Returns user_display_name (user_credentials.name) and user_email (user_credentials.email).
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

$limit = isset($_GET['limit']) ? min(10000, max(1, (int) $_GET['limit'])) : 2000;
$offset = isset($_GET['offset']) ? max(0, (int) $_GET['offset']) : 0;
$searchQ = isset($_GET['q']) && $_GET['q'] !== '' ? trim($_GET['q']) : null;
$filterEvent = isset($_GET['event_type']) && $_GET['event_type'] !== '' ? trim($_GET['event_type']) : null;
$filterSource = isset($_GET['source']) && $_GET['source'] !== '' ? trim($_GET['source']) : null;
$filterTicker = isset($_GET['ticker']) && $_GET['ticker'] !== '' ? trim($_GET['ticker']) : null;
// include_read=1 -> show read entries too. Default: hide read entries.
$includeRead = isset($_GET['include_read']) && ($_GET['include_read'] === '1' || $_GET['include_read'] === 'true');

$from_sql = " FROM capital_api_logs l
    LEFT JOIN accounts a ON l.account_id = a.id
    LEFT JOIN user_credentials uc ON a.uni_id = uc.uni_id ";

$where = [];
if (!$includeRead) {
    $where[] = "l.is_read = 0";
}
if ($searchQ !== null) {
    $esc = mysqli_real_escape_string($conn, $searchQ);
    $where[] = "(
    l.event_type LIKE '%$esc%'
    OR l.ticker LIKE '%$esc%'
    OR l.message LIKE '%$esc%'
    OR l.source LIKE '%$esc%'
    OR l.account_name LIKE '%$esc%'
    OR l.action LIKE '%$esc%'
    OR (l.http_status IS NOT NULL AND CAST(l.http_status AS CHAR) LIKE '%$esc%')
    OR (l.account_id IS NOT NULL AND CAST(l.account_id AS CHAR) LIKE '%$esc%')
    OR uc.name LIKE '%$esc%'
    OR uc.email LIKE '%$esc%'
  )";
} else {
    if ($filterEvent !== null) {
        $where[] = "l.event_type = '" . mysqli_real_escape_string($conn, $filterEvent) . "'";
    }
    if ($filterSource !== null) {
        $where[] = "l.source LIKE '%" . mysqli_real_escape_string($conn, $filterSource) . "%'";
    }
    if ($filterTicker !== null) {
        $where[] = "l.ticker LIKE '%" . mysqli_real_escape_string($conn, strtoupper($filterTicker)) . "%'";
    }
}
$where_sql = count($where) > 0 ? ' WHERE ' . implode(' AND ', $where) : '';

$count_sql = "SELECT COUNT(DISTINCT l.id) AS cnt" . $from_sql . $where_sql;
$count_res = mysqli_query($conn, $count_sql);
$total = $count_res ? (int) mysqli_fetch_assoc($count_res)['cnt'] : 0;

$limit = (int) $limit;
$offset = (int) $offset;
$sql = "SELECT l.id, l.account_id, l.account_name, l.ticker, l.action, l.event_type, l.http_status, l.source, l.message, l.email_sent, l.is_read, l.created_at,
        uc.name AS user_display_name, uc.email AS user_email
        " . $from_sql . $where_sql . " ORDER BY l.created_at DESC, l.id DESC LIMIT " . $limit . " OFFSET " . $offset;

$result = mysqli_query($conn, $sql);

if (!$result) {
    $err = mysqli_error($conn);
    mysqli_close($conn);
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $err]);
    exit;
}

$logs = [];
while ($row = mysqli_fetch_assoc($result)) {
    $logs[] = [
        'id' => (int) $row['id'],
        'account_id' => $row['account_id'] === null ? null : (int) $row['account_id'],
        'account_name' => $row['account_name'],
        'ticker' => $row['ticker'],
        'action' => $row['action'],
        'event_type' => $row['event_type'],
        'http_status' => $row['http_status'] === null ? null : (int) $row['http_status'],
        'source' => $row['source'],
        'message' => $row['message'],
        'email_sent' => (int) $row['email_sent'] === 1,
        'is_read' => (int) $row['is_read'] === 1,
        'created_at' => $row['created_at'],
        'user_display_name' => $row['user_display_name'],
        'user_email' => $row['user_email'],
    ];
}

mysqli_free_result($result);
mysqli_close($conn);

echo json_encode([
    'success' => true,
    'logs' => $logs,
    'total' => $total,
    'limit' => $limit,
    'offset' => $offset,
]);
