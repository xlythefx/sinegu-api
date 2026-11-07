<?php
/**
 * Insert Bybit UNIFIED-wallet transfers (transaction-log TRANSFER_IN / TRANSFER_OUT) into
 * bybit_transactions. Called by fetch_transfers. Skips rows that already exist (same
 * api_key + tran_id). tranId is Bybit's transaction-log id — a STRING, stored as one
 * (VARCHAR(64), migration 101); info carries the log type (TRANSFER_IN / TRANSFER_OUT).
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
$data = json_decode($raw, true, 512, JSON_BIGINT_AS_STRING); // ids stay exact strings
if (!is_array($data) || !isset($data['accounts']) || !is_array($data['accounts'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid body. Expected { "accounts": [ { "api_key", "uni_id", "transfers": [...] } ] }']);
    exit;
}

$inserted = 0;
$skipped = 0;
$ins = "INSERT IGNORE INTO bybit_transactions (api_key, uni_id, type, amount, balance_after, tran_id, currency, transaction_time, info) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
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
        // A STRING (Bybit transaction-log id) — never (int).
        $tranId = isset($t['tranId']) ? substr(trim((string) $t['tranId']), 0, 64) : '';
        if ($tranId === '') {
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
        $info = isset($t['info']) ? substr(trim((string) $t['info']), 0, 64) : null;
        $balanceAfter = null;

        mysqli_stmt_bind_param($stmt, 'sssddssis',
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
