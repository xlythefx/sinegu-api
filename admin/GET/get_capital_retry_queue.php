<?php
/**
 * GET Capital.com retry queue (capital_retry_queue) for admin.
 * GET /admin/GET/get_capital_retry_queue.php?limit=100&offset=0&status=pending&q=search
 *
 * Returns queued entry/exit signals awaiting retry against Capital.com,
 * plus a status summary (count per status).
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

$limit = isset($_GET['limit']) ? min(1000, max(1, (int) $_GET['limit'])) : 200;
$offset = isset($_GET['offset']) ? max(0, (int) $_GET['offset']) : 0;
$searchQ = isset($_GET['q']) && $_GET['q'] !== '' ? trim($_GET['q']) : null;
$filterStatus = isset($_GET['status']) && $_GET['status'] !== '' && $_GET['status'] !== 'all'
    ? trim($_GET['status']) : null;

require_once __DIR__ . '/../../includes/retry_queue_schema.php';
$retryCols = retry_queue_columns($conn);

// Since migration 094 the table also holds Binance/MEXC jobs; this panel is Capital's.
$where = [];
if ($retryCols['broker']) {
    $where[] = "broker = 'capital'";
}
if ($filterStatus !== null) {
    $where[] = "status = '" . mysqli_real_escape_string($conn, $filterStatus) . "'";
}
if ($searchQ !== null) {
    $esc = mysqli_real_escape_string($conn, $searchQ);
    $where[] = "(
        ticker LIKE '%$esc%'
        OR strategy LIKE '%$esc%'
        OR action LIKE '%$esc%'
        OR kind LIKE '%$esc%'
        OR last_error LIKE '%$esc%'
        OR target_uni_ids LIKE '%$esc%'
        OR CAST(id AS CHAR) LIKE '%$esc%'
    )";
}
$where_sql = count($where) > 0 ? ' WHERE ' . implode(' AND ', $where) : '';

// Status summary across the whole table (ignores current filters).
$summary = ['pending' => 0, 'processing' => 0, 'executed' => 0, 'failed' => 0, 'needs_review' => 0];
$summary_res = mysqli_query($conn, "SELECT status, COUNT(*) AS cnt FROM capital_retry_queue"
    . ($retryCols['broker'] ? " WHERE broker = 'capital'" : '') . " GROUP BY status");
if ($summary_res) {
    while ($r = mysqli_fetch_assoc($summary_res)) {
        $summary[$r['status']] = (int) $r['cnt'];
    }
    mysqli_free_result($summary_res);
}

$count_res = mysqli_query($conn, "SELECT COUNT(*) AS cnt FROM capital_retry_queue" . $where_sql);
$total = $count_res ? (int) mysqli_fetch_assoc($count_res)['cnt'] : 0;

$sql = "SELECT id, kind, action, ticker, price, strategy, target_uni_ids, is_demo_only,
        attempt_count, max_attempts, next_attempt_at, status, last_error, created_at, updated_at
        FROM capital_retry_queue" . $where_sql . "
        ORDER BY
            FIELD(status, 'processing', 'pending', 'needs_review', 'failed', 'executed'),
            next_attempt_at ASC, id DESC
        LIMIT " . (int) $limit . " OFFSET " . (int) $offset;

$result = mysqli_query($conn, $sql);

if (!$result) {
    $err = mysqli_error($conn);
    mysqli_close($conn);
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $err]);
    exit;
}

$rows = [];
while ($row = mysqli_fetch_assoc($result)) {
    $targets = null;
    if ($row['target_uni_ids'] !== null && $row['target_uni_ids'] !== '') {
        $decoded = json_decode($row['target_uni_ids'], true);
        $targets = is_array($decoded) ? $decoded : null;
    }
    $rows[] = [
        'id' => (int) $row['id'],
        'kind' => $row['kind'],
        'action' => $row['action'],
        'ticker' => $row['ticker'],
        'price' => $row['price'] === null ? null : (float) $row['price'],
        'strategy' => $row['strategy'],
        'target_uni_ids' => $targets,
        'target_count' => $targets === null ? null : count($targets),
        'is_demo_only' => (int) $row['is_demo_only'] === 1,
        'attempt_count' => (int) $row['attempt_count'],
        'max_attempts' => (int) $row['max_attempts'],
        'next_attempt_at' => $row['next_attempt_at'],
        'status' => $row['status'],
        'last_error' => $row['last_error'],
        'created_at' => $row['created_at'],
        'updated_at' => $row['updated_at'],
    ];
}

mysqli_free_result($result);
mysqli_close($conn);

echo json_encode([
    'success' => true,
    'rows' => $rows,
    'total' => $total,
    'summary' => $summary,
    'limit' => $limit,
    'offset' => $offset,
]);
