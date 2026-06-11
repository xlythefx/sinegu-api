<?php
/**
 * Fetch a single binance_positions row's position_amt by (uni_id, symbol, position_side).
 * Used by Flask bot as a fallback when the live Binance API call fails.
 *
 * GET ?uni_id=...&symbol=BTCUSDT&position_side=LONG
 * Returns: { "success": true, "position_amt": <float> }   (0 if no row)
 *          { "success": false, "error": "..." }
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed. Use GET.']);
    exit;
}

require_once __DIR__ . '/../conn.php';

if (!$conn) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit;
}

$uniId = isset($_GET['uni_id']) ? trim((string) $_GET['uni_id']) : '';
$symbol = isset($_GET['symbol']) ? strtoupper(trim((string) $_GET['symbol'])) : '';
$positionSide = isset($_GET['position_side']) ? strtoupper(trim((string) $_GET['position_side'])) : '';

if ($uniId === '' || $symbol === '' || $positionSide === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'uni_id, symbol, and position_side are required']);
    mysqli_close($conn);
    exit;
}

$sql = "SELECT position_amt FROM binance_positions WHERE uni_id = ? AND symbol = ? AND position_side = ? LIMIT 1";
$stmt = mysqli_prepare($conn, $sql);
if (!$stmt) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Prepare failed: ' . mysqli_error($conn)]);
    mysqli_close($conn);
    exit;
}

mysqli_stmt_bind_param($stmt, 'sss', $uniId, $symbol, $positionSide);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$row = $result ? mysqli_fetch_assoc($result) : null;

$positionAmt = $row && isset($row['position_amt']) ? (float) $row['position_amt'] : 0.0;

mysqli_stmt_close($stmt);
mysqli_close($conn);

echo json_encode(['success' => true, 'position_amt' => $positionAmt]);
