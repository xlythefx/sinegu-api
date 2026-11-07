<?php
/**
 * Bybit twin of get_binance_pastpositions.php (the bybit_* tables are column-for-column Binance).
 * Get Bybit past positions (closed trades) for a user by uni_id.
 * Used for asset performance analytics on Bybit dashboard.
 * GET: ?uni_id=xxx
 * Returns: {"success": true, "positions": [{"symbol", "realized_pnl", "closed_at"}, ...]}
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed. Use GET.']);
    exit;
}

require_once __DIR__ . '/../conn.php';

if (!$conn) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit;
}

$uni_id = isset($_GET['uni_id']) ? trim($_GET['uni_id']) : null;
if (!$uni_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing required parameter: uni_id']);
    exit;
}

$uni_id = mysqli_real_escape_string($conn, $uni_id);

$sql = "SELECT symbol, COALESCE(realized_pnl, 0) AS realized_pnl, COALESCE(exit_price, 0) AS exit_price, position_amt, entry_price, closed_at, strategy
    FROM bybit_pastpositions
    WHERE uni_id = '{$uni_id}'
    ORDER BY closed_at ASC";

$result = mysqli_query($conn, $sql);

if (!$result) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => mysqli_error($conn)]);
    exit;
}

$positions = [];
while ($row = mysqli_fetch_assoc($result)) {
    $positions[] = [
        'symbol' => $row['symbol'],
        'realized_pnl' => (float) $row['realized_pnl'],
        'exit_price' => (float) $row['exit_price'],
        // Size closed and its entry; entry_price is NULL/0 on rows the poller backfilled.
        'size' => $row['position_amt'] === null ? null : abs((float) $row['position_amt']),
        'entry_price' => (float) $row['entry_price'] > 0 ? (float) $row['entry_price'] : null,
        'closed_at' => $row['closed_at'],
        'strategy' => isset($row['strategy']) ? $row['strategy'] : null,
    ];
}

mysqli_free_result($result);
mysqli_close($conn);

echo json_encode(['success' => true, 'positions' => $positions]);
