<?php
/**
 * Insert closed IG.com position into ig_past_positions.
 * POST JSON: {
 *   "account_id": 1,
 *   "ticker": "EURUSD",
 *   "epic": "CS.D.EURUSD.CFD.IP",
 *   "deal_id": "DIAAA...",
 *   "side": "BUY",
 *   "size": 1,
 *   "open_price": 1.0923,
 *   "close_price": 1.0941,
 *   "pnl": 18.2,
 *   "currency": "USD",
 *   "opened_at": "2026-04-24 09:00:00",
 *   "closed_at": "2026-04-24 10:03:00",
 *   "close_deal_reference": "ABC123",
 *   "strategy": "SineguAlerts"
 * }
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
$ticker = mysqli_real_escape_string($conn, strtoupper(trim((string) $input['ticker'])));

$nullableString = function ($value) use ($conn) {
    if (!isset($value)) {
        return 'NULL';
    }
    $s = trim((string) $value);
    if ($s === '') {
        return 'NULL';
    }
    return "'" . mysqli_real_escape_string($conn, $s) . "'";
};

$nullableFloat = function ($value) {
    if (!isset($value) || $value === '') {
        return 'NULL';
    }
    return (string) floatval($value);
};

$epic_sql = $nullableString($input['epic'] ?? null);
$deal_id_sql = $nullableString($input['deal_id'] ?? null);
$side_val = isset($input['side']) ? strtoupper(trim((string) $input['side'])) : null;
if ($side_val !== null && $side_val !== '' && $side_val !== 'BUY' && $side_val !== 'SELL') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid side. Must be BUY or SELL']);
    exit;
}
$side_sql = $nullableString($side_val);
$size_sql = $nullableFloat($input['size'] ?? null);
$open_price_sql = $nullableFloat($input['open_price'] ?? null);
$close_price_sql = $nullableFloat($input['close_price'] ?? null);
$pnl_sql = $nullableFloat($input['pnl'] ?? null);
$currency_sql = $nullableString($input['currency'] ?? null);
$opened_at_sql = $nullableString($input['opened_at'] ?? null);
$closed_at_sql = $nullableString($input['closed_at'] ?? null);
$close_ref_sql = $nullableString($input['close_deal_reference'] ?? null);
$strategy_sql = $nullableString($input['strategy'] ?? null);

// Upsert keyed by UNIQUE(account_id, close_deal_reference). On duplicate, only
// fill columns that are still NULL — never overwrite a value already set by an
// earlier write. The exit handler inserts a stub (pnl/close_price NULL) and the
// fetch_past_positions.py reconciler later fills realized values; this ordering
// works either way.
$sql = "INSERT INTO ig_past_positions
        (account_id, api_key, ticker, epic, deal_id, side, size, open_price, close_price, pnl, currency, opened_at, closed_at, close_deal_reference, strategy, created_at)
        VALUES
        ({$account_id}, '{$api_key}', '{$ticker}', {$epic_sql}, {$deal_id_sql}, {$side_sql}, {$size_sql}, {$open_price_sql}, {$close_price_sql}, {$pnl_sql}, {$currency_sql}, {$opened_at_sql}, COALESCE({$closed_at_sql}, NOW()), {$close_ref_sql}, {$strategy_sql}, NOW())
        ON DUPLICATE KEY UPDATE
            epic         = COALESCE(epic,         VALUES(epic)),
            deal_id      = COALESCE(deal_id,      VALUES(deal_id)),
            side         = COALESCE(side,         VALUES(side)),
            size         = COALESCE(size,         VALUES(size)),
            open_price   = COALESCE(open_price,   VALUES(open_price)),
            close_price  = COALESCE(close_price,  VALUES(close_price)),
            pnl          = COALESCE(pnl,          VALUES(pnl)),
            currency     = COALESCE(currency,     VALUES(currency)),
            opened_at    = COALESCE(opened_at,    VALUES(opened_at)),
            closed_at    = COALESCE(closed_at,    VALUES(closed_at)),
            strategy     = COALESCE(strategy,     VALUES(strategy))";

$result = mysqli_query($conn, $sql);
if (!$result) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => mysqli_error($conn)]);
    exit;
}

echo json_encode([
    'success' => true,
    'message' => 'IG past position inserted successfully',
    'id' => mysqli_insert_id($conn),
]);

mysqli_close($conn);
