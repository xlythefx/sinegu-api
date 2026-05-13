<?php
/**
 * Seed preview rows for dashboard:
 * - ig_positions: uses account_id + api_key
 * - ig_past_positions: uses account_id + api_key
 * - positions: uses account_id (positions has no api_key column)
 *
 * Usage:
 *   php migrations/seeds/run_seed_preview_igpast_and_positions.php
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../../conn.php';

if (!$conn) {
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit;
}

$accountId = 1;
$apiKey = 'fff812b74240535da18c298438a83ce2ce0148ec';

// Validate IG account exists for FK safety.
$chkStmt = mysqli_prepare($conn, "SELECT id FROM ig_accounts WHERE id = ? AND api_key = ? LIMIT 1");
mysqli_stmt_bind_param($chkStmt, "is", $accountId, $apiKey);
mysqli_stmt_execute($chkStmt);
$chkRes = mysqli_stmt_get_result($chkStmt);
if (!$chkRes || mysqli_num_rows($chkRes) === 0) {
    mysqli_stmt_close($chkStmt);
    echo json_encode([
        'success' => false,
        'error' => 'No ig_accounts row matched id=1 with provided api_key',
    ]);
    mysqli_close($conn);
    exit;
}
mysqli_stmt_close($chkStmt);

// Keep this seed idempotent by deleting prior preview rows.
mysqli_query($conn, "DELETE FROM ig_positions WHERE account_id = 1 AND deal_id LIKE 'PREVIEW-IG-OPEN-%'");
mysqli_query($conn, "DELETE FROM ig_past_positions WHERE account_id = 1 AND deal_id LIKE 'PREVIEW-IG-%'");
mysqli_query($conn, "DELETE FROM positions WHERE account_id = 1 AND deal_id LIKE 'PREVIEW-CAP-%'");

// Seed ig_positions (open)
$igOpenRows = [
    ['CS.D.EURUSD.CFD.IP', 'EURUSD', 'PREVIEW-IG-OPEN-001', 'BUY', 1.50, 1.08310, 1.07920, 1.08900, 32.10, 'USD', 'Scalp'],
    ['CS.D.XAUUSD.CFD.IP', 'XAUUSD', 'PREVIEW-IG-OPEN-002', 'SELL', 0.70, 3362.20, 3374.00, 3341.80, -11.30, 'USD', 'Reversal'],
];

$igOpenIns = mysqli_prepare(
    $conn,
    "INSERT INTO ig_positions
    (account_id, api_key, epic, ticker, deal_id, direction, size, level, stop_level, limit_level, unrealized_pnl, currency, strategy, updated_at)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())"
);
if (!$igOpenIns) {
    echo json_encode(['success' => false, 'error' => mysqli_error($conn)]);
    mysqli_close($conn);
    exit;
}
foreach ($igOpenRows as $r) {
    $epic = $r[0];
    $ticker = $r[1];
    $dealId = $r[2];
    $direction = $r[3];
    $size = $r[4];
    $level = $r[5];
    $stopLevel = $r[6];
    $limitLevel = $r[7];
    $upl = $r[8];
    $currency = $r[9];
    $strategy = $r[10];
    mysqli_stmt_bind_param(
        $igOpenIns,
        "isssssdddddss",
        $accountId,
        $apiKey,
        $epic,
        $ticker,
        $dealId,
        $direction,
        $size,
        $level,
        $stopLevel,
        $limitLevel,
        $upl,
        $currency,
        $strategy
    );
    if (!mysqli_stmt_execute($igOpenIns)) {
        $err = mysqli_error($conn);
        mysqli_stmt_close($igOpenIns);
        echo json_encode(['success' => false, 'error' => $err]);
        mysqli_close($conn);
        exit;
    }
}
mysqli_stmt_close($igOpenIns);

// Seed ig_past_positions
$igRows = [
    ['EURUSD', 'CS.D.EURUSD.CFD.IP', 'PREVIEW-IG-001', 'BUY', 1.25, 1.08120, 1.08490, 46.25, 'USD', '2026-04-24 08:45:00', '2026-04-24 10:05:00', 'CLOSE-REF-IG-001', 'Scalp'],
    ['XAUUSD', 'CS.D.XAUUSD.CFD.IP', 'PREVIEW-IG-002', 'SELL', 0.80, 3358.40, 3351.10, 58.40, 'USD', '2026-04-24 09:10:00', '2026-04-24 10:22:00', 'CLOSE-REF-IG-002', 'Reversal'],
];

$igIns = mysqli_prepare(
    $conn,
    "INSERT INTO ig_past_positions
    (account_id, api_key, ticker, epic, deal_id, side, size, open_price, close_price, pnl, currency, opened_at, closed_at, close_deal_reference, strategy)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
);
if (!$igIns) {
    echo json_encode(['success' => false, 'error' => mysqli_error($conn)]);
    mysqli_close($conn);
    exit;
}
foreach ($igRows as $r) {
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
        $igIns,
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
    if (!mysqli_stmt_execute($igIns)) {
        $err = mysqli_error($conn);
        mysqli_stmt_close($igIns);
        echo json_encode(['success' => false, 'error' => $err]);
        mysqli_close($conn);
        exit;
    }
}
mysqli_stmt_close($igIns);

// Seed positions (capital positions table; no api_key column)
$capRows = [
    ['NVDA', 'PREVIEW-CAP-001', 'BUY', 183.23, 100.0000, 1, 'Momentum'],
    ['CRWD', 'PREVIEW-CAP-002', 'SELL', 445.50, -17.7000, 1, 'Breakout'],
    ['AAPL', 'PREVIEW-CAP-003', 'BUY', 262.90, 40.2600, 1, 'Swing'],
];

$capIns = mysqli_prepare(
    $conn,
    "INSERT INTO positions (account_id, ticker, deal_id, side, price, unrealized_pnl, increments, strategy, updated_at)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())"
);
if (!$capIns) {
    echo json_encode(['success' => false, 'error' => mysqli_error($conn)]);
    mysqli_close($conn);
    exit;
}
foreach ($capRows as $r) {
    $ticker = $r[0];
    $dealId = $r[1];
    $side = $r[2];
    $price = $r[3];
    $upl = $r[4];
    $increments = $r[5];
    $strategy = $r[6];
    mysqli_stmt_bind_param($capIns, "isssddis", $accountId, $ticker, $dealId, $side, $price, $upl, $increments, $strategy);
    if (!mysqli_stmt_execute($capIns)) {
        $err = mysqli_error($conn);
        mysqli_stmt_close($capIns);
        echo json_encode(['success' => false, 'error' => $err]);
        mysqli_close($conn);
        exit;
    }
}
mysqli_stmt_close($capIns);

// Return a quick preview payload.
$igPreview = [];
$igRes = mysqli_query($conn, "SELECT id, account_id, api_key, ticker, deal_id, side, pnl, closed_at FROM ig_past_positions WHERE account_id = 1 AND deal_id LIKE 'PREVIEW-IG-%' ORDER BY id DESC LIMIT 5");
if ($igRes) {
    while ($row = mysqli_fetch_assoc($igRes)) {
        $igPreview[] = $row;
    }
    mysqli_free_result($igRes);
}

$capPreview = [];
$capRes = mysqli_query($conn, "SELECT id, account_id, ticker, deal_id, side, price, unrealized_pnl, updated_at FROM positions WHERE account_id = 1 AND deal_id LIKE 'PREVIEW-CAP-%' ORDER BY id DESC LIMIT 5");
if ($capRes) {
    while ($row = mysqli_fetch_assoc($capRes)) {
        $capPreview[] = $row;
    }
    mysqli_free_result($capRes);
}

 $igOpenPreview = [];
$igOpenRes = mysqli_query($conn, "SELECT id, account_id, api_key, ticker, deal_id, direction, unrealized_pnl, updated_at FROM ig_positions WHERE account_id = 1 AND deal_id LIKE 'PREVIEW-IG-OPEN-%' ORDER BY id DESC LIMIT 5");
if ($igOpenRes) {
    while ($row = mysqli_fetch_assoc($igOpenRes)) {
        $igOpenPreview[] = $row;
    }
    mysqli_free_result($igOpenRes);
}

echo json_encode([
    'success' => true,
    'message' => 'Seeded ig_positions + ig_past_positions + positions preview rows',
    'ig_positions_seeded' => count($igOpenRows),
    'ig_past_positions_seeded' => count($igRows),
    'positions_seeded' => count($capRows),
    'note' => 'positions table has no api_key column; seeded using account_id=1',
    'preview' => [
        'ig_positions' => $igOpenPreview,
        'ig_past_positions' => $igPreview,
        'positions' => $capPreview,
    ],
]);
mysqli_close($conn);
