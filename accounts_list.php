<?php

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Method not allowed',
    ]);
    exit;
}

require_once __DIR__ . '/conn.php';

if (!$conn) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database connection failed',
    ]);
    exit;
}

// Build SELECT from existing columns so we work with or without initial_deposit, equity, etc.
$describe = mysqli_query($conn, 'DESCRIBE accounts');
if (!$describe) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Table accounts not found or inaccessible',
    ]);
    exit;
}
/**
 * Columns safe to return. `api_key`, `email`, `password`, `cst` and
 * `x_security_token` are deliberately ABSENT — this endpoint is unauthenticated
 * and is called by ordinary user pages AND by the public landing page, so
 * anything listed here is effectively published to the world.
 *
 *   password           stored in plaintext; with api_key + email it is the exact
 *                      triple Capital.com's POST /api/v1/session requires.
 *   cst / x_security_token
 *                      live Capital.com session tokens — those two headers alone
 *                      authenticate a request (CapitalFlask src/capital_api.py),
 *                      and a background loop keeps them fresh.
 *   api_key / email    removed for the same reason: they are two thirds of that
 *                      triple, and returning them here published every customer's
 *                      key and login email to anyone who opened the marketing
 *                      site (Index.tsx renders <BotPerformance>, which called
 *                      this endpoint anonymously just to find the master row).
 *
 * Nothing consumed either field: across the public page, the user dashboard and
 * alerts pages, AdminOverview / AdminInvoiceHistory / AdminAlerts, and
 * CapitalFlask src/main.py, the only columns read are id, name, uni_id, balance,
 * unrealized_pnl, currency_type, hwm_equity and broker_accountID.
 *
 * The UI never displayed the secrets either: it renders "••••••••" from a
 * boolean, so `has_password` below replaces the value. Do not add any of them
 * back — reveal one account at a time via
 * admin/GET/get_broker_credentials.php instead.
 */
$allowed = ['id', 'uni_id', 'name', 'broker_accountID', 'demo', 'enabled', 'created_at', 'balance', 'unrealized_pnl', 'currency_type', 'deposit_amount', 'initial_deposit', 'equity', 'realized_pnl', 'session_last_used_at', 'deleted_at'];
$cols = [];
$hasPasswordCol = false;
while ($dr = mysqli_fetch_assoc($describe)) {
    if ($dr['Field'] === 'password') {
        $hasPasswordCol = true;
    }
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
// Whether a password is set, never the password itself — the UI only needs the
// boolean to decide between "••••••••" and "—".
if ($hasPasswordCol) {
    $selectList .= ", (password IS NOT NULL AND password <> '') AS has_password";
}
// All accounts (active + soft-deleted); active first, then by email. `email` is
// no longer in the select list but is still a column of the table, so ordering by
// it stays valid and the row order callers see is unchanged.
$sql = "SELECT {$selectList} FROM accounts ORDER BY (deleted_at IS NULL) DESC, email ASC";
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
    
    // Calculate realized_pnl from trades table (if not already in row)
    $realizedPnl = isset($row['realized_pnl']) ? (float) $row['realized_pnl'] : null;
    if ($realizedPnl === null) {
        $realizedPnlSql = "SELECT COALESCE(SUM(pnl), 0) AS total_pnl FROM trades WHERE account_id = $accountId";
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
    $row['has_password'] = (int) ($row['has_password'] ?? 0);
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

