<?php
/**
 * Fetch MEXC assets only (broker = 'mexc', enabled = 1).
 * Tickers are stored in the USDT form the signals use (BTCUSDT); the bot maps
 * them to MEXC's contract symbol (BTC_USDT) itself.
 * Returns JSON: {"success": true, "assets": [...]}
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../conn.php';

if (!$conn) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Database connection failed'
    ]);
    exit;
}

$sql = "SELECT asset_id, ticker, type, broker, max_increments, base_size, leverage, enabled, created_at, updated_at FROM assets WHERE enabled = 1 AND LOWER(TRIM(COALESCE(broker, ''))) = 'mexc' ORDER BY ticker";
$result = mysqli_query($conn, $sql);

if (!$result) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => mysqli_error($conn)
    ]);
    exit;
}

$assets = [];
while ($row = mysqli_fetch_assoc($result)) {
    $assets[] = $row;
}

mysqli_close($conn);

echo json_encode([
    'success' => true,
    'assets' => $assets,
    'count' => count($assets)
]);
