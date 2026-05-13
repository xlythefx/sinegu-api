<?php
/**
 * Admin: Release a referrer payout for a selected set of paid invoice items.
 *
 * Two transports supported:
 *   crypto    — JSON body
 *   bank_wire — multipart/form-data (with proof_file upload)
 *
 * Common fields:
 *   referrer_uni_id  : string
 *   items            : array of { referred_user_uni_id, broker, month_year }
 *                      (sent JSON-encoded as a string named "items" when multipart)
 *   total_amount     : number  (client-displayed total, must match server-recomputed sum ±$0.01)
 *   payout_address   : string
 *   tx_hash          : string  (crypto only)
 *   proof_file       : file    (bank_wire only)
 *
 * Behavior:
 *   - Re-derives fee_paid + commission per item server-side from the broker invoice tables.
 *   - Inserts one envelope row in referrer_payouts and N rows in referrer_payout_items.
 *   - Per-item uniqueness on (referrer_uni_id, referred_user_uni_id, broker, month_year)
 *     prevents double-release.
 */

ob_start();

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ob_end_clean();
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
$isMultipart = strpos($contentType, 'multipart/form-data') !== false;

if ($isMultipart) {
    $referrerUniId  = trim($_POST['referrer_uni_id'] ?? '');
    $totalAmount    = (float) ($_POST['total_amount'] ?? 0);
    $payoutAddress  = trim($_POST['payout_address'] ?? '');
    $txHash         = null;
    $paymentMethod  = 'bank_wire';
    $itemsRaw       = $_POST['items'] ?? '';
    $items          = is_string($itemsRaw) ? json_decode($itemsRaw, true) : null;
} else {
    $raw   = file_get_contents('php://input');
    $input = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($input)) {
        ob_end_clean();
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid JSON body']);
        exit;
    }
    $referrerUniId = trim($input['referrer_uni_id'] ?? '');
    $totalAmount   = (float) ($input['total_amount'] ?? 0);
    $payoutAddress = trim($input['payout_address'] ?? '');
    $txHash        = isset($input['tx_hash']) ? trim((string) $input['tx_hash']) : null;
    $paymentMethod = 'crypto';
    $items         = $input['items'] ?? null;
}

if ($referrerUniId === '' || $payoutAddress === '') {
    ob_end_clean();
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'referrer_uni_id and payout_address are required']);
    exit;
}

if (!is_array($items) || count($items) === 0) {
    ob_end_clean();
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'items array is required']);
    exit;
}

$brokerTables = [
    'capital_com' => 'billing_periods',
    'binance'     => 'binance_invoices',
    'ig'          => 'ig_invoices',
];

// Validate item shape and de-duplicate
$normalizedItems = [];
$seenKeys = [];
foreach ($items as $it) {
    if (!is_array($it)) continue;
    $referredUniId = trim($it['referred_user_uni_id'] ?? '');
    $broker        = trim($it['broker'] ?? '');
    $monthYear     = trim($it['month_year'] ?? '');
    if ($referredUniId === '' || $broker === '' || $monthYear === '') {
        ob_end_clean();
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'item missing referred_user_uni_id / broker / month_year']);
        exit;
    }
    if (!isset($brokerTables[$broker])) {
        ob_end_clean();
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => "Unknown broker: $broker"]);
        exit;
    }
    if (!preg_match('/^\d{4}-\d{2}$/', $monthYear)) {
        ob_end_clean();
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'month_year must be YYYY-MM']);
        exit;
    }
    $key = $broker . '|' . $referredUniId . '|' . $monthYear;
    if (isset($seenKeys[$key])) continue;
    $seenKeys[$key] = true;
    $normalizedItems[] = [
        'referred_user_uni_id' => $referredUniId,
        'broker' => $broker,
        'month_year' => $monthYear,
    ];
}

if (count($normalizedItems) === 0) {
    ob_end_clean();
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'No valid items']);
    exit;
}

require_once __DIR__ . '/../../conn.php';

if (!$conn) {
    ob_end_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit;
}

