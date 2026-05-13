<?php
/**
 * Admin: Affiliate overview – all referrers (code creators) and their referrals.
 * GET /admin/GET/get_affiliate_overview.php
 *
 * Aggregates paid/pending invoices across all three broker tables:
 *   capital_com -> billing_periods
 *   binance     -> binance_invoices
 *   ig          -> ig_invoices
 *
 * has_releasable / releasable_commission_total exclude items already released
 * (present in referrer_payout_items).
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

$BROKER_TABLES = [
    'capital_com' => 'billing_periods',
    'binance'     => 'binance_invoices',
    'ig'          => 'ig_invoices',
];

/**
 * UNION query across the three broker invoice tables for a single user_id.
 * Returns rows: { broker, month_year, total_fee, status, due_date }
 */
function fetchAllInvoicesForUser(mysqli $conn, string $userId): array
{
    $sql = "
        SELECT 'capital_com' AS broker, month_year, total_fee, status, due_date FROM billing_periods WHERE user_id = ?
        UNION ALL
        SELECT 'binance'     AS broker, month_year, total_fee, status, due_date FROM binance_invoices WHERE user_id = ?
        UNION ALL
        SELECT 'ig'          AS broker, month_year, total_fee, status, due_date FROM ig_invoices     WHERE user_id = ?
    ";
    $rows = [];
    $st = mysqli_prepare($conn, $sql);
    if (!$st) return $rows;
    mysqli_stmt_bind_param($st, 'sss', $userId, $userId, $userId);
    mysqli_stmt_execute($st);
    $res = mysqli_stmt_get_result($st);
    while ($r = mysqli_fetch_assoc($res)) {
        $rows[] = $r;
    }
    mysqli_stmt_close($st);
    return $rows;
}

// All referrers
$refSql = "SELECT rc.referral_id, rc.code, rc.user_uni_id, uc.name AS referrer_name, uc.email AS referrer_email, uc.affiliate_percentage
           FROM referral_codes rc
           LEFT JOIN user_credentials uc ON rc.user_uni_id = uc.uni_id
           ORDER BY rc.created_at DESC";
$referrers = [];
$res = mysqli_query($conn, $refSql);
if (!$res) {
    ob_end_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => mysqli_error($conn)]);
    mysqli_close($conn);
    exit;
}

