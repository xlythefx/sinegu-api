<?php
/**
 * Admin: Get all IG.com active positions (all users).
 * GET /admin/GET/get_all_ig_positions.php
 * Returns: positions array normalized to Position-like shape with broker "IG.com"
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

$sql = "SELECT pos.id, pos.account_id, pos.ticker, pos.level, pos.size, pos.direction,
               pos.unrealized_pnl, pos.currency, pos.strategy, pos.created_at, pos.updated_at,
               a.name AS account_name, a.email AS account_email, a.uni_id
    FROM ig_positions pos
    JOIN ig_accounts a ON a.id = pos.account_id
    WHERE a.deleted_at IS NULL
    ORDER BY pos.updated_at DESC";

$result = mysqli_query($conn, $sql);

if (!$result) {
    ob_end_clean();
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
        'price'         => (string) ($row['level'] ?? '0'),
        'increments'    => (string) ($row['size'] ?? ''),
        'updated_at'    => $row['updated_at'] ?? $row['created_at'] ?? '',
        'account_name'  => $row['account_name'] ?? 'IG.com',
        'account_email' => $row['account_email'] ?? null,
        'unrealized_pnl'=> (float) ($row['unrealized_pnl'] ?? 0),
        'strategy'      => $row['strategy'] ?? null,
        'side'          => $row['direction'] ?? null,
        'broker'        => 'IG.com',
    ];
}

mysqli_free_result($result);
mysqli_close($conn);

ob_end_clean();
echo json_encode(['success' => true, 'positions' => $positions]);
