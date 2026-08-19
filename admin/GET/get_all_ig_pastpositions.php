<?php
/**
 * Admin: Get all IG.com past positions (closed trades, all users).
 * GET /admin/GET/get_all_ig_pastpositions.php
 * Returns: trades array normalized to Trade-like shape with broker "IG.com"
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

$sql = "SELECT p.id, p.account_id, p.ticker, p.close_price, p.pnl, p.closed_at, p.created_at,
               p.strategy, p.side,
               a.name AS account_name, a.email AS account_email, a.uni_id,
               a.balance AS account_balance, a.currency_type AS account_currency
    FROM ig_past_positions p
    JOIN ig_accounts a ON a.id = p.account_id
    WHERE a.deleted_at IS NULL
    ORDER BY p.closed_at DESC";

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
        'id'              => (int) $row['id'],
        'account_id'      => (int) $row['account_id'],
        'ticker'          => $row['ticker'],
        'price'           => (string) ($row['close_price'] ?? '0'),
        'pnl'             => (float) ($row['pnl'] ?? 0),
        'pnl_percentage'  => 0,
        'closed_positions'=> 1,
        'date_closed'     => $datePart,
        'time_closed'     => $timePart,
        'created_at'      => $row['created_at'] ?? $closedAt,
        'strategy'        => $row['strategy'] ?? null,
        'side'            => $row['side'] ?? null,
        'account_name'    => $row['account_name'] ?? 'IG.com',
        'account_email'   => $row['account_email'] ?? null,
        // Owner link + capital base, so admin views can attribute a trade to a user and
        // express its P&L as a share of that account's balance.
        'uni_id'          => $row['uni_id'] ?? null,
        'account_balance' => $row['account_balance'] === null ? null : (float) $row['account_balance'],
        'account_currency'=> $row['account_currency'] ?? null,
        'broker'          => 'IG.com',
    ];
}

mysqli_free_result($result);
mysqli_close($conn);

ob_end_clean();
echo json_encode(['success' => true, 'trades' => $trades]);
