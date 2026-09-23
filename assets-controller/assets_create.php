<?php

// Start output buffering to prevent any accidental output
ob_start();

// Suppress warnings to prevent HTML output before JSON
error_reporting(E_ALL);
ini_set('display_errors', 0);

// PHP 8.1+ makes mysqli throw by default. A DB-level rejection (e.g. a leftover
// CHECK constraint on base_size / max_increments) would then fatal and return an
// empty body, which the dashboard reports as "Unexpected end of JSON input".
// Turn reporting off so failures fall through to the JSON error responses below.
if (function_exists('mysqli_report')) {
    mysqli_report(MYSQLI_REPORT_OFF);
}

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    ob_clean();
    http_response_code(204);
    ob_end_flush();
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ob_clean();
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Method not allowed',
    ]);
    ob_end_flush();
    exit;
}

require_once __DIR__ . '/../conn.php';

if (!$conn) {
    ob_clean();
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database connection failed',
    ]);
    ob_end_flush();
    exit;
}

$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    ob_clean();
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid JSON: ' . json_last_error_msg(),
    ]);
    ob_end_flush();
    exit;
}

if (!isset($data['ticker']) || !isset($data['max_increments']) || !isset($data['base_size'])) {
    ob_clean();
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Missing required fields: ticker, max_increments, and base_size are required',
    ]);
    ob_end_flush();
    exit;
}

$ticker = trim($data['ticker']);
$max_increments = round((float) $data['max_increments'], 3);
$base_size = (float) $data['base_size'];
$enabled = isset($data['enabled']) ? ((int) $data['enabled'] ? 1 : 0) : 1;
$type = isset($data['type']) && $data['type'] !== '' ? trim($data['type']) : null;
$broker = isset($data['broker']) && $data['broker'] !== '' ? trim($data['broker']) : null;
$side = isset($data['side']) ? strtoupper(trim((string) $data['side'])) : 'ALL';
if (!in_array($side, ['LONG', 'SHORT', 'ALL'], true)) {
    $side = 'ALL';
}
$asset_image = isset($data['asset_image']) && $data['asset_image'] !== '' ? trim($data['asset_image']) : null;

// Per-asset leverage (crypto futures). Omitted or blank stores NULL, which the bots
// read as "no override, use BINANCE_DEFAULT_LEVERAGE" — so a Capital/IG asset, which
// has no leverage concept here, simply never sets it.
$leverage = null;
if (isset($data['leverage']) && $data['leverage'] !== '' && $data['leverage'] !== null) {
    $leverage = (int) $data['leverage'];
    if ($leverage < 1 || $leverage > 125) {
        ob_clean();
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'leverage must be between 1 and 125, or empty to use the bot default',
        ]);
        ob_end_flush();
        exit;
    }
}

// Validation
if (empty($ticker)) {
    ob_clean();
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Ticker cannot be empty',
    ]);
    ob_end_flush();
    exit;
}

// Crypto futures brokers size in coins (fractional); everything else in whole increments.
$minIncrement = in_array(strtolower(trim((string) $broker)), ['binance', 'mexc'], true) ? 0.001 : 1;
if ($max_increments < $minIncrement) {
    ob_clean();
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $minIncrement < 1
            ? 'max_increments must be at least 0.001'
            : 'max_increments must be at least 1',
    ]);
    ob_end_flush();
    exit;
}

if ($base_size <= 0) {
    ob_clean();
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'base_size must be greater than 0',
    ]);
    ob_end_flush();
    exit;
}

// Insert new asset
$insertSql = 'INSERT INTO assets (ticker, type, broker, side, asset_image, max_increments, base_size, leverage, enabled, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())';
$stmt = mysqli_prepare($conn, $insertSql);

if (!$stmt) {
    $errorMsg = mysqli_error($conn);
    mysqli_close($conn);
    ob_clean();
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to prepare statement: ' . $errorMsg,
    ]);
    ob_end_flush();
    exit;
}

mysqli_stmt_bind_param($stmt, 'sssssddii', $ticker, $type, $broker, $side, $asset_image, $max_increments, $base_size, $leverage, $enabled);

if (mysqli_stmt_execute($stmt)) {
    $newAssetId = mysqli_insert_id($conn);
    mysqli_stmt_close($stmt);
    if (file_exists(__DIR__ . '/../includes/api_log.php')) {
        require_once __DIR__ . '/../includes/api_log.php';
        api_log($conn, 'assets-controller/assets_create', 'Asset created successfully', 'create', true);
    }
    if (file_exists(__DIR__ . '/../includes/asset_audit.php')) {
        require_once __DIR__ . '/../includes/asset_audit.php';
        asset_audit_log($conn, $newAssetId, $ticker, 'create', null, [
            'ticker' => $ticker,
            'type' => $type,
            'broker' => $broker,
            'side' => $side,
            'asset_image' => $asset_image,
            'max_increments' => (float) $max_increments,
            'base_size' => (float) $base_size,
            'leverage' => $leverage,
            'enabled' => (int) $enabled,
        ]);
    }
    mysqli_close($conn);

    ob_clean();
    http_response_code(201);
    echo json_encode([
        'success' => true,
        'message' => 'Asset created successfully',
        'asset_id' => (int) $newAssetId,
        'ticker' => $ticker,
    ]);
    ob_end_flush();
} else {
    $errorMsg = mysqli_error($conn);
    mysqli_stmt_close($stmt);
    if (file_exists(__DIR__ . '/../includes/api_log.php')) {
        require_once __DIR__ . '/../includes/api_log.php';
        api_log($conn, 'assets-controller/assets_create', 'Asset create failed', 'create', false);
    }
    mysqli_close($conn);
    
    ob_clean();
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to create asset: ' . $errorMsg,
    ]);
    ob_end_flush();
}

?>

