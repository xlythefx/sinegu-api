<?php
/**
 * Get referral network details for "Your Network".
 * GET ?user_uni_id=xxx (referrer)
 *
 * Aggregates invoices across the three brokers:
 *   capital_com -> billing_periods
 *   binance     -> binance_invoices
 *   ig          -> ig_invoices
 *
 * For each referred user returns:
 *   user_uni_id, name, referred_at, status,
 *   last_earnings, last_earnings_month, fee, payment_status,
 *   broker_breakdown[{ broker, paid_fee, pending_fee, paid_commission, pending_commission,
 *                      released_commission, unreleased_commission, has_overdue }],
 *   payout_released ('paid'|'pending'|'rejected'|null) — kept for legacy callers,
 *   payment_release_status ('paid'|'partial'|'pending'|'n_a'),
 *   realized_pnl, unrealized_pnl, realized_percentage, unrealized_percentage.
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

require_once __DIR__ . '/../conn.php';

if (!$conn) {
    ob_end_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit;
}

if (empty($_GET['user_uni_id'])) {
    ob_end_clean();
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'User uni_id is required']);
    exit;
}

$userUniId = trim($_GET['user_uni_id']);

// 1) Referral code
$referralCode = null;
$referralId = null;
if ($stmt = mysqli_prepare($conn, 'SELECT referral_id, code FROM referral_codes WHERE user_uni_id = ? LIMIT 1')) {
    mysqli_stmt_bind_param($stmt, 's', $userUniId);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $row = mysqli_fetch_assoc($result);
    if ($row) {
        $referralCode = $row['code'];
        $referralId = (int) $row['referral_id'];
    }
    mysqli_stmt_close($stmt);
}

if (!$referralCode) {
    ob_end_clean();
    echo json_encode([
        'success' => true,
        'code' => null,
        'referral_id' => null,
        'total_referrals' => 0,
        'referrals' => [],
    ]);
    mysqli_close($conn);
    exit;
}

// 2) Referrer affiliate_percentage
$pct = 0.0;
if ($stmt = mysqli_prepare($conn, 'SELECT affiliate_percentage FROM user_credentials WHERE uni_id = ? LIMIT 1')) {
    mysqli_stmt_bind_param($stmt, 's', $userUniId);
    mysqli_stmt_execute($stmt);
    $r = mysqli_stmt_get_result($stmt);
    $row = mysqli_fetch_assoc($r);
    if ($row && $row['affiliate_percentage'] !== null && $row['affiliate_percentage'] !== '') {
        $pct = (float) $row['affiliate_percentage'];
    }
    mysqli_stmt_close($stmt);
}

// 3) Referrals list
$referrals = [];
$statsSql = 'SELECT rt.referred_user_uni_id, rt.created_at, u.name
             FROM referral_tracking rt
             LEFT JOIN user_credentials u ON rt.referred_user_uni_id = u.uni_id
             WHERE rt.referrer_uni_id = ?
             ORDER BY rt.created_at DESC';
if (!($stmt = mysqli_prepare($conn, $statsSql))) {
    ob_end_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => mysqli_error($conn)]);
    mysqli_close($conn);
    exit;
}
mysqli_stmt_bind_param($stmt, 's', $userUniId);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
while ($row = mysqli_fetch_assoc($result)) {
    $referrals[] = [
        'user_uni_id' => $row['referred_user_uni_id'],
        'name' => $row['name'] ?? 'Unknown',
        'referred_at' => $row['created_at'],
    ];
}
mysqli_stmt_close($stmt);

// 4) Released payout items keyed for fast lookup: "$broker|$user|$month"
$releasedKeys = [];
if ($stmt = mysqli_prepare($conn, 'SELECT referred_user_uni_id, broker, month_year FROM referrer_payout_items WHERE referrer_uni_id = ?')) {
    mysqli_stmt_bind_param($stmt, 's', $userUniId);
    mysqli_stmt_execute($stmt);
    $r = mysqli_stmt_get_result($stmt);
    while ($rr = mysqli_fetch_assoc($r)) {
        $releasedKeys[$rr['broker'] . '|' . $rr['referred_user_uni_id'] . '|' . $rr['month_year']] = true;
    }
    mysqli_stmt_close($stmt);
}

// 5) Per-referral details
$today = date('Y-m-d');

foreach ($referrals as &$r) {
    $referredUniId = $r['user_uni_id'];

    // Has any account
    $hasAccounts = false;
    if ($stmt = mysqli_prepare($conn, 'SELECT COUNT(*) AS c FROM accounts WHERE uni_id = ?')) {
        mysqli_stmt_bind_param($stmt, 's', $referredUniId);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $arow = mysqli_fetch_assoc($res);
        $hasAccounts = ($arow && (int) $arow['c'] > 0);
        mysqli_stmt_close($stmt);
    }

    // Pull all invoices across 3 brokers, plus latest paid + latest pending
    $sql = "
        SELECT 'capital_com' AS broker, month_year, total_fee, status, due_date, created_at FROM billing_periods   WHERE user_id = ?
        UNION ALL
        SELECT 'binance'     AS broker, month_year, total_fee, status, due_date, created_at FROM binance_invoices  WHERE user_id = ?
        UNION ALL
        SELECT 'ig'          AS broker, month_year, total_fee, status, due_date, created_at FROM ig_invoices       WHERE user_id = ?
    ";
    $invoices = [];
    if ($stmt = mysqli_prepare($conn, $sql)) {
        mysqli_stmt_bind_param($stmt, 'sss', $referredUniId, $referredUniId, $referredUniId);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        while ($row = mysqli_fetch_assoc($res)) {
            $invoices[] = $row;
        }
        mysqli_stmt_close($stmt);
    }

    // Per-broker aggregates
    $brokerBreakdown = [
        'capital_com' => ['paid_fee' => 0.0, 'pending_fee' => 0.0, 'paid_commission' => 0.0, 'pending_commission' => 0.0, 'released_commission' => 0.0, 'unreleased_commission' => 0.0, 'has_overdue' => false],
        'binance'     => ['paid_fee' => 0.0, 'pending_fee' => 0.0, 'paid_commission' => 0.0, 'pending_commission' => 0.0, 'released_commission' => 0.0, 'unreleased_commission' => 0.0, 'has_overdue' => false],
        'ig'          => ['paid_fee' => 0.0, 'pending_fee' => 0.0, 'paid_commission' => 0.0, 'pending_commission' => 0.0, 'released_commission' => 0.0, 'unreleased_commission' => 0.0, 'has_overdue' => false],
    ];
    $hasOverdue = false;
    $totalPaidInvoices = 0;
    $releasedPaidInvoices = 0;
    $latestPaid = null;       // ['fee', 'month', 'broker', 'created_at']
    $latestPending = null;    // ['fee', 'month', 'broker', 'created_at']
    $latestAny = null;        // ['status', 'created_at']

    foreach ($invoices as $inv) {
        $broker = $inv['broker'];
        if (!isset($brokerBreakdown[$broker])) continue;
        $status = $inv['status'] ?? '';
        $fee    = (float) ($inv['total_fee'] ?? 0);
        $month  = $inv['month_year'] ?? '';
        $due    = $inv['due_date'] ?? null;
        $created = $inv['created_at'] ?? null;

        if (!$latestAny || ($created && $latestAny['created_at'] && $created > $latestAny['created_at'])) {
            $latestAny = ['status' => $status, 'created_at' => $created];
        }

        if ($status === 'paid') {
            $brokerBreakdown[$broker]['paid_fee'] += $fee;
            $invComm = $fee * ($pct / 100.0);
            $brokerBreakdown[$broker]['paid_commission'] += $invComm;
            $totalPaidInvoices++;
            if ($month !== '' && $month !== null) {
                $key = $broker . '|' . $referredUniId . '|' . $month;
                if (isset($releasedKeys[$key])) {
                    $brokerBreakdown[$broker]['released_commission'] += $invComm;
                    $releasedPaidInvoices++;
                } else {
                    $brokerBreakdown[$broker]['unreleased_commission'] += $invComm;
                }
            } else {
                $brokerBreakdown[$broker]['unreleased_commission'] += $invComm;
            }
            if (!$latestPaid || ($created && $latestPaid['created_at'] && $created > $latestPaid['created_at'])) {
                $latestPaid = ['fee' => $fee, 'month' => $month, 'broker' => $broker, 'created_at' => $created];
            }
        } elseif ($status === 'pending') {
            $brokerBreakdown[$broker]['pending_fee'] += $fee;
            $brokerBreakdown[$broker]['pending_commission'] += $fee * ($pct / 100.0);
            if ($due !== null && $due < $today) {
                $hasOverdue = true;
                $brokerBreakdown[$broker]['has_overdue'] = true;
            }
            if (!$latestPending || ($created && $latestPending['created_at'] && $created > $latestPending['created_at'])) {
                $latestPending = ['fee' => $fee, 'month' => $month, 'broker' => $broker, 'created_at' => $created];
            }
        } elseif ($status === 'overdue') {
            $hasOverdue = true;
            if (isset($brokerBreakdown[$broker])) {
                $brokerBreakdown[$broker]['has_overdue'] = true;
            }
        }
    }

    // Member status
    if (!$hasAccounts) {
        $r['status'] = 'new';
    } elseif ($hasOverdue) {
        $r['status'] = 'suspended';
    } else {
        $r['status'] = 'active';
    }

    // Last earnings = latest paid invoice across brokers
    $r['last_earnings'] = $latestPaid['fee'] ?? null;
    $r['last_earnings_month'] = $latestPaid['month'] ?? null;
    // Fee = latest pending across brokers
    $r['fee'] = $latestPending['fee'] ?? null;
    // payment_status = status of latest invoice (any broker), restricted to pending|paid
    $r['payment_status'] = null;
    if ($latestAny && in_array($latestAny['status'], ['pending', 'paid'], true)) {
        $r['payment_status'] = $latestAny['status'];
    }

    // payment_release_status:
    //   n_a     = no paid invoices yet
    //   paid    = every paid invoice released
    //   pending = paid invoices exist but none released
    //   partial = some released, some not
    if ($totalPaidInvoices === 0) {
        $r['payment_release_status'] = 'n_a';
    } elseif ($releasedPaidInvoices === $totalPaidInvoices) {
        $r['payment_release_status'] = 'paid';
    } elseif ($releasedPaidInvoices === 0) {
        $r['payment_release_status'] = 'pending';
    } else {
        $r['payment_release_status'] = 'partial';
    }

    // Legacy payout_released — derived from latest paid invoice's broker+month
    if ($latestPaid && $latestPaid['month'] !== '' && $latestPaid['month'] !== null) {
        $key = $latestPaid['broker'] . '|' . $referredUniId . '|' . $latestPaid['month'];
        $r['payout_released'] = isset($releasedKeys[$key]) ? 'paid' : 'pending';
    } else {
        $r['payout_released'] = null;
    }

    // Convert breakdown to list (only brokers with activity)
    $brokerBreakdownList = [];
    foreach ($brokerBreakdown as $brokerName => $b) {
        $hasAnything = $b['paid_fee'] > 0 || $b['pending_fee'] > 0 || $b['released_commission'] > 0;
        if (!$hasAnything) continue;
        $brokerBreakdownList[] = [
            'broker' => $brokerName,
            'paid_fee' => round($b['paid_fee'], 2),
            'pending_fee' => round($b['pending_fee'], 2),
            'paid_commission' => round($b['paid_commission'], 2),
            'pending_commission' => round($b['pending_commission'], 2),
            'released_commission' => round($b['released_commission'], 2),
            'unreleased_commission' => round($b['unreleased_commission'], 2),
            'has_overdue' => $b['has_overdue'],
        ];
    }
    $r['broker_breakdown'] = $brokerBreakdownList;

    // PNL
    $r['realized_pnl'] = 0.0;
    $r['unrealized_pnl'] = 0.0;
    $r['realized_percentage'] = null;
    $r['unrealized_percentage'] = null;

    if ($stmt = mysqli_prepare($conn, 'SELECT COALESCE(SUM(t.pnl), 0) AS r FROM trades t INNER JOIN accounts a ON t.account_id = a.id WHERE a.uni_id = ?')) {
        mysqli_stmt_bind_param($stmt, 's', $referredUniId);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $prow = mysqli_fetch_assoc($res);
        if ($prow) $r['realized_pnl'] = (float) ($prow['r'] ?? 0);
        mysqli_stmt_close($stmt);
    }

    if ($stmt = mysqli_prepare($conn, 'SELECT COALESCE(SUM(a.unrealized_pnl), 0) AS u FROM accounts a WHERE a.uni_id = ?')) {
        mysqli_stmt_bind_param($stmt, 's', $referredUniId);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $prow = mysqli_fetch_assoc($res);
        if ($prow) $r['unrealized_pnl'] = (float) ($prow['u'] ?? 0);
        mysqli_stmt_close($stmt);
    }

    try {
        if ($stmt = mysqli_prepare($conn, 'SELECT realized_percentage, unrealized_percentage FROM user_credentials WHERE uni_id = ? LIMIT 1')) {
            mysqli_stmt_bind_param($stmt, 's', $referredUniId);
            mysqli_stmt_execute($stmt);
            $res = mysqli_stmt_get_result($stmt);
            $prow = mysqli_fetch_assoc($res);
            if ($prow) {
                if (isset($prow['realized_percentage']) && $prow['realized_percentage'] !== null && $prow['realized_percentage'] !== '') {
                    $r['realized_percentage'] = (float) $prow['realized_percentage'];
                }
                if (isset($prow['unrealized_percentage']) && $prow['unrealized_percentage'] !== null && $prow['unrealized_percentage'] !== '') {
                    $r['unrealized_percentage'] = (float) $prow['unrealized_percentage'];
                }
            }
            mysqli_stmt_close($stmt);
        }
    } catch (mysqli_sql_exception $e) {
        // ignore
    }
}
unset($r);

ob_end_clean();
echo json_encode([
    'success' => true,
    'code' => $referralCode,
    'referral_id' => $referralId,
    'total_referrals' => count($referrals),
    'referrals' => $referrals,
]);
mysqli_close($conn);
