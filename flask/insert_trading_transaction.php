<?php
/**
 * Insert a trading transaction (Entry/Exit) into capital_trades_transactions table
 * POST JSON: {
 *   "account_id": 28,
 *   "ticker": "BTCUSD",
 *   "type": "entry",
 *   "price": 50000.00,
 *   "size": 0.001,
 *   "increment": 1
 * }
 * OR for Exit:
 * {
 *   "account_id": 28,
 *   "ticker": "BTCUSD",
 *   "type": "exit",
 *   "price": 51000.00,
 *   "pnl": 10.00
 * }
 * Returns JSON: {"success": true, "message": "Transaction inserted", "transaction_id": 123}
 */

// Never leak HTML error pages into the JSON body (PHP 8 mysqli throws on SQL errors).
ini_set('display_errors', '0');
error_reporting(E_ALL);
ob_start();

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
    echo json_encode([
        'success' => false,
        'error' => 'Method not allowed. Use POST.'
    ]);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Invalid JSON input'
    ]);
    exit;
}

require_once '../conn.php';

if (!$conn) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Database connection failed'
    ]);
    exit;
}

// Validate required fields
if (!isset($input['account_id']) || !isset($input['ticker']) || !isset($input['type']) || !isset($input['price'])) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Missing required fields: account_id, ticker, type, and price'
    ]);
    exit;
}

$account_id = intval($input['account_id']);
$ticker = mysqli_real_escape_string($conn, strtoupper(trim($input['ticker'])));
$type = mysqli_real_escape_string($conn, strtolower(trim($input['type'])));
$price = floatval($input['price']);

// Validate type
if ($type !== 'entry' && $type !== 'exit') {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Invalid type. Must be "entry" or "exit"'
    ]);
    exit;
}

$pnl = isset($input['pnl']) ? floatval($input['pnl']) : null;
$size = isset($input['size']) ? floatval($input['size']) : null;
$increment = isset($input['increment']) ? intval($input['increment']) : null;

// Build SQL query
$fields = ['account_id', 'ticker', 'type', 'price'];
$values = [$account_id, "'{$ticker}'", "'{$type}'", $price];

if ($pnl !== null) {
    $fields[] = 'pnl';
    $values[] = $pnl;
}

if ($size !== null) {
    $fields[] = 'size';
    $values[] = $size;
}

if ($increment !== null) {
    $fields[] = 'increment';
    $values[] = $increment;
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

$sql = "INSERT INTO capital_trades_transactions ({$fields_str}, created_at) VALUES ({$values_str}, NOW())";

try {
    $result = mysqli_query($conn, $sql);
} catch (\mysqli_sql_exception $e) {
    ob_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    mysqli_close($conn);
    exit;
}

if (!$result) {
    ob_clean();
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => mysqli_error($conn)
    ]);
    exit;
}

$transaction_id = mysqli_insert_id($conn);

ob_clean();
echo json_encode([
    'success' => true,
    'message' => 'Trading transaction inserted successfully',
    'transaction_id' => $transaction_id
]);

mysqli_close($conn);

