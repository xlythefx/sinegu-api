<?php
/**
 * Admin: return all broker accounts (Capital.com + Binance) with a broker_type field.
 * Used by User Management sub-rows. Capital.com from `accounts`, Binance from `binance_accounts`.
 * GET: no params
 * Returns: { "success": true, "accounts": [ { ...row, "broker_type": "Capital.com"|"BINANCE" } ] }
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Admin-Password');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

/*
 * Gated rather than stripped, unlike accounts_list.php.
 *
 * This one is admin-only (AdminUsers) and genuinely displays api_key and email,
 * and Binance rows are *identified* by api_key — binance_accounts has no `id`,
 * so it is the primary key that edit/disconnect/reveal all key off. Removing it
 * would break those actions, so the endpoint takes the password instead.
 */
require_once __DIR__ . '/includes/admin_action_auth.php';
requireAdminActionPassword();

require_once __DIR__ . '/conn.php';
require_once __DIR__ . '/includes/bybit_schema.php';

if (!$conn) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit;
}

$out = [];

// --- 1) Capital.com accounts from `accounts` ---
$describe = mysqli_query($conn, 'DESCRIBE accounts');
if (!$describe) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Table accounts not found']);
    exit;
}
// Safe columns only — `password`, `cst` and `x_security_token` are deliberately
// absent. See the long note in accounts_list.php; this endpoint is the same data
// with Binance appended, so the two must be kept in step.
$allowed = ['id', 'uni_id', 'name', 'api_key', 'broker_accountID', 'email', 'demo', 'enabled', 'created_at', 'balance', 'unrealized_pnl', 'currency_type', 'deposit_amount', 'initial_deposit', 'equity', 'realized_pnl', 'session_last_used_at', 'deleted_at'];
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
    echo json_encode(['success' => false, 'message' => 'Table accounts must have id and uni_id']);
    exit;
}

$selectList = implode(', ', $cols);
// Whether a password is set, never the password itself.
if ($hasPasswordCol) {
    $selectList .= ", (password IS NOT NULL AND password <> '') AS has_password";
}
$sql = "SELECT {$selectList} FROM accounts ORDER BY (deleted_at IS NULL) DESC, email ASC";
$result = mysqli_query($conn, $sql);
if (!$result) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to fetch accounts: ' . mysqli_error($conn)]);
    exit;
}

while ($row = mysqli_fetch_assoc($result)) {
    $accountId = (int) $row['id'];
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
    $row['broker_type'] = 'Capital.com';
    $out[] = $row;
}
mysqli_free_result($result);

// --- 2) Binance accounts from `binance_accounts` (with uni_id); realized from binance_pastpositions, unrealized from binance_positions ---
$binanceSql = "SELECT api_key, uni_id, name, balance, initial_deposit, currency_type, created_at, deleted_at, demo, enabled FROM binance_accounts WHERE uni_id IS NOT NULL AND uni_id != '' ORDER BY (deleted_at IS NULL) DESC, name ASC";
$binanceResult = mysqli_query($conn, $binanceSql);
if ($binanceResult) {
    while ($row = mysqli_fetch_assoc($binanceResult)) {
        $apiKey = $conn ? mysqli_real_escape_string($conn, $row['api_key']) : $row['api_key'];
        $realizedPnl = 0.0;
        $unrealizedPnl = 0.0;
        $pastSql = "SELECT COALESCE(SUM(realized_pnl), 0) AS total FROM binance_pastpositions WHERE api_key = '" . $apiKey . "'";
        $pastRes = mysqli_query($conn, $pastSql);
        if ($pastRes) {
            $pastRow = mysqli_fetch_assoc($pastRes);
            $realizedPnl = ($pastRow && isset($pastRow['total'])) ? (float) $pastRow['total'] : 0.0;
            mysqli_free_result($pastRes);
        }
        $posSql = "SELECT COALESCE(SUM(unrealized_profit), 0) AS total FROM binance_positions WHERE api_key = '" . $apiKey . "'";
        $posRes = mysqli_query($conn, $posSql);
        if ($posRes) {
            $posRow = mysqli_fetch_assoc($posRes);
            $unrealizedPnl = ($posRow && isset($posRow['total'])) ? (float) $posRow['total'] : 0.0;
            mysqli_free_result($posRes);
        }
        $out[] = [
            'id' => 'binance_' . $row['api_key'],
            'uni_id' => $row['uni_id'],
            'name' => $row['name'] ?? 'Binance',
            'email' => '',
            'api_key' => $row['api_key'],
            // Binance authenticates with api_key + secret_key, and neither the
            // secret nor any password is ever returned here.
            'has_password' => 0,
            'demo' => (int) ($row['demo'] ?? 0),
            'enabled' => (int) ($row['enabled'] ?? 1),
            'created_at' => $row['created_at'] ?? '',
            'balance' => isset($row['balance']) ? (float) $row['balance'] : null,
            'realized_pnl' => $realizedPnl,
            'unrealized_pnl' => $unrealizedPnl,
            'currency_type' => $row['currency_type'] ?? 'USDT',
            'deposit_amount' => isset($row['initial_deposit']) ? (float) $row['initial_deposit'] : 0,
            'initial_deposit' => isset($row['initial_deposit']) ? (float) $row['initial_deposit'] : 0,
            'deleted_at' => $row['deleted_at'] ?? null,
            'broker_type' => 'BINANCE',
        ];
    }
    mysqli_free_result($binanceResult);
}

