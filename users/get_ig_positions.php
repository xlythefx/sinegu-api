<?php
/**
 * Get IG.com open positions for a user by uni_id (mirrors get_binance_positions.php).
 * GET: ?uni_id=xxx
 * Returns: {"success": true, "positions": [...]}
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

$sql = "SELECT pos.id, pos.account_id, pos.ticker, pos.epic, pos.deal_id, pos.direction, pos.size,
               pos.level, pos.unrealized_pnl, pos.currency, pos.strategy, pos.created_at, pos.updated_at
    FROM ig_positions pos
    JOIN ig_accounts a ON a.id = pos.account_id
    WHERE a.uni_id = '{$uni_id}' AND a.deleted_at IS NULL
    ORDER BY pos.updated_at DESC";

$result = mysqli_query($conn, $sql);

if (!$result) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => mysqli_error($conn)]);
    exit;
}

$positions = [];
while ($row = mysqli_fetch_assoc($result)) {
    $positions[] = [
        'id'            => (int) $row['id'],
        'account_id'    => (int) $row['account_id'],
        'ticker'        => $row['ticker'],
        'epic'          => $row['epic'] ?? null,
        'deal_id'       => $row['deal_id'] ?? null,
        'direction'     => $row['direction'] ?? null,
        'size'          => (float) ($row['size'] ?? 0),
        'level'         => (float) ($row['level'] ?? 0),
        'unrealized_pnl'=> (float) ($row['unrealized_pnl'] ?? 0),
        'currency'      => $row['currency'] ?? 'USD',
        'strategy'      => $row['strategy'] ?? null,
        'created_at'    => $row['created_at'],
        'updated_at'    => $row['updated_at'] ?? $row['created_at'],
    ];
}

mysqli_free_result($result);
mysqli_close($conn);

echo json_encode(['success' => true, 'positions' => $positions]);
