<?php
/**
 * Insert a new position
 * POST JSON: {
 *   "account_id": 1, "ticker": "BTCUSD",
 *   "price": 50000.00, "unrealized_pnl": -5.50,
 *   "deal_id": "006011e7-0001-54c4-0000-000080560043",
 *   "side": "BUY",
 *   "strategy": "trend_momentum"
 * }
 * Optional deal_id: Capital.com permanent deal id -> positions.deal_id (NULL if omitted).
 * Optional side: BUY or SELL (opening direction).
 * Optional strategy: webhook strategy label.
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
    echo json_encode([
        'success' => false,
        'error' => 'Method not allowed. Use POST.'
    ]);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

if (!$input || !isset($input['account_id']) || !isset($input['ticker'])) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Missing required fields: account_id and ticker'
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

$account_id = intval($input['account_id']);
$ticker = mysqli_real_escape_string($conn, strtoupper(trim($input['ticker'])));
$price = isset($input['price']) ? floatval($input['price']) : 0.0;
$unrealized_pnl = isset($input['unrealized_pnl']) ? floatval($input['unrealized_pnl']) : 0.0;

$deal_id_sql = 'NULL';
if (isset($input['deal_id']) && $input['deal_id'] !== '' && $input['deal_id'] !== null) {
    $deal_id_esc = mysqli_real_escape_string($conn, trim((string) $input['deal_id']));
    if ($deal_id_esc !== '') {
        $deal_id_sql = "'{$deal_id_esc}'";
    }
}

$side_col = '';
$side_val = '';
if (isset($input['side']) && trim((string) $input['side']) !== '') {
    $side_esc = mysqli_real_escape_string($conn, strtoupper(trim($input['side'])));
    $side_col = ', side';
    $side_val = ", '{$side_esc}'";
}

$strategy_col = '';
$strategy_val = '';
if (isset($input['strategy']) && trim((string) $input['strategy']) !== '') {
    $strategy_esc = mysqli_real_escape_string($conn, trim($input['strategy']));
    $strategy_col = ', strategy';
    $strategy_val = ", '{$strategy_esc}'";
}

$sql = "INSERT INTO positions (account_id, ticker, deal_id, increments, price, unrealized_pnl{$side_col}{$strategy_col}, updated_at) VALUES ({$account_id}, '{$ticker}', {$deal_id_sql}, 1, {$price}, {$unrealized_pnl}{$side_val}{$strategy_val}, NOW())";
$result = mysqli_query($conn, $sql);

if (!$result) {
    if (file_exists(__DIR__ . '/../includes/api_log.php')) {
        require_once __DIR__ . '/../includes/api_log.php';
        api_log($conn, 'flask/insert_position', 'Insert position failed', 'create', false);
    }
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => mysqli_error($conn)
    ]);
    exit;
}

if (file_exists(__DIR__ . '/../includes/api_log.php')) {
    require_once __DIR__ . '/../includes/api_log.php';
    api_log($conn, 'flask/insert_position', 'Position inserted successfully', 'create', true);
}
echo json_encode([
    'success' => true,
    'message' => 'Position inserted successfully'
]);

mysqli_close($conn);
?>

