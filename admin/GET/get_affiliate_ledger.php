<?php
/**
 * Admin: Full ledger – all rows from referrer_payouts, latest at top.
 * GET /admin/GET/get_affiliate_ledger.php
 * Returns: payouts[{ id, referrer_uni_id, month_year, total_amount, payout_address, tx_hash, paid_at, created_at, referrer_name?, referrer_email? }]
 */

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
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

require_once __DIR__ . '/../../conn.php';

if (!$conn) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit;
}

$sql = "SELECT rp.id, rp.referrer_uni_id, rp.month_year, rp.total_amount, rp.payout_address, rp.tx_hash, rp.paid_at, rp.created_at,
        uc.name AS referrer_name, uc.email AS referrer_email
        FROM referrer_payouts rp
        LEFT JOIN user_credentials uc ON rp.referrer_uni_id = uc.uni_id
        ORDER BY rp.paid_at DESC, rp.id DESC";
$res = mysqli_query($conn, $sql);
$payouts = [];
if ($res) {
    while ($r = mysqli_fetch_assoc($res)) {
        $payouts[] = [
            'id' => (int) ($r['id'] ?? 0),
            'referrer_uni_id' => $r['referrer_uni_id'] ?? '',
            'month_year' => $r['month_year'] ?? '',
            'total_amount' => (float) ($r['total_amount'] ?? 0),
            'payout_address' => $r['payout_address'] ?? '',
            'tx_hash' => $r['tx_hash'] ?? null,
            'paid_at' => $r['paid_at'] ?? null,
            'created_at' => $r['created_at'] ?? null,
            'referrer_name' => $r['referrer_name'] ?? null,
            'referrer_email' => $r['referrer_email'] ?? null,
        ];
    }
}

mysqli_close($conn);

echo json_encode([
    'success' => true,
    'payouts' => $payouts,
]);
