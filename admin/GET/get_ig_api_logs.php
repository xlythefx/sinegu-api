<?php
/**
 * GET IG.com API event logs (ig_api_logs) for admin.
 * GET /admin/GET/get_ig_api_logs.php?limit=500&offset=0&q=search+text
 * Legacy-compatible params: event_type, source, ticker (ticker ignored when q is set)
 *
 * Mirrors admin/GET/get_capital_api_logs.php. ig_api_logs has been written by
 * igcom/log_ig_api_event.php since migration 056 but had NO reader at all, so
 * every IG rate limit and auth failure was recorded and never seen. This is that
 * reader.
 *
 * Joins: ig_api_logs.account_id -> ig_accounts.id -> ig_accounts.uni_id = user_credentials.uni_id
 * Returns user_display_name (user_credentials.name) and user_email.
 *
 * Note is_read arrives with migration 085; until it is applied the include_read
 * filter is skipped rather than erroring, so this endpoint works on a box that
 * is mid-migration.
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

$limit        = isset($_GET['limit']) ? min(10000, max(1, (int) $_GET['limit'])) : 2000;
$offset       = isset($_GET['offset']) ? max(0, (int) $_GET['offset']) : 0;
$searchQ      = isset($_GET['q']) && $_GET['q'] !== '' ? trim($_GET['q']) : null;
$filterEvent  = isset($_GET['event_type']) && $_GET['event_type'] !== '' ? trim($_GET['event_type']) : null;
$filterSource = isset($_GET['source']) && $_GET['source'] !== '' ? trim($_GET['source']) : null;
$filterTicker = isset($_GET['ticker']) && $_GET['ticker'] !== '' ? trim($_GET['ticker']) : null;
$includeRead  = isset($_GET['include_read']) && ($_GET['include_read'] === '1' || $_GET['include_read'] === 'true');

// Tolerate a pre-085 schema: skip the is_read filter rather than 500.
$hasIsRead = false;
$colRes = @mysqli_query($conn, "SHOW COLUMNS FROM ig_api_logs LIKE 'is_read'");
if ($colRes) {
    $hasIsRead = mysqli_num_rows($colRes) > 0;
    mysqli_free_result($colRes);
}

$from_sql = ' FROM ig_api_logs l
    LEFT JOIN ig_accounts a ON l.account_id = a.id
    LEFT JOIN user_credentials uc ON a.uni_id = uc.uni_id ';

$where = [];
if ($hasIsRead && !$includeRead) {
    $where[] = 'l.is_read = 0';
}
if ($filterEvent !== null) {
    $where[] = "l.event_type = '" . mysqli_real_escape_string($conn, $filterEvent) . "'";
}
if ($filterSource !== null) {
    $where[] = "l.source LIKE '%" . mysqli_real_escape_string($conn, $filterSource) . "%'";
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
} elseif ($filterTicker !== null) {
    $where[] = "l.ticker LIKE '%" . mysqli_real_escape_string($conn, strtoupper($filterTicker)) . "%'";
}
$where_sql = count($where) > 0 ? ' WHERE ' . implode(' AND ', $where) : '';

$count_sql = 'SELECT COUNT(DISTINCT l.id) AS cnt' . $from_sql . $where_sql;
$count_res = @mysqli_query($conn, $count_sql);
$total = $count_res ? (int) mysqli_fetch_assoc($count_res)['cnt'] : 0;

$readCol = $hasIsRead ? 'l.is_read' : '0 AS is_read';
$sql = "SELECT l.id, l.account_id, l.account_name, l.ticker, l.action, l.event_type,
               l.http_status, l.source, l.message, l.email_sent, $readCol, l.created_at,
               uc.name AS user_display_name, uc.email AS user_email
        " . $from_sql . $where_sql . ' ORDER BY l.created_at DESC, l.id DESC LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset;

$result = @mysqli_query($conn, $sql);

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
        'id'                => (int) $row['id'],
        'account_id'        => $row['account_id'] === null ? null : (int) $row['account_id'],
        'account_name'      => $row['account_name'],
        'ticker'            => $row['ticker'],
        'action'            => $row['action'],
        'event_type'        => $row['event_type'],
        'http_status'       => $row['http_status'] === null ? null : (int) $row['http_status'],
        'source'            => $row['source'],
        'message'           => $row['message'],
        'email_sent'        => (int) $row['email_sent'] === 1,
        'is_read'           => (int) $row['is_read'] === 1,
        'created_at'        => $row['created_at'],
        'user_display_name' => $row['user_display_name'],
        'user_email'        => $row['user_email'],
    ];
}
mysqli_free_result($result);

// Distinct values across ALL rows, so the filter dropdowns stay stable
// regardless of what is currently filtered.
$eventTypes = [];
$dist_event = @mysqli_query($conn, "SELECT DISTINCT event_type FROM ig_api_logs WHERE event_type IS NOT NULL AND event_type <> '' ORDER BY event_type ASC");
if ($dist_event) {
    while ($row = mysqli_fetch_assoc($dist_event)) {
        $eventTypes[] = $row['event_type'];
    }
    mysqli_free_result($dist_event);
}

$sources = [];
$dist_source = @mysqli_query($conn, "SELECT DISTINCT source FROM ig_api_logs WHERE source IS NOT NULL AND source <> '' ORDER BY source ASC");
if ($dist_source) {
    while ($row = mysqli_fetch_assoc($dist_source)) {
        $sources[] = $row['source'];
    }
    mysqli_free_result($dist_source);
}

mysqli_close($conn);

echo json_encode([
    'success'      => true,
    'logs'         => $logs,
    'total'        => $total,
    'limit'        => $limit,
    'offset'       => $offset,
    'event_types'  => $eventTypes,
    'sources'      => $sources,
    'has_is_read'  => $hasIsRead,
]);
