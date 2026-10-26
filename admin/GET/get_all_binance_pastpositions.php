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

// The account join supplies the owner label + capital base, so admin views can attribute a
// trade to a user and express its P&L as a share of that account's balance. `api_key` is a
// secret and is deliberately never echoed back — `uni_id` is the owner key.
$sql = "SELECT p.id, p.uni_id, p.symbol, p.realized_pnl, p.exit_price, p.position_amt, p.entry_price, p.closed_at, p.created_at,
               p.strategy, p.side,
               b.name AS account_name, b.balance AS account_balance,
               b.currency_type AS account_currency,
               u.email AS account_email
    FROM binance_pastpositions p
    LEFT JOIN binance_accounts b ON b.api_key = p.api_key
    LEFT JOIN user_credentials u ON u.uni_id = p.uni_id
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
        'id' => (int) $row['id'],
        'account_id' => 0,
        'ticker' => $row['symbol'],
        'price' => (string) ($row['exit_price'] ?? '0'),
        // Size closed and its entry; entry_price is NULL/0 on rows the poller backfilled.
        'size' => $row['position_amt'] === null ? null : abs((float) $row['position_amt']),
        'entry_price' => (float) $row['entry_price'] > 0 ? (float) $row['entry_price'] : null,
        'pnl' => (float) ($row['realized_pnl'] ?? 0),
        'pnl_percentage' => 0,
        'closed_positions' => 1,
        'date_closed' => $datePart,
        'time_closed' => $timePart,
        'created_at' => $row['created_at'] ?? $closedAt,
        'strategy'     => $row['strategy'] ?? null,
        'side'         => $row['side'] ?? null,
        'account_name' => $row['account_name'] ?: 'Binance',
        'account_email'=> $row['account_email'] ?? null,
        'uni_id'       => $row['uni_id'] ?? null,
        'account_balance' => $row['account_balance'] === null ? null : (float) $row['account_balance'],
        'account_currency'=> $row['account_currency'] ?? null,
        'broker'       => 'Binance',
    ];
}

mysqli_free_result($result);
mysqli_close($conn);

ob_end_clean();
echo json_encode(['success' => true, 'trades' => $trades]);
