<?php
/**
 * Admin: Get trades for a user (by uni_id).
 * GET /admin/GET/get_user_trades.php?uni_id=...
 * Returns: trades array, count
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

$uni_id = isset($_GET['uni_id']) ? trim($_GET['uni_id']) : '';

if (empty($uni_id)) {
    ob_end_clean();
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'uni_id is required']);
    exit;
}

require_once __DIR__ . '/../../conn.php';

if (!$conn) {
    ob_end_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit;
}

$sql = "SELECT t.id, t.account_id, t.ticker, t.price, t.pnl, t.pnl_percentage, t.closed_positions,
               t.date_closed, t.time_closed, t.created_at,
               a.name AS account_name, a.email AS account_email, a.uni_id AS account_uni_id
        FROM trades t
        INNER JOIN accounts a ON t.account_id = a.id
        WHERE a.uni_id = ? AND (a.deleted_at IS NULL)
        ORDER BY t.created_at DESC";

$trades = [];

if ($stmt = mysqli_prepare($conn, $sql)) {
    mysqli_stmt_bind_param($stmt, 's', $uni_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $row['pnl'] = (float) ($row['pnl'] ?? 0);
            $row['pnl_percentage'] = isset($row['pnl_percentage']) ? (float) $row['pnl_percentage'] : null;
            $row['closed_positions'] = (int) ($row['closed_positions'] ?? 0);
            $trades[] = $row;
        }
        mysqli_free_result($result);
    }
    mysqli_stmt_close($stmt);
}

ob_end_clean();
echo json_encode([
    'success' => true,
    'trades' => $trades,
    'count' => count($trades),
]);
mysqli_close($conn);