// 1) Affiliate percentage
$pct = 0.0;
$st = mysqli_prepare($conn, "SELECT affiliate_percentage FROM user_credentials WHERE uni_id = ? LIMIT 1");
if ($st) {
    mysqli_stmt_bind_param($st, 's', $referrerUniId);
    mysqli_stmt_execute($st);
    $r = mysqli_stmt_get_result($st);
    $row = mysqli_fetch_assoc($r);
    if ($row && $row['affiliate_percentage'] !== null && $row['affiliate_percentage'] !== '') {
        $pct = (float) $row['affiliate_percentage'];
    }
    mysqli_stmt_close($st);
}

if ($pct <= 0) {
    ob_end_clean();
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Referrer has no affiliate_percentage configured']);
    mysqli_close($conn);
    exit;
}

// 2) Validate referrals belong to this referrer
$referralIds = array_unique(array_map(fn($it) => $it['referred_user_uni_id'], $normalizedItems));
$placeholders = implode(',', array_fill(0, count($referralIds), '?'));
$types = str_repeat('s', count($referralIds) + 1);
$validReferrals = [];
$sql = "SELECT referred_user_uni_id FROM referral_tracking WHERE referrer_uni_id = ? AND referred_user_uni_id IN ($placeholders)";
$st = mysqli_prepare($conn, $sql);
if ($st) {
    $params = array_merge([$referrerUniId], $referralIds);
    mysqli_stmt_bind_param($st, $types, ...$params);
    mysqli_stmt_execute($st);
    $res = mysqli_stmt_get_result($st);
    while ($r = mysqli_fetch_assoc($res)) {
        $validReferrals[$r['referred_user_uni_id']] = true;
    }
    mysqli_stmt_close($st);
}
foreach ($referralIds as $rid) {
    if (!isset($validReferrals[$rid])) {
        ob_end_clean();
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => "Referred user $rid is not under this referrer"]);
        mysqli_close($conn);
        exit;
    }
}

// 3) Re-derive each item's fee_paid from the broker invoice table (status='paid')
//    Reject if already released (existence in referrer_payout_items).
$enrichedItems = [];
$sumCommission = 0.0;
foreach ($normalizedItems as $it) {
    // already released?
    $st = mysqli_prepare(
        $conn,
        "SELECT 1 FROM referrer_payout_items
         WHERE referrer_uni_id = ? AND referred_user_uni_id = ? AND broker = ? AND month_year = ?
         LIMIT 1"
    );
    $alreadyReleased = false;
    if ($st) {
        mysqli_stmt_bind_param($st, 'ssss', $referrerUniId, $it['referred_user_uni_id'], $it['broker'], $it['month_year']);
        mysqli_stmt_execute($st);
        $r = mysqli_stmt_get_result($st);
        $alreadyReleased = mysqli_fetch_assoc($r) !== null;
        mysqli_stmt_close($st);
    }
    if ($alreadyReleased) {
        ob_end_clean();
        http_response_code(409);
        echo json_encode([
            'success' => false,
            'message' => "Item already released: {$it['broker']} {$it['month_year']} for {$it['referred_user_uni_id']}",
        ]);
        mysqli_close($conn);
        exit;
    }

    // Sum paid fee from the broker invoice table for this user+month
    $table = $brokerTables[$it['broker']];
    $sql = "SELECT COALESCE(SUM(total_fee), 0) AS s
            FROM $table
            WHERE user_id = ? AND month_year = ? AND status = 'paid'";
    $feePaid = 0.0;
    $st = mysqli_prepare($conn, $sql);
    if ($st) {
        mysqli_stmt_bind_param($st, 'ss', $it['referred_user_uni_id'], $it['month_year']);
        mysqli_stmt_execute($st);
        $r = mysqli_stmt_get_result($st);
        $row = mysqli_fetch_assoc($r);
        $feePaid = (float) ($row['s'] ?? 0);
        mysqli_stmt_close($st);
    }
    if ($feePaid <= 0) {
        ob_end_clean();
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => "No paid invoice for {$it['broker']} {$it['month_year']} / {$it['referred_user_uni_id']}",
        ]);
        mysqli_close($conn);
        exit;
    }
    $commission = round($feePaid * ($pct / 100.0), 2);
    $sumCommission += $commission;
    $enrichedItems[] = [
        'referred_user_uni_id' => $it['referred_user_uni_id'],
        'broker' => $it['broker'],
        'month_year' => $it['month_year'],
        'fee_paid' => round($feePaid, 2),
        'commission' => $commission,
    ];
}

