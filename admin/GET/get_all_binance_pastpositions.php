<?php
/**
 * Admin: Get all Binance past positions (closed trades, all users).
 * GET /admin/GET/get_all_binance_pastpositions.php
 * Returns: trades array normalized to Trade-like shape with broker "BINANCE"
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

$sql = "SELECT id, api_key, uni_id, symbol, realized_pnl, exit_price, closed_at, created_at, strategy, side
    FROM binance_pastpositions
    ORDER BY closed_at DESC";

$result = mysqli_query($conn, $sql);

if (!$result) {
    ob_end_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => mysqli_error($conn)]);
    exit;
}

$trades = [];
while ($row = mysqli_fetch_assoc($result)) {
    $closedAt = $row['closed_at'] ?? $row['created_at'] ?? '';
    $datePart = $closedAt ? substr($closedAt, 0, 10) : '';
    $timePart = $closedAt && strlen($closedAt) > 10 ? substr($closedAt, 11, 8) : '00:00:00';
    $trades[] = [
        'id' => (int) $row['id'],
        'account_id' => 0,
        'ticker' => $row['symbol'],
        'price' => (string) ($row['exit_price'] ?? '0'),
        'pnl' => (float) ($row['realized_pnl'] ?? 0),
        'pnl_percentage' => 0,
        'closed_positions' => 1,
        'date_closed' => $datePart,
        'time_closed' => $timePart,
        'created_at' => $row['created_at'] ?? $closedAt,
        'strategy'     => $row['strategy'] ?? null,
        'side'         => $row['side'] ?? null,
        'account_name' => 'Binance',
        'account_email'=> null,
        'broker'       => 'Binance',
    ];
}

mysqli_free_result($result);
mysqli_close($conn);

ob_end_clean();
echo json_encode(['success' => true, 'trades' => $trades]);
