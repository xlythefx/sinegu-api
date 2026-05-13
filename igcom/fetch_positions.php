<?php
/**
 * Fetch IG open positions from ig_positions.
 *
 * GET (no query params):
 *   Returns aggregates for in-memory state: {"success": true, "positions": [{"account_id", "ticker", "position_count"}, ...]}
 *
 * GET ?account_id=1&ticker=EURUSD:
 *   Returns full rows for exit handler: {"success": true, "rows": [...]}
 */

ob_start();

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

$method = $_SERVER['REQUEST_METHOD'] ?? '';
if ($method === 'OPTIONS') {
    ob_end_clean();
    http_response_code(200);
    exit;
}
if ($method !== 'GET') {
    ob_end_clean();
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

require_once __DIR__ . '/../conn.php';

if (!$conn) {
    ob_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit;
}

$account_id = isset($_GET['account_id']) ? (int) $_GET['account_id'] : 0;
$ticker = isset($_GET['ticker']) ? strtoupper(trim((string) $_GET['ticker'])) : '';
$epic = isset($_GET['epic']) ? trim((string) $_GET['epic']) : '';

// Prefer epic-based lookup (unambiguous); fall back to ticker for backward compat.
if ($account_id > 0 && ($epic !== '' || $ticker !== '')) {
    if ($epic !== '') {
        $stmt = mysqli_prepare(
            $conn,
            'SELECT id, account_id, api_key, epic, ticker, deal_id, direction, size, level, stop_level, limit_level,
                    unrealized_pnl, currency, strategy, created_at, updated_at
             FROM ig_positions
             WHERE account_id = ? AND epic = ?
             ORDER BY id ASC'
        );
        $bind_param = $epic;
    } else {
        $stmt = mysqli_prepare(
            $conn,
            'SELECT id, account_id, api_key, epic, ticker, deal_id, direction, size, level, stop_level, limit_level,
                    unrealized_pnl, currency, strategy, created_at, updated_at
             FROM ig_positions
             WHERE account_id = ? AND ticker = ?
             ORDER BY id ASC'
        );
        $bind_param = $ticker;
    }
    if (!$stmt) {
        ob_clean();
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => mysqli_error($conn)]);
        mysqli_close($conn);
        exit;
    }
    mysqli_stmt_bind_param($stmt, 'is', $account_id, $bind_param);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $rows = [];
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            $rows[] = $row;
        }
    }
    mysqli_stmt_close($stmt);
    ob_clean();
    echo json_encode(['success' => true, 'rows' => $rows, 'count' => count($rows)]);
    mysqli_close($conn);
    exit;
}

$sql = 'SELECT account_id, ticker, COUNT(*) AS position_count FROM ig_positions GROUP BY account_id, ticker ORDER BY account_id, ticker';
$result = mysqli_query($conn, $sql);

if (!$result) {
    ob_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => mysqli_error($conn)]);
    mysqli_close($conn);
    exit;
}

$positions = [];
while ($row = mysqli_fetch_assoc($result)) {
    $positions[] = [
        'account_id' => (int) $row['account_id'],
        'ticker' => $row['ticker'],
        'position_count' => (int) $row['position_count'],
    ];
}

ob_clean();
echo json_encode([
    'success' => true,
    'positions' => $positions,
    'count' => count($positions),
]);

mysqli_close($conn);
