<?php
/**
 * MEXC twin of get_binance_positions.php (the mexc_* tables are column-for-column Binance).
 * Get MEXC active positions for a user by uni_id.
 * GET: ?uni_id=xxx
 * Returns: {"success": true, "positions": [{"id", "symbol", "position_side", "position_amt", "entry_price", "mark_price", "unrealized_profit", "created_at"}, ...]}
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

$sql = "SELECT id, symbol, position_side, position_amt, entry_price, mark_price, unrealized_profit, created_at
    FROM mexc_positions
    WHERE uni_id = '{$uni_id}' AND position_amt != 0
    ORDER BY created_at DESC";

$result = mysqli_query($conn, $sql);

if (!$result) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => mysqli_error($conn)]);
    exit;
}

$positions = [];
while ($row = mysqli_fetch_assoc($result)) {
    $positions[] = [
        'id' => (int) $row['id'],
        'symbol' => $row['symbol'],
        'position_side' => $row['position_side'],
        'position_amt' => (float) $row['position_amt'],
        'entry_price' => (float) ($row['entry_price'] ?? 0),
        'mark_price' => (float) ($row['mark_price'] ?? 0),
        'unrealized_profit' => (float) ($row['unrealized_profit'] ?? 0),
        'created_at' => $row['created_at'],
    ];
}

mysqli_free_result($result);
mysqli_close($conn);

echo json_encode(['success' => true, 'positions' => $positions]);
