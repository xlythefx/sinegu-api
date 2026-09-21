<?php
/**
 * Upsert a single mexc_positions row right after a webhook trade fills.
 * position_amt is in COINS (holdVol x contractSize), symbol in ticker form (BTCUSDT).
 * Lets the Flask stack-guard see new positions immediately instead of waiting
 * for the next 5-minute fetch_positions snapshot.
 *
 * POST JSON:
 *   {
 *     "api_key": "...",
 *     "uni_id": "...",
 *     "symbol": "BTCUSDT",
 *     "position_side": "LONG" | "SHORT" | "BOTH",
 *     "position_amt": 0.012,          // absolute size after the fill; 0 = closed
 *     "entry_price": 67234.5,         // optional
 *     "update_time": 1700000000000    // optional (ms)
 *   }
 *
 * Behavior:
 *   - Always DELETE any existing row matching (uni_id, symbol, position_side).
 *   - If position_amt != 0, INSERT the new row.
 *   - If position_amt == 0, leave it deleted (position closed).
 *
 * Returns: { "success": true, "action": "inserted"|"deleted" }
 *          { "success": false, "error": "..." }
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
    echo json_encode(['success' => false, 'error' => 'Method not allowed. Use POST.']);
    exit;
}

require_once __DIR__ . '/../conn.php';

if (!$conn) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit;
}

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid JSON body']);
    mysqli_close($conn);
    exit;
}

$apiKey       = isset($data['api_key']) ? trim((string) $data['api_key']) : '';
$uniId        = isset($data['uni_id']) ? trim((string) $data['uni_id']) : '';
$symbol       = isset($data['symbol']) ? strtoupper(trim((string) $data['symbol'])) : '';
$positionSide = isset($data['position_side']) ? strtoupper(trim((string) $data['position_side'])) : '';
$positionAmt  = isset($data['position_amt']) ? (float) $data['position_amt'] : 0.0;
$entryPrice   = isset($data['entry_price']) && $data['entry_price'] !== '' ? (float) $data['entry_price'] : null;
$updateTime   = isset($data['update_time']) && $data['update_time'] !== '' ? (int) $data['update_time'] : null;

if ($apiKey === '' || $uniId === '' || $symbol === '' || $positionSide === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'api_key, uni_id, symbol, position_side are required']);
    mysqli_close($conn);
    exit;
}

// Always delete the existing row for this (uni_id, symbol, side).
$del = mysqli_prepare($conn, "DELETE FROM mexc_positions WHERE uni_id = ? AND symbol = ? AND position_side = ?");
if (!$del) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Prepare (delete) failed: ' . mysqli_error($conn)]);
    mysqli_close($conn);
    exit;
}
mysqli_stmt_bind_param($del, 'sss', $uniId, $symbol, $positionSide);
mysqli_stmt_execute($del);
mysqli_stmt_close($del);

if ($positionAmt == 0.0) {
    mysqli_close($conn);
    echo json_encode(['success' => true, 'action' => 'deleted']);
    exit;
}

$ins = mysqli_prepare(
    $conn,
    "INSERT INTO mexc_positions (api_key, uni_id, symbol, position_side, position_amt, entry_price, update_time)
     VALUES (?, ?, ?, ?, ?, ?, ?)"
);
if (!$ins) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Prepare (insert) failed: ' . mysqli_error($conn)]);
    mysqli_close($conn);
    exit;
}
mysqli_stmt_bind_param(
    $ins,
    'ssssddi',
    $apiKey, $uniId, $symbol, $positionSide, $positionAmt, $entryPrice, $updateTime
);
$ok = mysqli_stmt_execute($ins);
mysqli_stmt_close($ins);
mysqli_close($conn);

if (!$ok) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Insert failed']);
    exit;
}

echo json_encode(['success' => true, 'action' => 'inserted']);
