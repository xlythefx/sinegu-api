<?php
/**
 * Insert a new IG.com position after a successful trade open.
 * POST JSON: {
 *   "account_id": 1, "ticker": "EURUSD", "epic": "CS.D.EURUSD.CFD.IP",
 *   "price": 1.09, "unrealized_pnl": 0, "deal_id": "...",
 *   "direction": "BUY", "size": 1, "currency": "USD", "strategy": "Volume_Momentum"
 * }
 * Returns JSON: {"success": true, "message": "Position inserted"}
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

if (!$input || !isset($input['account_id']) || !isset($input['api_key']) || !isset($input['ticker'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing required fields: account_id, api_key and ticker']);
    exit;
}

require_once __DIR__ . '/../conn.php';

if (!$conn) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit;
}

$account_id = intval($input['account_id']);
$api_key = mysqli_real_escape_string($conn, trim((string) $input['api_key']));
$ticker = mysqli_real_escape_string($conn, strtoupper(trim($input['ticker'])));
$epic = isset($input['epic']) ? mysqli_real_escape_string($conn, trim($input['epic'])) : '';
$level = isset($input['price']) ? floatval($input['price']) : (isset($input['level']) ? floatval($input['level']) : 0.0);
$unrealized_pnl = isset($input['unrealized_pnl']) ? floatval($input['unrealized_pnl']) : 0.0;

$deal_id_sql = 'NULL';
if (isset($input['deal_id']) && $input['deal_id'] !== '' && $input['deal_id'] !== null) {
    $deal_id_esc = mysqli_real_escape_string($conn, trim((string) $input['deal_id']));
    if ($deal_id_esc !== '') {
        $deal_id_sql = "'{$deal_id_esc}'";
    }
}

$direction = isset($input['direction']) ? strtoupper(trim((string) $input['direction'])) : null;
$direction_sql = $direction ? "'" . mysqli_real_escape_string($conn, $direction) . "'" : 'NULL';

$size = isset($input['size']) ? floatval($input['size']) : null;
$size_sql = $size !== null ? $size : 'NULL';

$currency_sql = 'NULL';
if (isset($input['currency']) && trim((string) $input['currency']) !== '') {
    $currency_sql = "'" . mysqli_real_escape_string($conn, trim($input['currency'])) . "'";
}

$strategy_sql = 'NULL';
if (isset($input['strategy']) && trim((string) $input['strategy']) !== '') {
    $strategy_sql = "'" . mysqli_real_escape_string($conn, trim($input['strategy'])) . "'";
}

$sql = "INSERT INTO ig_positions (account_id, api_key, epic, ticker, deal_id, direction, size, level, unrealized_pnl, currency, strategy, updated_at)
        VALUES ({$account_id}, '{$api_key}', '{$epic}', '{$ticker}', {$deal_id_sql}, {$direction_sql}, {$size_sql}, {$level}, {$unrealized_pnl}, {$currency_sql}, {$strategy_sql}, NOW())";
$result = mysqli_query($conn, $sql);

if (!$result) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => mysqli_error($conn)]);
    exit;
}

echo json_encode(['success' => true, 'message' => 'IG position inserted successfully']);

mysqli_close($conn);
