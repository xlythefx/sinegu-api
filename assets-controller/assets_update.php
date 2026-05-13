<?php

// Start output buffering
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

$data = json_decode(file_get_contents('php://input'), true);

if (!isset($data['asset_id']) || !isset($data['ticker']) || !isset($data['max_increments']) || !isset($data['base_size'])) {
    ob_clean();
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Missing required fields: asset_id, ticker, max_increments, and base_size are required',
    ]);
    ob_end_flush();
    exit;
}

$asset_id = (int) $data['asset_id'];
$ticker = trim($data['ticker']);
$max_increments = (int) $data['max_increments'];
$base_size = (float) $data['base_size'];
$enabled = isset($data['enabled']) ? ((int) $data['enabled'] ? 1 : 0) : 1;
$type = isset($data['type']) && $data['type'] !== '' ? trim($data['type']) : null;
$broker = isset($data['broker']) && $data['broker'] !== '' ? trim($data['broker']) : null;
$has_asset_image = array_key_exists('asset_image', $data);
$asset_image = $has_asset_image && $data['asset_image'] !== '' ? trim((string) $data['asset_image']) : null;

// Validation
if ($asset_id < 1) {
    ob_clean();
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'asset_id must be a positive integer',
    ]);
    ob_end_flush();
    exit;
}

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

// Check asset exists
$checkSql = 'SELECT asset_id FROM assets WHERE asset_id = ?';
$checkStmt = mysqli_prepare($conn, $checkSql);
if ($checkStmt) {
    mysqli_stmt_bind_param($checkStmt, 'i', $asset_id);
    mysqli_stmt_execute($checkStmt);
    $checkResult = mysqli_stmt_get_result($checkStmt);

    if (mysqli_num_rows($checkResult) === 0) {
        mysqli_stmt_close($checkStmt);
        mysqli_close($conn);
        ob_clean();
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => 'Asset not found',
        ]);
        ob_end_flush();
        exit;
    }
    mysqli_stmt_close($checkStmt);
}

// Update asset (include asset_image only if provided in payload, so partial updates don't wipe it)
if ($has_asset_image) {
    $updateSql = 'UPDATE assets SET ticker = ?, type = ?, broker = ?, asset_image = ?, max_increments = ?, base_size = ?, enabled = ?, updated_at = NOW() WHERE asset_id = ?';
} else {
    $updateSql = 'UPDATE assets SET ticker = ?, type = ?, broker = ?, max_increments = ?, base_size = ?, enabled = ?, updated_at = NOW() WHERE asset_id = ?';
}
$stmt = mysqli_prepare($conn, $updateSql);

if (!$stmt) {
    mysqli_close($conn);
    ob_clean();
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to prepare statement: ' . mysqli_error($conn),
    ]);
    ob_end_flush();
    exit;
}

if ($has_asset_image) {
    mysqli_stmt_bind_param($stmt, 'ssssidii', $ticker, $type, $broker, $asset_image, $max_increments, $base_size, $enabled, $asset_id);
} else {
    mysqli_stmt_bind_param($stmt, 'sssidii', $ticker, $type, $broker, $max_increments, $base_size, $enabled, $asset_id);
}

if (mysqli_stmt_execute($stmt)) {
    mysqli_stmt_close($stmt);
    if (file_exists(__DIR__ . '/../includes/api_log.php')) {
        require_once __DIR__ . '/../includes/api_log.php';
        api_log($conn, 'assets-controller/assets_update', 'Asset updated successfully', 'update', true);
    }
    mysqli_close($conn);
    
    ob_clean();
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Asset updated successfully',
    ]);
    ob_end_flush();
} else {
    mysqli_stmt_close($stmt);
    if (file_exists(__DIR__ . '/../includes/api_log.php')) {
        require_once __DIR__ . '/../includes/api_log.php';
        api_log($conn, 'assets-controller/assets_update', 'Asset update failed', 'update', false);
    }
    mysqli_close($conn);
    
    ob_clean();
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to update asset: ' . mysqli_error($conn),
    ]);
    ob_end_flush();
}

?>
