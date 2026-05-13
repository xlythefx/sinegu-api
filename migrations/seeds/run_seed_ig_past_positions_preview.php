<?php
/**
 * Seed preview rows into ig_past_positions for one IG account.
 *
 * Usage:
 *   php migrations/seeds/run_seed_ig_past_positions_preview.php
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../../conn.php';

if (!$conn) {
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit;
}

$apiKey = 'fff812b74240535da18c298438a83ce2ce0148ec';
$uniId = '25293010-a2b7-4e1d-8e04-e2c3350c8118';

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

$delStmt = mysqli_prepare(
    $conn,
    "DELETE FROM ig_past_positions WHERE account_id = ? AND deal_id LIKE 'PREVIEW-IG-PAST-%'"
);
mysqli_stmt_bind_param($delStmt, "i", $accountId);
mysqli_stmt_execute($delStmt);
mysqli_stmt_close($delStmt);

$rows = [
    // ticker, epic, deal_id, side, size, open_price, close_price, pnl, currency, opened_at, closed_at, close_deal_reference, strategy
    ['EURUSD', 'CS.D.EURUSD.CFD.IP', 'PREVIEW-IG-PAST-001', 'BUY', 1.20, 1.07840, 1.08290, 54.00, 'USD', '2026-04-27 09:05:00', '2026-04-27 10:15:00', 'PREVIEW-IG-CLOSE-001', 'Scalp'],
    ['XAUUSD', 'CS.D.XAUUSD.CFD.IP', 'PREVIEW-IG-PAST-002', 'SELL', 0.65, 3368.10, 3360.80, 47.45, 'USD', '2026-04-27 10:40:00', '2026-04-27 11:12:00', 'PREVIEW-IG-CLOSE-002', 'Reversal'],
];

$ins = mysqli_prepare(
    $conn,
    "INSERT INTO ig_past_positions
    (account_id, api_key, ticker, epic, deal_id, side, size, open_price, close_price, pnl, currency, opened_at, closed_at, close_deal_reference, strategy)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
);
if (!$ins) {
    echo json_encode(['success' => false, 'error' => mysqli_error($conn)]);
    mysqli_close($conn);
    exit;
}

foreach ($rows as $r) {
    $ticker = $r[0];
    $epic = $r[1];
    $dealId = $r[2];
    $side = $r[3];
    $size = $r[4];
    $openPrice = $r[5];
    $closePrice = $r[6];
    $pnl = $r[7];
    $currency = $r[8];
    $openedAt = $r[9];
    $closedAt = $r[10];
    $closeRef = $r[11];
    $strategy = $r[12];
    mysqli_stmt_bind_param(
        $ins,
        "isssssddddsssss",
        $accountId,
        $apiKey,
        $ticker,
        $epic,
        $dealId,
        $side,
        $size,
        $openPrice,
        $closePrice,
        $pnl,
        $currency,
        $openedAt,
        $closedAt,
        $closeRef,
        $strategy
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
    "SELECT id, account_id, api_key, ticker, epic, deal_id, side, size, open_price, close_price, pnl, currency, opened_at, closed_at, close_deal_reference, strategy, created_at
     FROM ig_past_positions
     WHERE account_id = ? AND deal_id LIKE 'PREVIEW-IG-PAST-%'
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
    'message' => 'Seeded ig_past_positions preview rows',
    'account_id' => $accountId,
    'api_key' => $apiKey,
    'uni_id' => $uniId,
    'seeded' => count($rows),
    'preview' => $preview,
]);
mysqli_close($conn);
