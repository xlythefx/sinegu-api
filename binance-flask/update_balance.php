<?php
/**
 * Update binance_accounts balance and optionally initial_deposit.
 * Called by fetch_balances. initial_deposit is only updated when current value is NULL or 0.
 *
 * POST JSON: { "accounts": [ { "api_key": "...", "balance": "123.45", "initial_deposit": "123.45" (optional) } ] }
 * Returns: { "success": true, "updated": N } or { "success": false, "error": "..." }
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
    echo json_encode(['success' => false, 'error' => 'Invalid body. Expected { "accounts": [ { "api_key", "balance" } ] }']);
    exit;
}

$updated = 0;
foreach ($data['accounts'] as $acc) {
    $apiKey = isset($acc['api_key']) ? trim((string) $acc['api_key']) : '';
    if ($apiKey === '') {
        continue;
    }
    $balance = isset($acc['balance']) ? (float) $acc['balance'] : 0;
    $initialDeposit = array_key_exists('initial_deposit', $acc) ? (float) $acc['initial_deposit'] : null;
    $unrealizedPnl = array_key_exists('unrealized_pnl', $acc) ? (float) $acc['unrealized_pnl'] : null;

    // Always update balance; include unrealized_pnl when provided
    if ($unrealizedPnl !== null) {
        $stmt = mysqli_prepare($conn, "UPDATE binance_accounts SET balance = ?, unrealized_pnl = ? WHERE api_key = ?");
        if (!$stmt) {
            continue;
        }
        mysqli_stmt_bind_param($stmt, 'dds', $balance, $unrealizedPnl, $apiKey);
    } else {
        $stmt = mysqli_prepare($conn, "UPDATE binance_accounts SET balance = ? WHERE api_key = ?");
        if (!$stmt) {
            continue;
        }
        mysqli_stmt_bind_param($stmt, 'ds', $balance, $apiKey);
    }
    if (mysqli_stmt_execute($stmt) && mysqli_affected_rows($conn) > 0) {
        $updated++;
    }
    mysqli_stmt_close($stmt);

    // Update initial_deposit only when current is NULL or 0 (one-time only)
    if ($initialDeposit !== null) {
        $stmt2 = mysqli_prepare(
            $conn,
            "UPDATE binance_accounts SET initial_deposit = ? WHERE api_key = ? AND (initial_deposit IS NULL OR initial_deposit = 0 OR initial_deposit = 0.00)"
        );
        if ($stmt2) {
            mysqli_stmt_bind_param($stmt2, 'ds', $initialDeposit, $apiKey);
            mysqli_stmt_execute($stmt2);
            mysqli_stmt_close($stmt2);
        }
    }
}

mysqli_close($conn);
echo json_encode(['success' => true, 'updated' => $updated]);
