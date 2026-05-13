<?php
/**
 * Admin: Get user details and broker accounts for a specific user.
 * GET /admin/GET/get_user_details.php?uni_id=...
 * Returns: user (user_credentials row), accounts (broker accounts for this uni_id)
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

$userSql = "SELECT uni_id, name, email, status, created_at, updated_at, realized_percentage, unrealized_percentage, affiliate_percentage, course_level, user_profile, user_banner FROM user_credentials WHERE uni_id = ? LIMIT 1";
$user = null;

if ($stmt = mysqli_prepare($conn, $userSql)) {
    mysqli_stmt_bind_param($stmt, 's', $uni_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $row = mysqli_fetch_assoc($result);
    mysqli_stmt_close($stmt);

    if ($row) {
        $user = [
            'uni_id' => $row['uni_id'],
            'name' => $row['name'],
            'email' => $row['email'],
            'status' => $row['status'],
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
            'realized_percentage' => $row['realized_percentage'] !== null ? (float) $row['realized_percentage'] : null,
            'unrealized_percentage' => $row['unrealized_percentage'] !== null ? (float) $row['unrealized_percentage'] : null,
            'affiliate_percentage' => $row['affiliate_percentage'] !== null ? (float) $row['affiliate_percentage'] : null,
            'course_level' => $row['course_level'],
            'user_profile' => $row['user_profile'],
            'user_banner' => $row['user_banner'],
        ];
    }
}

if (!$user) {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'User not found']);
    mysqli_close($conn);
    exit;
}

$accSql = "SELECT id, uni_id, name, email, balance, unrealized_pnl, equity, currency_type, demo, enabled, created_at, broker_accountID, deleted_at, deposit_amount, initial_deposit FROM accounts WHERE uni_id = ? ORDER BY (deleted_at IS NULL) DESC, created_at ASC";
$accounts = [];
$accountIds = [];

if ($stmt = mysqli_prepare($conn, $accSql)) {
    mysqli_stmt_bind_param($stmt, 's', $uni_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($result)) {
        $accId = (int) $row['id'];
        $accountIds[] = $accId;
        $accounts[] = [
            'id' => $accId,
            'uni_id' => $row['uni_id'],
            'name' => $row['name'],
            'email' => $row['email'],
            'balance' => (float) ($row['balance'] ?? 0),
            'unrealized_pnl' => (float) ($row['unrealized_pnl'] ?? 0),
            'realized_pnl' => 0.0, // filled below
            'equity' => (float) ($row['equity'] ?? 0),
            'currency_type' => $row['currency_type'] ?? 'USD',
            'demo' => (int) ($row['demo'] ?? 0),
            'enabled' => (int) ($row['enabled'] ?? 1),
            'created_at' => $row['created_at'],
            'broker_accountID' => $row['broker_accountID'],
            'deleted_at' => $row['deleted_at'],
            'deposit_amount' => (float) ($row['deposit_amount'] ?? 0),
            'initial_deposit' => (float) ($row['initial_deposit'] ?? 0),
        ];
    }
    mysqli_stmt_close($stmt);
}

// Compute realized_pnl from trades (closed trades only)
if (!empty($accountIds)) {
    $placeholders = implode(',', array_fill(0, count($accountIds), '?'));
    $types = str_repeat('i', count($accountIds));
    $realizedSql = "SELECT account_id, COALESCE(SUM(pnl), 0) AS realized FROM trades WHERE account_id IN ($placeholders) AND date_closed IS NOT NULL GROUP BY account_id";
    if ($stmt = mysqli_prepare($conn, $realizedSql)) {
        $bindParams = array_merge([$types], $accountIds);
        $refs = [];
        foreach ($bindParams as $k => $v) {
            $refs[$k] = &$bindParams[$k];
        }
        call_user_func_array([$stmt, 'bind_param'], $refs);
        mysqli_stmt_execute($stmt);
        $rResult = mysqli_stmt_get_result($stmt);
        $realizedByAccount = [];
        while ($rRow = mysqli_fetch_assoc($rResult)) {
            $realizedByAccount[(int) $rRow['account_id']] = (float) $rRow['realized'];
        }
        mysqli_stmt_close($stmt);
        foreach ($accounts as &$a) {
            $a['realized_pnl'] = $realizedByAccount[$a['id']] ?? 0.0;
        }
        unset($a);
    }
}

// Latest HWM per Capital.com account (from billing_periods)
if (!empty($accountIds)) {
    $placeholders = implode(',', array_fill(0, count($accountIds), '?'));
    $types = str_repeat('i', count($accountIds));
    $hwmSql = "SELECT bp.account_id, bp.hwm_after, bp.month_year
               FROM billing_periods bp
               INNER JOIN (
                 SELECT account_id, MAX(month_year) AS max_month
                 FROM billing_periods
                 WHERE account_id IN ($placeholders)
                 GROUP BY account_id
               ) latest
               ON bp.account_id = latest.account_id AND bp.month_year = latest.max_month";
    if ($stmt = mysqli_prepare($conn, $hwmSql)) {
        $bindParams = array_merge([$types], $accountIds);
        $refs = [];
        foreach ($bindParams as $k => $v) {
            $refs[$k] = &$bindParams[$k];
        }
        call_user_func_array([$stmt, 'bind_param'], $refs);
        mysqli_stmt_execute($stmt);
        $hResult = mysqli_stmt_get_result($stmt);
        $hwmByAccount = [];
        while ($hRow = mysqli_fetch_assoc($hResult)) {
            $hwmByAccount[(int) $hRow['account_id']] = [
                'hwm_after' => (float) $hRow['hwm_after'],
                'month_year' => $hRow['month_year'],
            ];
        }
        mysqli_stmt_close($stmt);
        foreach ($accounts as &$a) {
            $hwm = $hwmByAccount[$a['id']] ?? null;
            $a['hwm_after'] = $hwm['hwm_after'] ?? null;
            $a['hwm_month_year'] = $hwm['month_year'] ?? null;
        }
        unset($a);
    }
}

// Latest HWM for Binance (binance_invoices) and IG (ig_invoices) by uni_id
$binance_hwm = null;
$ig_hwm = null;

$binSql = "SELECT hwm_after, month_year FROM binance_invoices WHERE user_id = ? ORDER BY month_year DESC LIMIT 1";
if ($stmt = mysqli_prepare($conn, $binSql)) {
    mysqli_stmt_bind_param($stmt, 's', $uni_id);
    mysqli_stmt_execute($stmt);
    $bRes = mysqli_stmt_get_result($stmt);
    if ($bRow = mysqli_fetch_assoc($bRes)) {
        $binance_hwm = [
            'hwm_after' => (float) $bRow['hwm_after'],
            'month_year' => $bRow['month_year'],
        ];
    }
    mysqli_stmt_close($stmt);
}

$igSql = "SELECT hwm_after, month_year FROM ig_invoices WHERE user_id = ? ORDER BY month_year DESC LIMIT 1";
if ($stmt = mysqli_prepare($conn, $igSql)) {
    mysqli_stmt_bind_param($stmt, 's', $uni_id);
    mysqli_stmt_execute($stmt);
    $iRes = mysqli_stmt_get_result($stmt);
    if ($iRow = mysqli_fetch_assoc($iRes)) {
        $ig_hwm = [
            'hwm_after' => (float) $iRow['hwm_after'],
            'month_year' => $iRow['month_year'],
        ];
    }
    mysqli_stmt_close($stmt);
}

ob_end_clean();
echo json_encode([
    'success' => true,
    'user' => $user,
    'accounts' => $accounts,
    'binance_hwm' => $binance_hwm,
    'ig_hwm' => $ig_hwm,
]);
mysqli_close($conn);
