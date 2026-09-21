<?php
/**
 * Insert MEXC positions. Called by mexc-flask src/fetch_positions.py on every tick.
 * DELETE all rows from mexc_positions, then INSERT new rows.
 *
 * The position objects use the BINANCE field names on purpose (positionSide,
 * positionAmt in coins, entryPrice, markPrice, unRealizedProfit, ...): the bot
 * converts MEXC's positionType / holdVol-in-contracts / holdAvgPrice rows before
 * posting, so this file and the table stay identical to the Binance ones.
 *
 * POST JSON: { "accounts": [ { "api_key": "...", "uni_id": "...", "positions": [ Binance-shaped position objs ] } ] }
 * Returns: { "success": true, "inserted": N } or { "success": false, "error": "..." }
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
    echo json_encode(['success' => false, 'error' => 'Invalid body. Expected { "accounts": [ { "api_key", "uni_id", "positions": [...] } ] }']);
    exit;
}

// DELETE all rows
$deleteSql = "DELETE FROM mexc_positions";
if (!mysqli_query($conn, $deleteSql)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Delete failed: ' . mysqli_error($conn)]);
    mysqli_close($conn);
    exit;
}

$inserted = 0;
$ins = "INSERT INTO mexc_positions (api_key, uni_id, symbol, position_side, position_amt, entry_price, mark_price, unrealized_profit, notional, initial_margin, maint_margin, isolated_margin, isolated_wallet, update_time) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
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
    $positions = isset($acc['positions']) && is_array($acc['positions']) ? $acc['positions'] : [];

    if ($apiKey === '' || $uniId === '') {
        continue;
    }

    foreach ($positions as $p) {
        $symbol = isset($p['symbol']) ? trim((string) $p['symbol']) : '';
        $positionSide = isset($p['positionSide']) ? trim((string) $p['positionSide']) : 'BOTH';
        $positionAmt = isset($p['positionAmt']) ? (float) $p['positionAmt'] : 0;
        $entryPrice = isset($p['entryPrice']) ? (float) $p['entryPrice'] : null;
        $markPrice = isset($p['markPrice']) ? (float) $p['markPrice'] : null;
        $unrealizedProfit = isset($p['unRealizedProfit']) ? (float) $p['unRealizedProfit'] : (isset($p['unrealizedProfit']) ? (float) $p['unrealizedProfit'] : null);
        $notional = isset($p['notional']) ? (float) $p['notional'] : null;
        $initialMargin = isset($p['initialMargin']) ? (float) $p['initialMargin'] : null;
        $maintMargin = isset($p['maintMargin']) ? (float) $p['maintMargin'] : null;
        $isolatedMargin = isset($p['isolatedMargin']) ? (float) $p['isolatedMargin'] : null;
        $isolatedWallet = isset($p['isolatedWallet']) ? (float) $p['isolatedWallet'] : null;
        $updateTime = isset($p['updateTime']) ? (int) $p['updateTime'] : null;

        if ($symbol === '') {
            continue;
        }

        mysqli_stmt_bind_param($stmt, 'sssssddddddddi',
            $apiKey, $uniId, $symbol, $positionSide, $positionAmt,
            $entryPrice, $markPrice, $unrealizedProfit, $notional, $initialMargin, $maintMargin, $isolatedMargin, $isolatedWallet, $updateTime
        );
        if (mysqli_stmt_execute($stmt)) {
            $inserted++;
        }
    }
}

mysqli_stmt_close($stmt);
mysqli_close($conn);

echo json_encode(['success' => true, 'inserted' => $inserted]);
