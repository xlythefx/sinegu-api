<?php
/**
 * Insert a completed trade
 * POST JSON: {"account_id": 1, "ticker": "BTCUSD", "pnl": 100.50, "pnl_percentage": 2.5, "closed_positions": 3, "price": 50000.00}
 * Optional side: BUY/LONG or SELL/SHORT — see normalize_trade_side() below.
 * Returns JSON: {"success": true, "message": "Trade inserted"}
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
    ob_clean();
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'error' => 'Method not allowed. Use POST.'
    ]);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

if (!$input || !isset($input['account_id']) || !isset($input['ticker'])) {
    ob_clean();
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Missing required fields: account_id and ticker'
    ]);
    exit;
}

require_once '../conn.php';

if (!$conn) {
    ob_clean();
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

/**
 * `trades`.`side` is ENUM('BUY','SELL'), but the Capital Flask exit path reports the
 * direction it closed as LONG/SHORT (`trade_side` in trading_handler.py). Under
 * STRICT_TRANS_TABLES an out-of-range ENUM value raises error 1265 and aborts the
 * whole INSERT — so an unmapped side used to cost the entire trade row, not just the
 * side column. (Pre-cutover, non-strict MySQL silently stored '' instead; that is
 * where the historical blank-side rows come from.)
 *
 * Closing a LONG means the position was a BUY and closing a SHORT means it was a
 * SELL, so this mapping is lossless. Anything unrecognised returns null and is
 * omitted from the INSERT, leaving side NULL rather than losing the trade.
 */
function normalize_trade_side($raw) {
    switch (strtoupper(trim((string) $raw))) {
        case 'BUY':
        case 'LONG':
            return 'BUY';
        case 'SELL':
        case 'SHORT':
            return 'SELL';
        default:
            return null;
    }
}

$side_sql = '';
$side_val = '';
if (isset($input['side']) && trim((string) $input['side']) !== '') {
    $side_norm = normalize_trade_side($input['side']);
    if ($side_norm !== null) {
        // Always one of two literals, so there is nothing left to escape.
        $side_sql = ', side';
        $side_val = ", '{$side_norm}'";
    }
}

$has_deal_ids = $deal_ids !== null && $deal_ids !== '';
if ($has_deal_ids) {
    $sql = "INSERT INTO trades (account_id, ticker, pnl, pnl_percentage, closed_positions, price, date_closed, time_closed, deal_ids, strategy{$side_sql}, created_at) 
            VALUES ({$account_id}, '{$ticker}', {$pnl}, {$pnl_percentage}, {$closed_positions}, {$price}, '{$date_closed}', '{$time_closed}', '{$deal_ids}', '{$strategy}'{$side_val}, NOW())";
} else {
    $sql = "INSERT INTO trades (account_id, ticker, pnl, pnl_percentage, closed_positions, price, date_closed, time_closed, strategy{$side_sql}, created_at) 
            VALUES ({$account_id}, '{$ticker}', {$pnl}, {$pnl_percentage}, {$closed_positions}, {$price}, '{$date_closed}', '{$time_closed}', '{$strategy}'{$side_val}, NOW())";
}
// PHP 8.1+ mysqli throws instead of returning false, so without this catch the
// `if (!$result)` handling below never ran — including the 1062 idempotency branch.
try {
    $result = mysqli_query($conn, $sql);
    $errno = $result ? 0 : mysqli_errno($conn);
    $errmsg = $result ? '' : mysqli_error($conn);
} catch (\mysqli_sql_exception $e) {
    $result = false;
    $errno = $e->getCode();
    $errmsg = $e->getMessage();
}

if (!$result) {
    if ($errno == 1062) {
        // Duplicate key: same account_id + deal_ids already inserted (idempotent)
        if (file_exists(__DIR__ . '/../includes/api_log.php')) {
            require_once __DIR__ . '/../includes/api_log.php';
            api_log($conn, 'flask/insert_trade', 'Trade already recorded (idempotent)', 'create', true);
        }
        ob_clean();
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
        api_log($conn, 'flask/insert_trade', 'Insert trade failed: ' . $errmsg, 'create', false);
    }
    ob_clean();
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $errmsg
    ]);
    exit;
}

if (file_exists(__DIR__ . '/../includes/api_log.php')) {
    require_once __DIR__ . '/../includes/api_log.php';
    api_log($conn, 'flask/insert_trade', 'Trade inserted successfully', 'create', true);
}
ob_clean();
echo json_encode([
    'success' => true,
    'message' => 'Trade inserted successfully'
]);

mysqli_close($conn);
?>