while ($row = mysqli_fetch_assoc($res)) {
    $referrerUniId = $row['user_uni_id'];
    $affiliatePct = isset($row['affiliate_percentage']) && $row['affiliate_percentage'] !== '' ? (float) $row['affiliate_percentage'] : 0.0;

    // Already-released keys for this referrer: "$broker|$user|$month"
    $releasedKeys = [];
    $releasedCommissionByUser = []; // referred_user_uni_id => sum(commission)
    $st = mysqli_prepare($conn, "SELECT referred_user_uni_id, broker, month_year, commission FROM referrer_payout_items WHERE referrer_uni_id = ?");
    if ($st) {
        mysqli_stmt_bind_param($st, 's', $referrerUniId);
        mysqli_stmt_execute($st);
        $rRes = mysqli_stmt_get_result($st);
        while ($rr = mysqli_fetch_assoc($rRes)) {
            $releasedKeys[$rr['broker'] . '|' . $rr['referred_user_uni_id'] . '|' . $rr['month_year']] = true;
            $uid = $rr['referred_user_uni_id'];
            $releasedCommissionByUser[$uid] = ($releasedCommissionByUser[$uid] ?? 0) + (float) $rr['commission'];
        }
        mysqli_stmt_close($st);
    }

    // Referrals
    $trackSql = "SELECT rt.referred_user_uni_id, rt.created_at, u.name
                 FROM referral_tracking rt
                 LEFT JOIN user_credentials u ON rt.referred_user_uni_id = u.uni_id
                 WHERE rt.referrer_uni_id = ?
                 ORDER BY rt.created_at DESC";
    $referrals = [];
    $hasReleasable = false;
    $releasableCommissionTotal = 0.0;
    $totalCommissionEarned = 0.0;

    if ($stmt = mysqli_prepare($conn, $trackSql)) {
        mysqli_stmt_bind_param($stmt, 's', $referrerUniId);
        mysqli_stmt_execute($stmt);
        $rResult = mysqli_stmt_get_result($stmt);
        while ($rRow = mysqli_fetch_assoc($rResult)) {
            $referredUniId = $rRow['referred_user_uni_id'];

            // Account status
            $hasAccounts = false;
            $accStmt = mysqli_prepare($conn, "SELECT 1 FROM accounts WHERE uni_id = ? LIMIT 1");
            if ($accStmt) {
                mysqli_stmt_bind_param($accStmt, 's', $referredUniId);
                mysqli_stmt_execute($accStmt);
                $aRes = mysqli_stmt_get_result($accStmt);
                $hasAccounts = mysqli_fetch_assoc($aRes) !== null;
                mysqli_stmt_close($accStmt);
            }

            // Pull all invoice rows across the three brokers for this user
            $invoices = fetchAllInvoicesForUser($conn, $referredUniId);

            $hasBilling = count($invoices) > 0;
            $paid = false;
            $hasOverdue = false;
            $pendingFee = 0.0;
            $paidFeeTotal = 0.0;
            $unreleasedCommission = 0.0;
            $today = date('Y-m-d');

            // Per-broker breakdown
            $brokerBreakdown = [
                'capital_com' => ['paid_fee' => 0.0, 'pending_fee' => 0.0, 'paid_commission' => 0.0, 'pending_commission' => 0.0, 'released_commission' => 0.0, 'unreleased_commission' => 0.0, 'has_overdue' => false],
                'binance'     => ['paid_fee' => 0.0, 'pending_fee' => 0.0, 'paid_commission' => 0.0, 'pending_commission' => 0.0, 'released_commission' => 0.0, 'unreleased_commission' => 0.0, 'has_overdue' => false],
                'ig'          => ['paid_fee' => 0.0, 'pending_fee' => 0.0, 'paid_commission' => 0.0, 'pending_commission' => 0.0, 'released_commission' => 0.0, 'unreleased_commission' => 0.0, 'has_overdue' => false],
            ];

            foreach ($invoices as $inv) {
                $status = $inv['status'] ?? '';
                $fee    = (float) ($inv['total_fee'] ?? 0);
                $month  = $inv['month_year'] ?? '';
                $broker = $inv['broker'];
                $due    = $inv['due_date'] ?? null;

                if (!isset($brokerBreakdown[$broker])) continue;

                if ($status === 'paid') {
                    $paid = true;
                    $paidFeeTotal += $fee;
                    $brokerBreakdown[$broker]['paid_fee'] += $fee;
                    $invComm = $fee * ($affiliatePct / 100.0);
                    $brokerBreakdown[$broker]['paid_commission'] += $invComm;

                    // Releasable contribution
                    if ($affiliatePct > 0 && $month !== '' && $month !== null) {
                        $key = $broker . '|' . $referredUniId . '|' . $month;
                        if (isset($releasedKeys[$key])) {
                            $brokerBreakdown[$broker]['released_commission'] += $invComm;
                        } else {
                            $releasableCommissionTotal += $invComm;
                            $unreleasedCommission += $invComm;
                            $brokerBreakdown[$broker]['unreleased_commission'] += $invComm;
                            $hasReleasable = true;
                        }
                    }
                } elseif ($status === 'pending') {
                    $pendingFee += $fee;
                    $brokerBreakdown[$broker]['pending_fee'] += $fee;
                    $brokerBreakdown[$broker]['pending_commission'] += $fee * ($affiliatePct / 100.0);
                    if ($due !== null && $due < $today) {
                        $hasOverdue = true;
                        $brokerBreakdown[$broker]['has_overdue'] = true;
                    }
                }
            }

            $paidCommissionTotal = $paidFeeTotal * ($affiliatePct / 100.0);
            $releasedCommission = (float) ($releasedCommissionByUser[$referredUniId] ?? 0);

            // Convert breakdown to a list, only for brokers with any activity
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

            if (!$hasAccounts) {
                $accountStatus = 'new';
            } elseif ($hasOverdue) {
                $accountStatus = 'overdue';
            } else {
                $accountStatus = 'active';
            }

            $referrals[] = [
                'referred_user_uni_id' => $referredUniId,
                'name' => $rRow['name'] ?? 'Unknown',
                'referred_at' => $rRow['created_at'],
                'paid_commission_total' => round($paidCommissionTotal, 2),
                'released_commission_total' => round($releasedCommission, 2),
                'unreleased_commission_total' => round($unreleasedCommission, 2),
                'broker_breakdown' => $brokerBreakdownList,
                'paid' => $paid,
                'pending_fee' => $pendingFee,
                'paid_fee_total' => $paidFeeTotal,
                'has_billing' => $hasBilling,
                'status' => $accountStatus,
            ];

            $totalCommissionEarned += $paidFeeTotal * ($affiliatePct / 100.0);
        }
        mysqli_stmt_close($stmt);
    }

    // Referrer's own billing status (still uses billing_periods only — referrer's own broker activity)
    $referrerOverdue = false;
    $referrerPending = false;
    $overStmt = mysqli_prepare($conn, "SELECT 1 FROM billing_periods WHERE user_id = ? AND due_date IS NOT NULL AND due_date < CURDATE() AND status = 'pending' LIMIT 1");
    if ($overStmt) {
        mysqli_stmt_bind_param($overStmt, 's', $referrerUniId);
        mysqli_stmt_execute($overStmt);
        $oRes = mysqli_stmt_get_result($overStmt);
        $referrerOverdue = mysqli_fetch_assoc($oRes) !== null;
        mysqli_stmt_close($overStmt);
    }
    if (!$referrerOverdue) {
        $pendStmt = mysqli_prepare($conn, "SELECT 1 FROM billing_periods WHERE user_id = ? AND status = 'pending' LIMIT 1");
        if ($pendStmt) {
            mysqli_stmt_bind_param($pendStmt, 's', $referrerUniId);
            mysqli_stmt_execute($pendStmt);
            $pRes = mysqli_stmt_get_result($pendStmt);
            $referrerPending = mysqli_fetch_assoc($pRes) !== null;
            mysqli_stmt_close($pendStmt);
        }
    }

    // has_released: at least one envelope row
    $hasReleased = false;
    $relStmt = mysqli_prepare($conn, "SELECT 1 FROM referrer_payouts WHERE referrer_uni_id = ? LIMIT 1");
    if ($relStmt) {
        mysqli_stmt_bind_param($relStmt, 's', $referrerUniId);
        mysqli_stmt_execute($relStmt);
        $relRes = mysqli_stmt_get_result($relStmt);
        $hasReleased = mysqli_fetch_assoc($relRes) !== null;
        mysqli_stmt_close($relStmt);
    }

    $referrers[] = [
        'referral_id' => (int) $row['referral_id'],
        'code' => $row['code'],
        'user_uni_id' => $referrerUniId,
        'referrer_name' => $row['referrer_name'] ?? 'Unknown',
        'referrer_email' => $row['referrer_email'] ?? '',
        'referrals' => $referrals,
        'referrer_overdue' => $referrerOverdue,
        'referrer_pending' => $referrerPending,
        'affiliate_percentage' => $affiliatePct > 0 ? $affiliatePct : null,
        'total_commission_earned' => round($totalCommissionEarned, 2),
        'has_released' => $hasReleased,
        'has_releasable' => $hasReleasable,
        'releasable_commission_total' => round($releasableCommissionTotal, 2),
    ];
}

ob_end_clean();
echo json_encode([
    'success' => true,
    'referrers' => $referrers,
]);
mysqli_close($conn);
