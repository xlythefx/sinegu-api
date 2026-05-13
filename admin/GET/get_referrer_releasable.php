<?php
/**
 * Admin: Get releasable per-invoice payout items for a referrer.
 * GET ?referrer_uni_id=xxx
 *
 * Returns one row per (referred_user, broker, month_year) where:
 *   - the underlying invoice is status='paid'
 *   - the (referrer, referred_user, broker, month_year) tuple has not already been released
 *     (not present in referrer_payout_items)
 *
 * Brokers: capital_com -> billing_periods, binance -> binance_invoices, ig -> ig_invoices.
 *
 * Response:
 * {
 *   success: true,
 *   affiliate_percentage: number,
 *   releasable_items: [
 *     { referred_user_uni_id, name, broker, month_year, fee_paid, commission }
 *   ],
 *   referrer_wallets: [...],
 *   bank_wire_accounts: [...]
 * }
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

$referrerUniId = isset($_GET['referrer_uni_id']) ? trim($_GET['referrer_uni_id']) : '';
if ($referrerUniId === '') {
    ob_end_clean();
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'referrer_uni_id is required']);
    exit;
}

require_once __DIR__ . '/../../conn.php';

if (!$conn) {
    ob_end_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit;
}

// 1) Affiliate percentage
$pct = 0.0;
$st = mysqli_prepare($conn, "SELECT affiliate_percentage FROM user_credentials WHERE uni_id = ? LIMIT 1");
if ($st) {
    mysqli_stmt_bind_param($st, 's', $referrerUniId);
    mysqli_stmt_execute($st);
    $r = mysqli_stmt_get_result($st);
    $row = mysqli_fetch_assoc($r);
    if ($row && $row['affiliate_percentage'] !== null && $row['affiliate_percentage'] !== '') {
        $pct = (float) $row['affiliate_percentage'];
    }
    mysqli_stmt_close($st);
}

// 2) Referred users
$referrals = [];
$st = mysqli_prepare($conn, "SELECT referred_user_uni_id FROM referral_tracking WHERE referrer_uni_id = ?");
if ($st) {
    mysqli_stmt_bind_param($st, 's', $referrerUniId);
    mysqli_stmt_execute($st);
    $res = mysqli_stmt_get_result($st);
    while ($r = mysqli_fetch_assoc($res)) {
        $referrals[] = $r['referred_user_uni_id'];
    }
    mysqli_stmt_close($st);
}

if (count($referrals) === 0) {
    ob_end_clean();
    echo json_encode([
        'success' => true,
        'affiliate_percentage' => $pct,
        'releasable_items' => [],
        'referrer_wallets' => [],
        'bank_wire_accounts' => [],
    ]);
    mysqli_close($conn);
    exit;
}

$placeholders = implode(',', array_fill(0, count($referrals), '?'));
$types = str_repeat('s', count($referrals));

// 3) Already-released (referred_user, broker, month_year) tuples for this referrer
$released = []; // key: "$broker|$user|$month" => true
$st = mysqli_prepare(
    $conn,
    "SELECT referred_user_uni_id, broker, month_year
     FROM referrer_payout_items
     WHERE referrer_uni_id = ?"
);
if ($st) {
    mysqli_stmt_bind_param($st, 's', $referrerUniId);
    mysqli_stmt_execute($st);
    $res = mysqli_stmt_get_result($st);
    while ($r = mysqli_fetch_assoc($res)) {
        $released[$r['broker'] . '|' . $r['referred_user_uni_id'] . '|' . $r['month_year']] = true;
    }
    mysqli_stmt_close($st);
}

// 4) Names lookup
$names = [];
$nameSql = "SELECT uni_id, name FROM user_credentials WHERE uni_id IN ($placeholders)";
$st = mysqli_prepare($conn, $nameSql);
if ($st) {
    mysqli_stmt_bind_param($st, $types, ...$referrals);
    mysqli_stmt_execute($st);
    $res = mysqli_stmt_get_result($st);
    while ($r = mysqli_fetch_assoc($res)) {
        $names[$r['uni_id']] = $r['name'] ?? 'Unknown';
    }
    mysqli_stmt_close($st);
}

// 5) Paid invoices from each of the three broker tables
$brokerTables = [
    'capital_com' => 'billing_periods',
    'binance'     => 'binance_invoices',
    'ig'          => 'ig_invoices',
];

$items = [];

foreach ($brokerTables as $broker => $table) {
    $sql = "SELECT user_id, month_year, SUM(total_fee) AS fee_paid
            FROM $table
            WHERE user_id IN ($placeholders) AND status = 'paid'
              AND month_year IS NOT NULL AND month_year != ''
            GROUP BY user_id, month_year";
    $st = mysqli_prepare($conn, $sql);
    if (!$st) {
        // Table missing or other error -> skip this broker rather than fail the whole request.
        continue;
    }
    mysqli_stmt_bind_param($st, $types, ...$referrals);
    mysqli_stmt_execute($st);
    $res = mysqli_stmt_get_result($st);
    while ($r = mysqli_fetch_assoc($res)) {
        $userId = $r['user_id'];
        $monthYear = $r['month_year'];
        $key = $broker . '|' . $userId . '|' . $monthYear;
        if (isset($released[$key])) {
            continue;
        }
        $feePaid = (float) ($r['fee_paid'] ?? 0);
        if ($feePaid <= 0) {
            continue;
        }
        $commission = $feePaid * ($pct / 100.0);
        if ($commission <= 0) {
            continue;
        }
        $items[] = [
            'referred_user_uni_id' => $userId,
            'name' => $names[$userId] ?? 'Unknown',
            'broker' => $broker,
            'month_year' => $monthYear,
            'fee_paid' => round($feePaid, 2),
            'commission' => round($commission, 2),
        ];
    }
    mysqli_stmt_close($st);
}

// Sort: by name, then month desc, then broker
usort($items, function ($a, $b) {
    $n = strcasecmp($a['name'], $b['name']);
    if ($n !== 0) return $n;
    $m = strcmp($b['month_year'], $a['month_year']);
    if ($m !== 0) return $m;
    return strcmp($a['broker'], $b['broker']);
});

// 6) Crypto wallets
$referrerWallets = [];
$st = mysqli_prepare($conn, "SELECT id, network, address, name, is_main FROM crypto_wallets WHERE uni_id = ? ORDER BY is_main DESC, created_at DESC");
if ($st) {
    mysqli_stmt_bind_param($st, 's', $referrerUniId);
    mysqli_stmt_execute($st);
    $res = mysqli_stmt_get_result($st);
    while ($r = mysqli_fetch_assoc($res)) {
        $referrerWallets[] = [
            'id' => isset($r['id']) ? (int) $r['id'] : 0,
            'network' => $r['network'] ?? '',
            'address' => $r['address'] ?? '',
            'name' => $r['name'] ?? '',
            'is_main' => isset($r['is_main']) ? (int) $r['is_main'] : 0,
        ];
    }
    mysqli_stmt_close($st);
}

// 7) Bank wire accounts
$bankWireAccounts = [];
$st = mysqli_prepare($conn, "SELECT id, label, currency, name, bank_name, bank_address, account_type, routing_number, account_number, swift_bic, iban, is_main FROM bank_wire_accounts WHERE uni_id = ? ORDER BY is_main DESC, created_at DESC");
if ($st) {
    mysqli_stmt_bind_param($st, 's', $referrerUniId);
    mysqli_stmt_execute($st);
    $res = mysqli_stmt_get_result($st);
    while ($r = mysqli_fetch_assoc($res)) {
        $bankWireAccounts[] = $r;
    }
    mysqli_stmt_close($st);
}

ob_end_clean();
echo json_encode([
    'success' => true,
    'affiliate_percentage' => $pct,
    'releasable_items' => $items,
    'referrer_wallets' => $referrerWallets,
    'bank_wire_accounts' => $bankWireAccounts,
]);
mysqli_close($conn);
