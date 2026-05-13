<?php
/**
 * Get IG.com past positions (closed trades) for a user by uni_id.
 * Mirrors get_binance_pastpositions.php.
 * GET: ?uni_id=xxx
 * Returns: {"success": true, "positions": [{"symbol", "realized_pnl", "closed_at", "strategy"}, ...]}
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

$sql = "SELECT p.ticker AS symbol, COALESCE(p.pnl, 0) AS realized_pnl, p.closed_at, p.strategy
    FROM ig_past_positions p
    JOIN ig_accounts a ON a.id = p.account_id
    WHERE a.uni_id = '{$uni_id}' AND a.deleted_at IS NULL
    ORDER BY p.closed_at ASC";

$result = mysqli_query($conn, $sql);

if (!$result) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => mysqli_error($conn)]);
    exit;
}

$positions = [];
while ($row = mysqli_fetch_assoc($result)) {
    $positions[] = [
        'symbol'       => $row['symbol'],
        'realized_pnl' => (float) $row['realized_pnl'],
        'closed_at'    => $row['closed_at'],
        'strategy'     => $row['strategy'] ?? null,
    ];
}

mysqli_free_result($result);
mysqli_close($conn);

echo json_encode(['success' => true, 'positions' => $positions]);
