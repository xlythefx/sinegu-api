<?php
/**
 * Get IG.com transactions (deposits/withdrawals) for a user by uni_id.
 * Mirrors get_binance_transactions.php.
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
$limit  = isset($_GET['limit'])  ? max(1, min(1000, (int) $_GET['limit']))  : 100;
$offset = isset($_GET['offset']) ? max(0, (int) $_GET['offset'])            : 0;

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

$count_sql = "SELECT COUNT(*) AS total
    FROM ig_transactions t
    JOIN ig_accounts a ON a.id = t.account_id
    WHERE a.uni_id = '{$uni_id}' AND a.deleted_at IS NULL
      AND t.type IN ('DEPOSIT','WITHDRAWAL')";
$count_res = mysqli_query($conn, $count_sql);
$total_count = 0;
if ($count_res && $row = mysqli_fetch_assoc($count_res)) {
    $total_count = (int) $row['total'];
}
if ($count_res) mysqli_free_result($count_res);

$sql = "SELECT t.id, t.account_id, a.uni_id, t.type, t.amount, t.currency, t.reference, t.transaction_date, t.cash_transaction
    FROM ig_transactions t
    JOIN ig_accounts a ON a.id = t.account_id
    WHERE a.uni_id = '{$uni_id}' AND a.deleted_at IS NULL
      AND t.type IN ('DEPOSIT','WITHDRAWAL')
    ORDER BY t.transaction_date DESC
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
        'id'               => (int) $row['id'],
        'account_id'       => (int) $row['account_id'],
        'uni_id'           => $row['uni_id'],
        'type'             => $row['type'],
        'amount'           => (float) $row['amount'],
        'balance_after'    => null,
        'broker_reference' => $row['reference'] ?? null,
        'currency'         => $row['currency'] ?? 'USD',
        'created_at'       => $row['transaction_date'] ?? null,
    ];
}

mysqli_free_result($result);
mysqli_close($conn);

echo json_encode([
    'success'      => true,
    'transactions' => $transactions,
    'total'        => $total_count,
    'count'        => count($transactions),
    'limit'        => $limit,
    'offset'       => $offset,
    'has_more'     => ($offset + count($transactions)) < $total_count,
]);
