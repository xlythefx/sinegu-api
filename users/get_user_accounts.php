<?php
/**
 * Fetch accounts from the accounts table for a single user by uni_id.
 * The uni_id is the same as in user_credentials (from login/register); the frontend
 * sends it from localStorage (e.g. userUniId).
 *
 * GET: ?uni_id=xxx
 * Returns: {"success": true, "accounts": [...]} or {"success": false, "message": "..."}
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
    echo json_encode([
        'success' => false,
        'message' => 'Method not allowed. Use GET.',
    ]);
    exit;
}

require_once __DIR__ . '/../conn.php';

if (!$conn) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database connection failed',
    ]);
    exit;
}

$uni_id = isset($_GET['uni_id']) ? trim($_GET['uni_id']) : null;
if (!$uni_id) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Missing required parameter: uni_id',
    ]);
    exit;
}

$uni_id = mysqli_real_escape_string($conn, $uni_id);

// Build SELECT from existing columns (same schema handling as accounts_list.php)
$describe = mysqli_query($conn, 'DESCRIBE accounts');
if (!$describe) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Table accounts not found or inaccessible',
    ]);
    exit;
}
$allowed = ['id', 'uni_id', 'name', 'api_key', 'broker_accountID', 'email', 'password', 'demo', 'enabled', 'created_at', 'balance', 'unrealized_pnl', 'currency_type', 'deposit_amount', 'initial_deposit', 'equity', 'realized_pnl', 'hwm_equity', 'hwm_realize', 'cst', 'x_security_token', 'session_last_used_at'];
$cols = [];
while ($dr = mysqli_fetch_assoc($describe)) {
    if (in_array($dr['Field'], $allowed, true)) {
        $cols[] = $dr['Field'];
    }
}
mysqli_free_result($describe);
if (!in_array('id', $cols, true) || !in_array('uni_id', $cols, true)) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Table accounts must have at least id and uni_id',
    ]);
    exit;
}

$selectList = implode(', ', $cols);
$sql = "SELECT {$selectList} FROM accounts WHERE uni_id = '{$uni_id}' AND (deleted_at IS NULL) ORDER BY id ASC";
$result = mysqli_query($conn, $sql);

if (!$result) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to fetch accounts: ' . mysqli_error($conn),
    ]);
    exit;
}

$accounts = [];

while ($row = mysqli_fetch_assoc($result)) {
    $accountId = (int) $row['id'];

    // realized_pnl: use column if present, else compute from trades
    $realizedPnl = isset($row['realized_pnl']) ? (float) $row['realized_pnl'] : null;
    if ($realizedPnl === null) {
        $realizedPnlSql = "SELECT COALESCE(SUM(pnl), 0) AS total_pnl FROM trades WHERE account_id = " . $accountId;
        $realizedPnlResult = mysqli_query($conn, $realizedPnlSql);
        $realizedPnl = 0.0;
        if ($realizedPnlResult) {
            $realizedPnlRow = mysqli_fetch_assoc($realizedPnlResult);
            $realizedPnl = isset($realizedPnlRow['total_pnl']) ? (float) $realizedPnlRow['total_pnl'] : 0.0;
            mysqli_free_result($realizedPnlResult);
        }
    }

    $row['demo'] = (int) ($row['demo'] ?? 0);
    $row['enabled'] = (int) ($row['enabled'] ?? 1);
    $row['balance'] = isset($row['balance']) ? (float) $row['balance'] : null;
    $row['realized_pnl'] = $realizedPnl;
    $row['unrealized_pnl'] = isset($row['unrealized_pnl']) ? (float) $row['unrealized_pnl'] : null;
    $row['deposit_amount'] = isset($row['deposit_amount']) ? (float) $row['deposit_amount'] : 0.0;
    $row['initial_deposit'] = isset($row['initial_deposit']) ? (float) $row['initial_deposit'] : ($row['deposit_amount'] ?? null);
    $accounts[] = $row;
}

mysqli_free_result($result);
mysqli_close($conn);

echo json_encode([
    'success' => true,
    'accounts' => $accounts,
]);
