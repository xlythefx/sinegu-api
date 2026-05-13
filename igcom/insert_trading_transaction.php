<?php
/**
 * Insert an IG.com trading transaction (Entry/Exit) into ig_trades_transactions.
 * POST JSON: {
 *   "account_id": 1, "ticker": "EURUSD", "type": "entry",
 *   "price": 1.09, "size": 1, "increment": 1, "side": "BUY",
 *   "deal_id": "...", "strategy": "Volume_Momentum"
 * }
 * Returns JSON: {"success": true, "message": "Transaction inserted", "transaction_id": 123}
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed. Use POST.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid JSON input']);
    exit;
}

require_once __DIR__ . '/../conn.php';

if (!$conn) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit;
}

if (!isset($input['account_id']) || !isset($input['ticker']) || !isset($input['type']) || !isset($input['price'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing required fields: account_id, ticker, type, and price']);
    exit;
}

$account_id = intval($input['account_id']);
$ticker = mysqli_real_escape_string($conn, strtoupper(trim($input['ticker'])));
$type = mysqli_real_escape_string($conn, strtolower(trim($input['type'])));
$price = floatval($input['price']);

if ($type !== 'entry' && $type !== 'exit') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid type. Must be "entry" or "exit"']);
    exit;
}

$fields = ['account_id', 'ticker', 'type', 'price'];
$values = [$account_id, "'{$ticker}'", "'{$type}'", $price];

if (isset($input['pnl'])) {
    $fields[] = 'pnl';
    $values[] = floatval($input['pnl']);
}
if (isset($input['size'])) {
    $fields[] = 'size';
    $values[] = floatval($input['size']);
}
if (isset($input['increment'])) {
    $fields[] = 'increment';
    $values[] = intval($input['increment']);
}
if (isset($input['side']) && trim((string) $input['side']) !== '') {
    $fields[] = 'side';
    $values[] = "'" . mysqli_real_escape_string($conn, strtoupper(trim($input['side']))) . "'";
}
if (isset($input['deal_id']) && trim((string) $input['deal_id']) !== '') {
    $fields[] = 'deal_id';
    $values[] = "'" . mysqli_real_escape_string($conn, trim($input['deal_id'])) . "'";
}
if (isset($input['unrealized_pnl'])) {
    $fields[] = 'unrealized_pnl';
    $values[] = floatval($input['unrealized_pnl']);
}
if (isset($input['strategy']) && trim((string) $input['strategy']) !== '') {
    $fields[] = 'strategy';
    $values[] = "'" . mysqli_real_escape_string($conn, trim($input['strategy'])) . "'";
}

$fields_str = implode(', ', $fields);
$values_str = implode(', ', $values);

$sql = "INSERT INTO ig_trades_transactions ({$fields_str}, created_at) VALUES ({$values_str}, NOW())";
$result = mysqli_query($conn, $sql);

if (!$result) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => mysqli_error($conn)]);
    exit;
}

echo json_encode([
    'success' => true,
    'message' => 'IG trading transaction inserted successfully',
    'transaction_id' => mysqli_insert_id($conn)
]);

mysqli_close($conn);
