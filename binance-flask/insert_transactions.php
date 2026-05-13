<?php
/**
 * Insert Binance wallet transfers into binance_transactions.
 * Called by fetch_transfers. Skips rows that already exist (same api_key + tran_id).
 *
 * POST JSON: { "accounts": [ { "api_key": "...", "uni_id": "...", "transfers": [ { tranId, time, asset, direction, amount, info } ] } ] }
 * Returns: { "success": true, "inserted": N, "skipped": M } or { "success": false, "error": "..." }
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed. Use POST.']);
    exit;
}

require_once __DIR__ . '/../conn.php';

if (!$conn) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit;
}

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data) || !isset($data['accounts']) || !is_array($data['accounts'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid body. Expected { "accounts": [ { "api_key", "uni_id", "transfers": [...] } ] }']);
    exit;
}

$inserted = 0;
$skipped = 0;
$ins = "INSERT IGNORE INTO binance_transactions (api_key, uni_id, type, amount, balance_after, tran_id, currency, transaction_time, info) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
$stmt = mysqli_prepare($conn, $ins);
if (!$stmt) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Prepare failed: ' . mysqli_error($conn)]);
    mysqli_close($conn);
    exit;
}

foreach ($data['accounts'] as $acc) {
    $apiKey = isset($acc['api_key']) ? trim((string) $acc['api_key']) : '';
    $uniId = isset($acc['uni_id']) ? trim((string) $acc['uni_id']) : '';
    $transfers = isset($acc['transfers']) && is_array($acc['transfers']) ? $acc['transfers'] : [];

    if ($apiKey === '' || $uniId === '') {
        continue;
    }

    foreach ($transfers as $t) {
        $tranId = isset($t['tranId']) ? (int) $t['tranId'] : null;
        if ($tranId === null) {
            $skipped++;
            continue;
        }
        $direction = isset($t['direction']) ? trim(strtoupper((string) $t['direction'])) : '';
        $type = ($direction === 'WITHDRAWAL') ? 'WITHDRAWAL' : 'DEPOSIT';
        $amount = isset($t['amount']) ? abs((float) $t['amount']) : 0;
        $currency = isset($t['asset']) ? trim((string) $t['asset']) : 'USDT';
        if ($currency === '') {
            $currency = 'USDT';
        }
        $transactionTime = isset($t['time']) ? (int) $t['time'] : null;
        $info = isset($t['info']) ? trim((string) $t['info']) : null;
        $balanceAfter = null;

        mysqli_stmt_bind_param($stmt, 'sssddissi',
            $apiKey, $uniId, $type, $amount, $balanceAfter, $tranId, $currency, $transactionTime, $info
        );
        if (mysqli_stmt_execute($stmt)) {
            if (mysqli_affected_rows($conn) > 0) {
                $inserted++;
            } else {
                $skipped++;
            }
        }
    }
}

mysqli_stmt_close($stmt);
mysqli_close($conn);

echo json_encode(['success' => true, 'inserted' => $inserted, 'skipped' => $skipped]);
