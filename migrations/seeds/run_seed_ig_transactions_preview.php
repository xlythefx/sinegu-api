<?php
/**
 * Seed preview rows into ig_transactions for one IG account.
 *
 * Usage:
 *   php migrations/seeds/run_seed_ig_transactions_preview.php
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../../conn.php';

if (!$conn) {
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit;
}

$apiKey = 'fff812b74240535da18c298438a83ce2ce0148ec';
$uniId = '25293010-a2b7-4e1d-8e04-e2c3350c8118';

// Resolve account_id from ig_accounts using both api_key and uni_id.
$acctStmt = mysqli_prepare(
    $conn,
    "SELECT id FROM ig_accounts WHERE api_key = ? AND uni_id = ? AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00') LIMIT 1"
);
mysqli_stmt_bind_param($acctStmt, "ss", $apiKey, $uniId);
mysqli_stmt_execute($acctStmt);
$acctRes = mysqli_stmt_get_result($acctStmt);
$acctRow = $acctRes ? mysqli_fetch_assoc($acctRes) : null;
mysqli_stmt_close($acctStmt);

if (!$acctRow) {
    echo json_encode([
        'success' => false,
        'error' => 'No ig_accounts row found for provided api_key + uni_id',
    ]);
    mysqli_close($conn);
    exit;
}

$accountId = (int) $acctRow['id'];

// Idempotent seed: clear old preview references first.
$delStmt = mysqli_prepare(
    $conn,
    "DELETE FROM ig_transactions WHERE account_id = ? AND reference LIKE 'PREVIEW-IG-TX-%'"
);
mysqli_stmt_bind_param($delStmt, "i", $accountId);
mysqli_stmt_execute($delStmt);
mysqli_stmt_close($delStmt);

$rows = [
    // type, amount, currency, reference, transaction_type, transaction_date, instrument_name, profit_and_loss, cash_transaction
    ['DEPOSIT', 1500.00, 'USD', 'PREVIEW-IG-TX-001', 'DEPOSIT', '2026-04-27 10:10:00', null, null, 1],
    ['TRADE', 42.35, 'USD', 'PREVIEW-IG-TX-002', 'CASH_OUT', '2026-04-27 10:35:00', 'EUR/USD', '+$42.35', 0],
    ['WITHDRAWAL', -200.00, 'USD', 'PREVIEW-IG-TX-003', 'WITHDRAWAL', '2026-04-27 11:05:00', null, null, 1],
];

$ins = mysqli_prepare(
    $conn,
    "INSERT INTO ig_transactions
    (account_id, type, amount, currency, reference, transaction_type, transaction_date, instrument_name, profit_and_loss, cash_transaction)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
);
if (!$ins) {
    echo json_encode(['success' => false, 'error' => mysqli_error($conn)]);
    mysqli_close($conn);
    exit;
}

foreach ($rows as $r) {
    $type = $r[0];
    $amount = $r[1];
    $currency = $r[2];
    $reference = $r[3];
    $txType = $r[4];
    $txDate = $r[5];
    $instrument = $r[6];
    $pnl = $r[7];
    $cashTx = $r[8];
    mysqli_stmt_bind_param(
        $ins,
        "isdssssssi",
        $accountId,
        $type,
        $amount,
        $currency,
        $reference,
        $txType,
        $txDate,
        $instrument,
        $pnl,
        $cashTx
    );
    if (!mysqli_stmt_execute($ins)) {
        $err = mysqli_error($conn);
        mysqli_stmt_close($ins);
        echo json_encode(['success' => false, 'error' => $err]);
        mysqli_close($conn);
        exit;
    }
}
mysqli_stmt_close($ins);

$preview = [];
$prevStmt = mysqli_prepare(
    $conn,
    "SELECT id, account_id, type, amount, currency, reference, transaction_type, transaction_date, instrument_name, profit_and_loss, cash_transaction, created_at
     FROM ig_transactions
     WHERE account_id = ? AND reference LIKE 'PREVIEW-IG-TX-%'
     ORDER BY id DESC
     LIMIT 10"
);
mysqli_stmt_bind_param($prevStmt, "i", $accountId);
mysqli_stmt_execute($prevStmt);
$prevRes = mysqli_stmt_get_result($prevStmt);
if ($prevRes) {
    while ($row = mysqli_fetch_assoc($prevRes)) {
        $preview[] = $row;
    }
}
mysqli_stmt_close($prevStmt);

echo json_encode([
    'success' => true,
    'message' => 'Seeded ig_transactions preview rows',
    'account_id' => $accountId,
    'api_key' => $apiKey,
    'uni_id' => $uniId,
    'seeded' => count($rows),
    'preview' => $preview,
]);
mysqli_close($conn);
