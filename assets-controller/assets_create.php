<?php

// Start output buffering to prevent any accidental output
ob_start();

// Suppress warnings to prevent HTML output before JSON
error_reporting(E_ALL);
ini_set('display_errors', 0);

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
$max_increments = (int) $data['max_increments'];
$base_size = (float) $data['base_size'];
$enabled = isset($data['enabled']) ? ((int) $data['enabled'] ? 1 : 0) : 1;
$type = isset($data['type']) && $data['type'] !== '' ? trim($data['type']) : null;
$broker = isset($data['broker']) && $data['broker'] !== '' ? trim($data['broker']) : null;
$asset_image = isset($data['asset_image']) && $data['asset_image'] !== '' ? trim($data['asset_image']) : null;

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

if ($max_increments < 1) {
    ob_clean();
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'max_increments must be at least 1',
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
$insertSql = 'INSERT INTO assets (ticker, type, broker, asset_image, max_increments, base_size, enabled, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())';
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

mysqli_stmt_bind_param($stmt, 'ssssidi', $ticker, $type, $broker, $asset_image, $max_increments, $base_size, $enabled);

if (mysqli_stmt_execute($stmt)) {
    $newAssetId = mysqli_insert_id($conn);
    mysqli_stmt_close($stmt);
    if (file_exists(__DIR__ . '/../includes/api_log.php')) {
        require_once __DIR__ . '/../includes/api_log.php';
        api_log($conn, 'assets-controller/assets_create', 'Asset created successfully', 'create', true);
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

