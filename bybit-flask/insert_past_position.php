<?php
/**
 * Insert a closed Bybit position. Called when EXIT_LONG, EXIT_SHORT, or EXIT executes.
 * position_amt is in COINS (Bybit linear qty already is); realized_pnl is NET of
 * commission, like the Binance table. order_id is a STRING (Bybit linear order ids are
 * UUIDs) and is stored as one — bybit_pastpositions.order_id is VARCHAR(64), migration 100.
 *
 * POST JSON: {
 *   "api_key": "...",
 *   "uni_id": "...",
 *   "symbol": "BTCUSDT",
 *   "position_side": "LONG",
 *   "position_amt": 0.002,
 *   "entry_price": 67780.10,
 *   "exit_price": 67800.00,
 *   "realized_pnl": 0.04,
 *   "side": "SELL",
 *   "order_id": "5a373bfe-188d-4913-9c81-d57ab5be8068",
 *   "closed_at": "2026-02-27 13:04:54",
 *   "strategy": "VWMA-Reversion"
 * }
 * Returns: { "success": true, "id": N } or { "success": false, "error": "..." }
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed. Use POST.']);
    exit;
}

require_once __DIR__ . '/../conn.php';

if (!$conn) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit;
}

$raw = file_get_contents('php://input');
$data = json_decode($raw, true, 512, JSON_BIGINT_AS_STRING); // ids stay exact strings
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid JSON body']);
    exit;
}

$apiKey = isset($data['api_key']) ? trim((string) $data['api_key']) : '';
$uniId = isset($data['uni_id']) ? trim((string) $data['uni_id']) : '';
$symbol = isset($data['symbol']) ? trim(strtoupper((string) $data['symbol'])) : '';
$positionSide = isset($data['position_side']) ? trim((string) $data['position_side']) : 'BOTH';
$positionAmt = isset($data['position_amt']) ? (float) $data['position_amt'] : 0;
$entryPrice = isset($data['entry_price']) ? (float) $data['entry_price'] : null;
$exitPrice = isset($data['exit_price']) ? (float) $data['exit_price'] : null;
$realizedPnl = isset($data['realized_pnl']) ? (float) $data['realized_pnl'] : null;
$side = isset($data['side']) ? trim(strtoupper((string) $data['side'])) : '';
// A STRING — never (int): a UUID would become 5 and collide on the UNIQUE key.
$orderId = isset($data['order_id']) ? substr(trim((string) $data['order_id']), 0, 64) : '';
if ($orderId === '') {
    $orderId = null;
}
$closedAt = isset($data['closed_at']) ? trim((string) $data['closed_at']) : date('Y-m-d H:i:s');
$strategy = isset($data['strategy']) ? trim((string) $data['strategy']) : '';
if ($strategy === '') {
    $strategy = null;
}

if ($apiKey === '' || $uniId === '' || $symbol === '' || $side === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing required: api_key, uni_id, symbol, side']);
    exit;
}

if (!in_array($side, ['BUY', 'SELL'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'side must be BUY or SELL']);
    exit;
}

$ins = "INSERT IGNORE INTO bybit_pastpositions (api_key, uni_id, symbol, position_side, position_amt, entry_price, exit_price, realized_pnl, side, order_id, closed_at, strategy) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
$stmt = mysqli_prepare($conn, $ins);
if (!$stmt) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Prepare failed: ' . mysqli_error($conn)]);
    mysqli_close($conn);
    exit;
}

mysqli_stmt_bind_param($stmt, 'ssssddddssss',
    $apiKey, $uniId, $symbol, $positionSide, $positionAmt,
    $entryPrice, $exitPrice, $realizedPnl, $side, $orderId, $closedAt, $strategy
);

if (!mysqli_stmt_execute($stmt)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => mysqli_stmt_error($stmt)]);
    mysqli_stmt_close($stmt);
    mysqli_close($conn);
    exit;
}

$affected = mysqli_affected_rows($conn);
$id = (int) mysqli_insert_id($conn);
mysqli_stmt_close($stmt);
mysqli_close($conn);

if ($affected <= 0) {
    echo json_encode(['success' => true, 'id' => 0, 'skipped' => true]);
    exit;
}

echo json_encode(['success' => true, 'id' => $id]);
