<?php
/**
 * Batch upsert closed Binance positions discovered by the background syncer
 * (binance-flask src/fetch_past_positions.py). Idempotent on
 * (api_key, symbol, order_id) — relies on UNIQUE key from migration 077.
 *
 * POST JSON: {
 *   "accounts": [
 *     {
 *       "api_key": "...",
 *       "uni_id": "...",
 *       "rows": [
 *         {
 *           "symbol": "BTCUSDT",
 *           "position_side": "LONG",
 *           "position_amt": 0.05,
 *           "entry_price": 76200.0,
 *           "exit_price": 76888.3,
 *           "realized_pnl": 34.2,
 *           "side": "SELL",
 *           "order_id": 12148139831,
 *           "closed_at": "2026-05-24 11:30:06",
 *           "strategy": "VWAP-Deviation"
 *         }
 *       ]
 *     }
 *   ]
 * }
 * Returns: { "success": true, "inserted": N, "skipped": M } or { "success": false, "error": "..." }
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
$data = json_decode($raw, true);
if (!is_array($data) || !isset($data['accounts']) || !is_array($data['accounts'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid body. Expected { "accounts": [ { "api_key", "uni_id", "rows": [...] } ] }']);
    exit;
}

$inserted = 0;
$skipped = 0;
$ins = "INSERT IGNORE INTO binance_pastpositions (api_key, uni_id, symbol, position_side, position_amt, entry_price, exit_price, realized_pnl, side, order_id, closed_at, strategy) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
$stmt = mysqli_prepare($conn, $ins);
if (!$stmt) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Prepare failed: ' . mysqli_error($conn)]);
    mysqli_close($conn);
    exit;
}

foreach ($data['accounts'] as $acc) {
    $apiKey = isset($acc['api_key']) ? trim((string) $acc['api_key']) : '';
    $uniId = isset($acc['uni_id']) ? trim((string) $acc['uni_id']) : '';
    $rows = isset($acc['rows']) && is_array($acc['rows']) ? $acc['rows'] : [];

    if ($apiKey === '' || $uniId === '') {
        continue;
    }

    foreach ($rows as $r) {
        $symbol = isset($r['symbol']) ? trim(strtoupper((string) $r['symbol'])) : '';
        $positionSide = isset($r['position_side']) ? trim((string) $r['position_side']) : 'BOTH';
        $positionAmt = isset($r['position_amt']) ? (float) $r['position_amt'] : 0;
        $entryPrice = isset($r['entry_price']) ? (float) $r['entry_price'] : null;
        $exitPrice = isset($r['exit_price']) ? (float) $r['exit_price'] : null;
        $realizedPnl = isset($r['realized_pnl']) ? (float) $r['realized_pnl'] : null;
        $side = isset($r['side']) ? trim(strtoupper((string) $r['side'])) : '';
        $orderId = isset($r['order_id']) ? (int) $r['order_id'] : null;
        $closedAt = isset($r['closed_at']) && trim((string) $r['closed_at']) !== ''
            ? trim((string) $r['closed_at'])
            : date('Y-m-d H:i:s');
        $strategy = isset($r['strategy']) ? trim((string) $r['strategy']) : '';
        if ($strategy === '') {
            $strategy = null;
        }

        if ($symbol === '' || $orderId === null || !in_array($side, ['BUY', 'SELL'])) {
            $skipped++;
            continue;
        }

        mysqli_stmt_bind_param($stmt, 'ssssddddsiss',
            $apiKey, $uniId, $symbol, $positionSide, $positionAmt,
            $entryPrice, $exitPrice, $realizedPnl, $side, $orderId, $closedAt, $strategy
        );
        if (mysqli_stmt_execute($stmt)) {
            if (mysqli_affected_rows($conn) > 0) {
                $inserted++;
            } else {
                $skipped++;
            }
        } else {
            $skipped++;
        }
    }
}

mysqli_stmt_close($stmt);
mysqli_close($conn);

echo json_encode(['success' => true, 'inserted' => $inserted, 'skipped' => $skipped]);