$serverTotal = round($sumCommission, 2);
if (abs($serverTotal - round($totalAmount, 2)) > 0.01) {
    ob_end_clean();
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => "total_amount mismatch (server=$serverTotal, client=$totalAmount)",
    ]);
    mysqli_close($conn);
    exit;
}

// 4) Handle bank_wire proof upload (after validation so we don't write a file we won't use)
$proofFile = null;
if ($isMultipart && isset($_FILES['proof_file']) && $_FILES['proof_file']['error'] === UPLOAD_ERR_OK) {
    $file    = $_FILES['proof_file'];
    $mime    = mime_content_type($file['tmp_name']);
    $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'application/pdf'];
    if (!in_array($mime, $allowed, true)) {
        ob_end_clean();
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Only images (JPEG/PNG/GIF/WEBP) and PDF are allowed']);
        mysqli_close($conn);
        exit;
    }
    if ($file['size'] > 5 * 1024 * 1024) {
        ob_end_clean();
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'File must be under 5 MB']);
        mysqli_close($conn);
        exit;
    }
    $ext       = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $filename  = 'proof_' . preg_replace('/[^a-z0-9]/', '_', strtolower($referrerUniId)) . '_' . time() . '.' . $ext;
    $uploadDir = __DIR__ . '/../../uploads/bank_proofs/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }
    if (move_uploaded_file($file['tmp_name'], $uploadDir . $filename)) {
        $proofFile = 'uploads/bank_proofs/' . $filename;
    } else {
        ob_end_clean();
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'File upload failed']);
        mysqli_close($conn);
        exit;
    }
}

// 5) Representative month_year: NULL if envelope spans multiple months, else the single month
$months = array_unique(array_map(fn($it) => $it['month_year'], $enrichedItems));
$envelopeMonth = count($months) === 1 ? reset($months) : null;

// 6) Insert envelope + items inside a transaction
mysqli_begin_transaction($conn);

$paidAt = date('Y-m-d H:i:s');

$ins = "INSERT INTO referrer_payouts
        (referrer_uni_id, month_year, total_amount, payout_address, tx_hash, paid_at, payment_method, proof_file)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
$st = mysqli_prepare($conn, $ins);
if (!$st) {
    mysqli_rollback($conn);
    ob_end_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Insert failed: ' . mysqli_error($conn)]);
    mysqli_close($conn);
    exit;
}
mysqli_stmt_bind_param(
    $st,
    'ssdsssss',
    $referrerUniId,
    $envelopeMonth,
    $serverTotal,
    $payoutAddress,
    $txHash,
    $paidAt,
    $paymentMethod,
    $proofFile
);
$ok = mysqli_stmt_execute($st);
if (!$ok) {
    $err = mysqli_error($conn);
    mysqli_stmt_close($st);
    mysqli_rollback($conn);
    ob_end_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Insert failed: ' . $err]);
    mysqli_close($conn);
    exit;
}
$payoutId = mysqli_insert_id($conn);
mysqli_stmt_close($st);

$itemIns = "INSERT INTO referrer_payout_items
            (payout_id, referrer_uni_id, referred_user_uni_id, broker, month_year, fee_paid, commission, released_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
$st = mysqli_prepare($conn, $itemIns);
if (!$st) {
    mysqli_rollback($conn);
    ob_end_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Item insert prepare failed: ' . mysqli_error($conn)]);
    mysqli_close($conn);
    exit;
}

foreach ($enrichedItems as $it) {
    mysqli_stmt_bind_param(
        $st,
        'issssdds',
        $payoutId,
        $referrerUniId,
        $it['referred_user_uni_id'],
        $it['broker'],
        $it['month_year'],
        $it['fee_paid'],
        $it['commission'],
        $paidAt
    );
    if (!mysqli_stmt_execute($st)) {
        $err = mysqli_error($conn);
        mysqli_stmt_close($st);
        mysqli_rollback($conn);
        ob_end_clean();
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'Item insert failed: ' . $err]);
        mysqli_close($conn);
        exit;
    }
}
mysqli_stmt_close($st);

mysqli_commit($conn);

ob_end_clean();
echo json_encode([
    'success' => true,
    'payout_id' => $payoutId,
    'total_amount' => $serverTotal,
    'items_count' => count($enrichedItems),
]);
mysqli_close($conn);
