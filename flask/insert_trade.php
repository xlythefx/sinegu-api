<?php
/**
 * Insert a completed trade
 * POST JSON: {"account_id": 1, "ticker": "BTCUSD", "pnl": 100.50, "pnl_percentage": 2.5, "closed_positions": 3, "price": 50000.00}
 * Returns JSON: {"success": true, "message": "Trade inserted"}
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
$pnl = isset($input['pnl']) ? floatval($input['pnl']) : 0.0;
$pnl_percentage = isset($input['pnl_percentage']) ? floatval($input['pnl_percentage']) : 0.0;
$closed_positions = isset($input['closed_positions']) ? intval($input['closed_positions']) : 0;
$price = isset($input['price']) ? floatval($input['price']) : 0.0;
$date_closed = isset($input['date_closed']) ? mysqli_real_escape_string($conn, $input['date_closed']) : date('Y-m-d');
$time_closed = isset($input['time_closed']) ? mysqli_real_escape_string($conn, $input['time_closed']) : date('H:i:s');
$deal_ids = isset($input['deal_ids']) && $input['deal_ids'] !== '' && $input['deal_ids'] !== null
    ? mysqli_real_escape_string($conn, $input['deal_ids'])
    : null;
$strategy = isset($input['strategy']) && $input['strategy'] !== '' && $input['strategy'] !== null
    ? mysqli_real_escape_string($conn, $input['strategy'])
    : 'SineguAlerts';

$side_sql = '';
$side_val = '';
if (isset($input['side']) && trim((string) $input['side']) !== '') {
    $side_esc = mysqli_real_escape_string($conn, strtoupper(trim($input['side'])));
    $side_sql = ', side';
    $side_val = ", '{$side_esc}'";
}

$has_deal_ids = $deal_ids !== null && $deal_ids !== '';
if ($has_deal_ids) {
    $sql = "INSERT INTO trades (account_id, ticker, pnl, pnl_percentage, closed_positions, price, date_closed, time_closed, deal_ids, strategy{$side_sql}, created_at) 
            VALUES ({$account_id}, '{$ticker}', {$pnl}, {$pnl_percentage}, {$closed_positions}, {$price}, '{$date_closed}', '{$time_closed}', '{$deal_ids}', '{$strategy}'{$side_val}, NOW())";
} else {
    $sql = "INSERT INTO trades (account_id, ticker, pnl, pnl_percentage, closed_positions, price, date_closed, time_closed, strategy{$side_sql}, created_at) 
            VALUES ({$account_id}, '{$ticker}', {$pnl}, {$pnl_percentage}, {$closed_positions}, {$price}, '{$date_closed}', '{$time_closed}', '{$strategy}'{$side_val}, NOW())";
}
$result = mysqli_query($conn, $sql);

if (!$result) {
    $errno = mysqli_errno($conn);
    if ($errno == 1062) {
        // Duplicate key: same account_id + deal_ids already inserted (idempotent)
        if (file_exists(__DIR__ . '/../includes/api_log.php')) {
            require_once __DIR__ . '/../includes/api_log.php';
            api_log($conn, 'flask/insert_trade', 'Trade already recorded (idempotent)', 'create', true);
        }
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'message' => 'Trade already recorded (idempotent)'
        ]);
        mysqli_close($conn);
        exit;
    }
    if (file_exists(__DIR__ . '/../includes/api_log.php')) {
        require_once __DIR__ . '/../includes/api_log.php';
        api_log($conn, 'flask/insert_trade', 'Insert trade failed: ' . mysqli_error($conn), 'create', false);
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
    api_log($conn, 'flask/insert_trade', 'Trade inserted successfully', 'create', true);
}
echo json_encode([
    'success' => true,
    'message' => 'Trade inserted successfully'
]);

mysqli_close($conn);
?>

