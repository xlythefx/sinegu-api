<?php
/**
 * Read-only: Retry queue status dashboard.
 * GET /flask/retry_status.php — shows all pending/failed/needs_review rows with:
 *   - affected accounts (names, not just IDs)
 *   - signal age (how long since created_at)
 *   - next attempt time
 *   - attempt progress (attempt_count / max_attempts)
 *
 * ?broker=capital|binance|mexc|ig|all (default 'capital', so the existing Capital view
 * is unchanged once Binance/MEXC share the table - migration 094). Without the broker
 * column every row is capital.
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/../includes/retry_queue_schema.php';
$brokerParam = isset($_GET['broker']) ? strtolower(trim((string) $_GET['broker'])) : '';
$broker = $brokerParam === 'all' ? 'all' : retry_queue_broker($brokerParam);
if ($broker === null) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'broker must be one of: all, ' . implode(', ', RETRY_QUEUE_BROKERS)]);
    exit;
}

mysqli_report(MYSQLI_REPORT_OFF);
require_once __DIR__ . '/../conn.php';

if (!$conn) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit;
}

$schema = retry_queue_columns($conn);
if (!$schema['broker'] && $broker !== 'capital' && $broker !== 'all') {
    // Pre-094: the table only holds capital rows, so any other broker has none.
    echo json_encode(['success' => true, 'timestamp' => date('Y-m-d H:i:s'), 'broker' => $broker, 'count' => 0, 'retries' => []]);
    mysqli_close($conn);
    exit;
}
$brokerSelect = $schema['broker'] ? 'broker,' : "'capital' AS broker,";
$brokerSql = ($schema['broker'] && $broker !== 'all') ? " AND broker='" . $broker . "'" : '';

$sql = "
  SELECT
    id,
    $brokerSelect
    kind,
    action,
    ticker,
    price,
    strategy,
    status,
    target_uni_ids,
    attempt_count,
    max_attempts,
    created_at,
    next_attempt_at,
    last_error,
    TIMESTAMPDIFF(MINUTE, created_at, NOW()) AS age_minutes
  FROM capital_retry_queue
  WHERE status IN ('pending', 'processing', 'failed', 'needs_review')$brokerSql
  ORDER BY next_attempt_at ASC, created_at DESC
";

$result = mysqli_query($conn, $sql);
if (!$result) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => mysqli_error($conn)]);
    exit;
}

$rows = [];
while ($row = mysqli_fetch_assoc($result)) {
    // Resolve account names from uni_ids
    $uni_ids = json_decode((string) ($row['target_uni_ids'] ?? ''), true) ?: [];
    $accounts = [];
    if ($uni_ids) {
        $ids_str = implode(',', array_map('intval', $uni_ids));
        $acct_q = mysqli_query($conn, "SELECT id, name FROM accounts WHERE id IN ($ids_str)");
        while ($acct = mysqli_fetch_assoc($acct_q)) {
            $accounts[] = $acct['name'];
        }
    }

    // Format age nicely
    $age_min = (int) $row['age_minutes'];
    if ($age_min < 60) {
        $age_display = $age_min . 'm';
    } else {
        $hours = intdiv($age_min, 60);
        $mins = $age_min % 60;
        $age_display = $hours . 'h ' . $mins . 'm';
    }

    $rows[] = [
        'id' => (int) $row['id'],
        'broker' => $row['broker'],
        'kind' => $row['kind'],
        'action' => $row['action'],
        'ticker' => $row['ticker'],
        'price' => (float) $row['price'],
        'strategy' => $row['strategy'],
        'status' => $row['status'],
        'affected_accounts' => $accounts,
        'attempt' => $row['attempt_count'] . '/' . $row['max_attempts'],
        'age' => $age_display,
        'created_at' => $row['created_at'],
        'next_attempt_at' => $row['next_attempt_at'],
        'last_error' => $row['last_error'] ?: null,
    ];
}

echo json_encode([
    'success' => true,
    'timestamp' => date('Y-m-d H:i:s'),
    'broker' => $broker,
    'count' => count($rows),
    'retries' => $rows,
]);
mysqli_close($conn);
