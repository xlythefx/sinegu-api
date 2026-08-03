<?php
/**
 * Get Binance transactions (deposits/withdrawals) for a user by uni_id.
 * Mirrors get_capital_transactions.php but by uni_id (all Binance accounts).
 * GET: ?uni_id=xxx&limit=100&offset=0
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed. Use GET.']);
    exit;
}

require_once __DIR__ . '/../conn.php';

$uni_id = isset($_GET['uni_id']) ? trim($_GET['uni_id']) : null;
$limit = isset($_GET['limit']) ? max(1, min(1000, (int) $_GET['limit'])) : 100;
$offset = isset($_GET['offset']) ? max(0, (int) $_GET['offset']) : 0;

if (!$uni_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing required parameter: uni_id']);
    exit;
}

if (!$conn) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit;
}

$uni_id = mysqli_real_escape_string($conn, $uni_id);

$count_sql = "SELECT COUNT(*) AS total FROM binance_transactions WHERE uni_id = '{$uni_id}'";
$count_res = mysqli_query($conn, $count_sql);
$total_count = 0;
if ($count_res && $row = mysqli_fetch_assoc($count_res)) {
    $total_count = (int) $row['total'];
}
if ($count_res) mysqli_free_result($count_res);

// effective_at: broker-true transfer time (transaction_time is Binance epoch ms;
// created_at is only when our sync inserted the row — a backfill stamps today).
$sql = "SELECT id, api_key, uni_id, type, amount, balance_after, tran_id, currency, transaction_time, info, created_at,
        COALESCE(FROM_UNIXTIME(transaction_time / 1000), created_at) AS effective_at
    FROM binance_transactions
    WHERE uni_id = '{$uni_id}'
    ORDER BY created_at DESC
    LIMIT {$limit} OFFSET {$offset}";
$result = mysqli_query($conn, $sql);

if (!$result) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => mysqli_error($conn)]);
    exit;
}

$transactions = [];
while ($row = mysqli_fetch_assoc($result)) {
    $transactions[] = [
        'id' => (int) $row['id'],
        'account_id' => 0,
        'api_key' => $row['api_key'],
        'uni_id' => $row['uni_id'],
        'type' => $row['type'],
        'amount' => (float) $row['amount'],
        'balance_after' => isset($row['balance_after']) && $row['balance_after'] !== null ? (float) $row['balance_after'] : null,
        'broker_reference' => isset($row['tran_id']) ? (string) $row['tran_id'] : ($row['info'] ?? null),
        'currency' => $row['currency'] ?? 'USDT',
        'created_at' => $row['created_at'],
        'effective_at' => $row['effective_at'],
    ];
}

mysqli_free_result($result);
mysqli_close($conn);

echo json_encode([
    'success' => true,
    'transactions' => $transactions,
    'total' => $total_count,
    'count' => count($transactions),
    'limit' => $limit,
    'offset' => $offset,
    'has_more' => ($offset + count($transactions)) < $total_count,
]);
