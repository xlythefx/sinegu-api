<?php
/**
 * Bybit twin of get_all_binance_positions.php (the bybit_* tables are column-for-column Binance).
 * Admin: Get all Bybit active positions (all users).
 * GET /admin/GET/get_all_bybit_positions.php
 * Returns: positions array normalized to Position-like shape with broker "Bybit"
 */

ob_start();

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    ob_end_clean();
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

require_once __DIR__ . '/../../conn.php';

if (!$conn) {
    ob_end_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit;
}

$sql = "SELECT id, api_key, uni_id, symbol, position_side, position_amt, entry_price, mark_price, unrealized_profit, created_at
    FROM bybit_positions
    WHERE position_amt != 0
    ORDER BY created_at DESC";

$result = mysqli_query($conn, $sql);

if (!$result) {
    ob_end_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => mysqli_error($conn)]);
    exit;
}

$positions = [];
while ($row = mysqli_fetch_assoc($result)) {
    $price = isset($row['mark_price']) && $row['mark_price'] !== null ? $row['mark_price'] : ($row['entry_price'] ?? 0);
    $positions[] = [
        'id'             => (int) $row['id'],
        'account_id'     => 0,
        'ticker'         => $row['symbol'],
        'price'          => (string) $price,
        'increments'     => (string) ($row['position_amt'] ?? ''),
        'updated_at'     => $row['created_at'] ?? '',
        'account_name'   => 'Bybit',
        'account_email'  => null,
        'unrealized_pnl' => isset($row['unrealized_profit']) ? (float) $row['unrealized_profit'] : 0,
        'side'           => $row['position_side'] ?? null,
        'strategy'       => null,
        'broker'         => 'Bybit',
    ];
}

mysqli_free_result($result);
mysqli_close($conn);

ob_end_clean();
echo json_encode(['success' => true, 'positions' => $positions]);
