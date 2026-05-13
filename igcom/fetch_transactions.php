<?php
/**
 * Insert/upsert IG.com wallet transactions into ig_transactions.
 * Uses (account_id, reference) as idempotency key — safe to call on repeated fetches.
 *
 * POST JSON: {
 *   "account_id": 1,
 *   "transactions": [
 *     {"type": "DEPOSIT", "amount": 1000.00, "currency": "USD", "reference": "REF123",
 *      "transaction_type": "DEPOSIT", "transaction_date": "2026-04-24T10:00:00",
 *      "instrument_name": "", "profit_and_loss": "", "cash_transaction": true}
 *   ]
 * }
 * Returns JSON: {"success": true, "inserted": N, "skipped": N}
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed. Use POST.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

if (!$input || !isset($input['account_id']) || !isset($input['transactions'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing required fields: account_id and transactions']);
    exit;
}

require_once __DIR__ . '/../conn.php';

if (!$conn) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit;
}

$account_id = intval($input['account_id']);
$transactions = $input['transactions'];
$inserted = 0;
$skipped = 0;

foreach ($transactions as $tx) {
    if (!is_array($tx)) continue;

    $type = isset($tx['type']) ? strtoupper(mysqli_real_escape_string($conn, trim($tx['type']))) : 'UNKNOWN';
    $amount = isset($tx['amount']) ? floatval($tx['amount']) : 0.0;
    $currency = isset($tx['currency']) ? mysqli_real_escape_string($conn, trim($tx['currency'])) : 'USD';
    $reference = isset($tx['reference']) && $tx['reference'] !== '' ? mysqli_real_escape_string($conn, trim($tx['reference'])) : null;
    $transaction_type = isset($tx['transaction_type']) ? mysqli_real_escape_string($conn, trim($tx['transaction_type'])) : null;
    $transaction_date = isset($tx['transaction_date']) && $tx['transaction_date'] !== '' ? mysqli_real_escape_string($conn, substr($tx['transaction_date'], 0, 19)) : null;
    $instrument_name = isset($tx['instrument_name']) ? mysqli_real_escape_string($conn, substr(trim($tx['instrument_name']), 0, 128)) : null;
    $profit_and_loss = isset($tx['profit_and_loss']) ? mysqli_real_escape_string($conn, substr(trim($tx['profit_and_loss']), 0, 64)) : null;
    $cash_transaction = isset($tx['cash_transaction']) ? ($tx['cash_transaction'] ? 1 : 0) : null;

    $ref_sql = $reference ? "'{$reference}'" : 'NULL';
    $tt_sql = $transaction_type ? "'{$transaction_type}'" : 'NULL';
    $td_sql = $transaction_date ? "'{$transaction_date}'" : 'NULL';
    $in_sql = $instrument_name ? "'{$instrument_name}'" : 'NULL';
    $pl_sql = $profit_and_loss ? "'{$profit_and_loss}'" : 'NULL';
    $ct_sql = $cash_transaction !== null ? $cash_transaction : 'NULL';

    $sql = "INSERT INTO ig_transactions
            (account_id, type, amount, currency, reference, transaction_type, transaction_date, instrument_name, profit_and_loss, cash_transaction)
            VALUES ({$account_id}, '{$type}', {$amount}, '{$currency}', {$ref_sql}, {$tt_sql}, {$td_sql}, {$in_sql}, {$pl_sql}, {$ct_sql})
            ON DUPLICATE KEY UPDATE
            type = VALUES(type), amount = VALUES(amount), currency = VALUES(currency),
            transaction_type = VALUES(transaction_type), transaction_date = VALUES(transaction_date),
            instrument_name = VALUES(instrument_name), profit_and_loss = VALUES(profit_and_loss)";

    if (mysqli_query($conn, $sql)) {
        $affected = mysqli_affected_rows($conn);
        if ($affected > 0) {
            $inserted++;
        } else {
            $skipped++;
        }
    } else {
        error_log('igcom/fetch_transactions.php insert error: ' . mysqli_error($conn));
        $skipped++;
    }
}

echo json_encode([
    'success' => true,
    'inserted' => $inserted,
    'skipped' => $skipped,
]);

mysqli_close($conn);
