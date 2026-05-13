<?php
/**
 * Admin: Get released payout envelopes for a referrer (with line items).
 * GET ?referrer_uni_id=xxx
 * Returns: payouts[{ id, month_year, total_amount, payout_address, tx_hash, paid_at, status,
 *                    payment_method, proof_file, items[{ broker, month_year, referred_user_uni_id, name, fee_paid, commission }] }]
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

$payouts = [];
$st = mysqli_prepare(
    $conn,
    "SELECT id, month_year, total_amount, payout_address, tx_hash, paid_at, status, payment_method, proof_file
     FROM referrer_payouts WHERE referrer_uni_id = ?
     ORDER BY paid_at DESC, id DESC"
);
if ($st) {
    mysqli_stmt_bind_param($st, 's', $referrerUniId);
    mysqli_stmt_execute($st);
    $res = mysqli_stmt_get_result($st);
    while ($r = mysqli_fetch_assoc($res)) {
        $payouts[] = [
            'id' => (int) $r['id'],
            'month_year' => $r['month_year'] ?? null,
            'total_amount' => (float) ($r['total_amount'] ?? 0),
            'payout_address' => $r['payout_address'] ?? '',
            'tx_hash' => $r['tx_hash'] ?? null,
            'paid_at' => $r['paid_at'] ?? null,
            'status' => $r['status'] ?? 'paid',
            'payment_method' => $r['payment_method'] ?? 'crypto',
            'proof_file' => $r['proof_file'] ?? null,
            'items' => [],
        ];
    }
    mysqli_stmt_close($st);
}

if (count($payouts) > 0) {
    $ids = array_column($payouts, 'id');
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $types = str_repeat('i', count($ids));
    $sql = "SELECT rpi.payout_id, rpi.referred_user_uni_id, rpi.broker, rpi.month_year,
                   rpi.fee_paid, rpi.commission, uc.name
            FROM referrer_payout_items rpi
            LEFT JOIN user_credentials uc ON rpi.referred_user_uni_id = uc.uni_id
            WHERE rpi.payout_id IN ($ph)
            ORDER BY rpi.id ASC";
    $st = mysqli_prepare($conn, $sql);
    if ($st) {
        mysqli_stmt_bind_param($st, $types, ...$ids);
        mysqli_stmt_execute($st);
        $res = mysqli_stmt_get_result($st);
        $byPayoutId = [];
        while ($r = mysqli_fetch_assoc($res)) {
            $byPayoutId[(int) $r['payout_id']][] = [
                'referred_user_uni_id' => $r['referred_user_uni_id'],
                'name' => $r['name'] ?? 'Unknown',
                'broker' => $r['broker'],
                'month_year' => $r['month_year'],
                'fee_paid' => (float) $r['fee_paid'],
                'commission' => (float) $r['commission'],
            ];
        }
        mysqli_stmt_close($st);
        foreach ($payouts as &$p) {
            $p['items'] = $byPayoutId[$p['id']] ?? [];
        }
        unset($p);
    }
}

ob_end_clean();
echo json_encode([
    'success' => true,
    'payouts' => $payouts,
]);
mysqli_close($conn);
