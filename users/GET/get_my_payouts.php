<?php
/**
 * User: Get released payouts for the current user (referrer).
 * GET ?uni_id=xxx
 * Returns payouts for referrer_uni_id = uni_id only (user sees their own released payments).
 * Response: payouts[{ month_year, total_amount, payout_address, tx_hash, paid_at }]
 */

header('Content-Type: application/json; charset=utf-8');
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

$uni_id = isset($_GET['uni_id']) ? trim($_GET['uni_id']) : '';
if ($uni_id === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'uni_id is required']);
    exit;
}

require_once __DIR__ . '/../../conn.php';

if (!$conn) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit;
}

$payouts = [];
$st = mysqli_prepare($conn, "SELECT month_year, total_amount, payout_address, tx_hash, paid_at, status FROM referrer_payouts WHERE referrer_uni_id = ? ORDER BY month_year DESC");
if ($st) {
    mysqli_stmt_bind_param($st, 's', $uni_id);
    mysqli_stmt_execute($st);
    $res = mysqli_stmt_get_result($st);
    while ($r = mysqli_fetch_assoc($res)) {
        $payouts[] = [
            'month_year' => $r['month_year'] ?? '',
            'total_amount' => (float) ($r['total_amount'] ?? 0),
            'payout_address' => $r['payout_address'] ?? '',
            'tx_hash' => $r['tx_hash'] ?? null,
            'paid_at' => $r['paid_at'] ?? null,
            'status' => $r['status'] ?? 'paid',
        ];
    }
    mysqli_stmt_close($st);
}

echo json_encode([
    'success' => true,
    'payouts' => $payouts,
]);
mysqli_close($conn);