// --- 3) MEXC accounts from `mexc_accounts` (twin of the Binance block above; same columns) ---
$mexcSql = "SELECT api_key, uni_id, name, balance, initial_deposit, currency_type, created_at, deleted_at, demo, enabled FROM mexc_accounts WHERE uni_id IS NOT NULL AND uni_id != '' ORDER BY (deleted_at IS NULL) DESC, name ASC";
$mexcResult = mysqli_query($conn, $mexcSql);
if ($mexcResult) {
    while ($row = mysqli_fetch_assoc($mexcResult)) {
        $apiKey = $conn ? mysqli_real_escape_string($conn, $row['api_key']) : $row['api_key'];
        $realizedPnl = 0.0;
        $unrealizedPnl = 0.0;
        $pastSql = "SELECT COALESCE(SUM(realized_pnl), 0) AS total FROM mexc_pastpositions WHERE api_key = '" . $apiKey . "'";
        $pastRes = mysqli_query($conn, $pastSql);
        if ($pastRes) {
            $pastRow = mysqli_fetch_assoc($pastRes);
            $realizedPnl = ($pastRow && isset($pastRow['total'])) ? (float) $pastRow['total'] : 0.0;
            mysqli_free_result($pastRes);
        }
        $posSql = "SELECT COALESCE(SUM(unrealized_profit), 0) AS total FROM mexc_positions WHERE api_key = '" . $apiKey . "'";
        $posRes = mysqli_query($conn, $posSql);
        if ($posRes) {
            $posRow = mysqli_fetch_assoc($posRes);
            $unrealizedPnl = ($posRow && isset($posRow['total'])) ? (float) $posRow['total'] : 0.0;
            mysqli_free_result($posRes);
        }
        $out[] = [
            'id' => 'mexc_' . $row['api_key'],
            'uni_id' => $row['uni_id'],
            'name' => $row['name'] ?? 'MEXC',
            'email' => '',
            'api_key' => $row['api_key'],
            // MEXC authenticates with api_key + secret_key, and neither the
            // secret nor any password is ever returned here.
            'has_password' => 0,
            'demo' => (int) ($row['demo'] ?? 0),
            'enabled' => (int) ($row['enabled'] ?? 1),
            'created_at' => $row['created_at'] ?? '',
            'balance' => isset($row['balance']) ? (float) $row['balance'] : null,
            'realized_pnl' => $realizedPnl,
            'unrealized_pnl' => $unrealizedPnl,
            'currency_type' => $row['currency_type'] ?? 'USDT',
            'deposit_amount' => isset($row['initial_deposit']) ? (float) $row['initial_deposit'] : 0,
            'initial_deposit' => isset($row['initial_deposit']) ? (float) $row['initial_deposit'] : 0,
            'deleted_at' => $row['deleted_at'] ?? null,
            'broker_type' => 'MEXC',
        ];
    }
    mysqli_free_result($mexcResult);
}

// --- 4) Bybit accounts from `bybit_accounts` (twin of the MEXC block above; skipped until migrations 098-101 create its tables) ---
if (bybit_tables_ready($conn)) {
    $bybitSql = "SELECT api_key, uni_id, name, balance, initial_deposit, currency_type, created_at, deleted_at, demo, enabled FROM bybit_accounts WHERE uni_id IS NOT NULL AND uni_id != '' ORDER BY (deleted_at IS NULL) DESC, name ASC";
    $bybitResult = mysqli_query($conn, $bybitSql);
    if ($bybitResult) {
        while ($row = mysqli_fetch_assoc($bybitResult)) {
            $apiKey = $conn ? mysqli_real_escape_string($conn, $row['api_key']) : $row['api_key'];
            $realizedPnl = 0.0;
            $unrealizedPnl = 0.0;
            $pastSql = "SELECT COALESCE(SUM(realized_pnl), 0) AS total FROM bybit_pastpositions WHERE api_key = '" . $apiKey . "'";
            $pastRes = mysqli_query($conn, $pastSql);
            if ($pastRes) {
                $pastRow = mysqli_fetch_assoc($pastRes);
                $realizedPnl = ($pastRow && isset($pastRow['total'])) ? (float) $pastRow['total'] : 0.0;
                mysqli_free_result($pastRes);
            }
            $posSql = "SELECT COALESCE(SUM(unrealized_profit), 0) AS total FROM bybit_positions WHERE api_key = '" . $apiKey . "'";
            $posRes = mysqli_query($conn, $posSql);
            if ($posRes) {
                $posRow = mysqli_fetch_assoc($posRes);
                $unrealizedPnl = ($posRow && isset($posRow['total'])) ? (float) $posRow['total'] : 0.0;
                mysqli_free_result($posRes);
            }
            $out[] = [
                'id' => 'bybit_' . $row['api_key'],
                'uni_id' => $row['uni_id'],
                'name' => $row['name'] ?? 'Bybit',
                'email' => '',
                'api_key' => $row['api_key'],
                // Bybit authenticates with api_key + secret_key, and neither the
                // secret nor any password is ever returned here.
                'has_password' => 0,
                'demo' => (int) ($row['demo'] ?? 0),
                'enabled' => (int) ($row['enabled'] ?? 1),
                'created_at' => $row['created_at'] ?? '',
                'balance' => isset($row['balance']) ? (float) $row['balance'] : null,
                'realized_pnl' => $realizedPnl,
                'unrealized_pnl' => $unrealizedPnl,
                'currency_type' => $row['currency_type'] ?? 'USDT',
                'deposit_amount' => isset($row['initial_deposit']) ? (float) $row['initial_deposit'] : 0,
                'initial_deposit' => isset($row['initial_deposit']) ? (float) $row['initial_deposit'] : 0,
                'deleted_at' => $row['deleted_at'] ?? null,
                'broker_type' => 'Bybit',
            ];
        }
        mysqli_free_result($bybitResult);
    }
}

mysqli_close($conn);

echo json_encode([
    'success' => true,
    'accounts' => $out,
]);
