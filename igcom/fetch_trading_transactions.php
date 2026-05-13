<?php
/**
 * Fetch IG trading transactions from ig_trades_transactions table.
 * GET parameters: account_id, uni_id, ticker, type (entry/exit), limit, offset, order_by, order_dir
 * Returns JSON: {"success": true, "transactions": [...], "total": N, "limit": N, "offset": N}
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../conn.php';

if (!$conn) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit;
}

$account_id = isset($_GET['account_id']) ? intval($_GET['account_id']) : null;
$uni_id = isset($_GET['uni_id']) ? mysqli_real_escape_string($conn, trim($_GET['uni_id'])) : null;
$ticker = isset($_GET['ticker']) ? mysqli_real_escape_string($conn, strtoupper(trim($_GET['ticker']))) : null;
$type = isset($_GET['type']) ? mysqli_real_escape_string($conn, strtolower(trim($_GET['type']))) : null;
$limit = isset($_GET['limit']) ? intval($_GET['limit']) : 100;
$offset = isset($_GET['offset']) ? intval($_GET['offset']) : 0;
$order_by = isset($_GET['order_by']) ? mysqli_real_escape_string($conn, $_GET['order_by']) : 'created_at';
$order_dir = isset($_GET['order_dir']) ? strtoupper(mysqli_real_escape_string($conn, $_GET['order_dir'])) : 'DESC';

if ($order_dir !== 'ASC' && $order_dir !== 'DESC') {
    $order_dir = 'DESC';
}
$allowed_order_fields = ['id', 'account_id', 'ticker', 'type', 'price', 'pnl', 'size', 'increment', 'created_at'];
if (!in_array($order_by, $allowed_order_fields)) {
    $order_by = 'created_at';
}

$where_conditions = [];
$join_type = 'LEFT';

if ($uni_id) {
    $join_type = 'INNER';
    $where_conditions[] = "a.uni_id = '{$uni_id}'";
}
if ($account_id !== null) {
    $where_conditions[] = "ig_trades_transactions.account_id = {$account_id}";
}
if ($ticker) {
    $where_conditions[] = "ig_trades_transactions.ticker = '{$ticker}'";
}
if ($type && ($type === 'entry' || $type === 'exit')) {
    $where_conditions[] = "ig_trades_transactions.type = '{$type}'";
}

$join_clause = "{$join_type} JOIN ig_accounts a ON ig_trades_transactions.account_id = a.id";
$where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

$count_sql = "SELECT COUNT(*) as total FROM ig_trades_transactions {$join_clause} {$where_clause}";
$count_result = mysqli_query($conn, $count_sql);
$total = $count_result ? intval(mysqli_fetch_assoc($count_result)['total']) : 0;

$sql = "SELECT ig_trades_transactions.id, ig_trades_transactions.account_id, ig_trades_transactions.ticker,
        ig_trades_transactions.type, ig_trades_transactions.price, ig_trades_transactions.pnl,
        ig_trades_transactions.size, ig_trades_transactions.increment, ig_trades_transactions.created_at,
        COALESCE(a.currency_type, 'USD') as currency_type
        FROM ig_trades_transactions
        {$join_clause}
        {$where_clause}
        ORDER BY ig_trades_transactions.{$order_by} {$order_dir}
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
        'id' => intval($row['id']),
        'account_id' => intval($row['account_id']),
        'ticker' => $row['ticker'],
        'type' => $row['type'],
        'price' => floatval($row['price']),
        'pnl' => $row['pnl'] !== null ? floatval($row['pnl']) : null,
        'size' => $row['size'] !== null ? floatval($row['size']) : null,
        'increment' => $row['increment'] !== null ? intval($row['increment']) : null,
        'created_at' => $row['created_at'],
        'currency_type' => $row['currency_type'] ?? 'USD',
    ];
}

echo json_encode([
    'success' => true,
    'transactions' => $transactions,
    'total' => $total,
    'limit' => $limit,
    'offset' => $offset,
]);

mysqli_close($conn);
