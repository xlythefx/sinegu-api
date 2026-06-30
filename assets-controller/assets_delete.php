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

if (!isset($data['asset_id'])) {
    ob_clean();
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'asset_id is required',
    ]);
    ob_end_flush();
    exit;
}

$asset_id = (int) $data['asset_id'];

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

// Check if asset exists
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

// Capture the asset snapshot before deletion for the audit history.
$asset_audit_old = null;
if (file_exists(__DIR__ . '/../includes/asset_audit.php')) {
    require_once __DIR__ . '/../includes/asset_audit.php';
    $asset_audit_old = asset_audit_fetch_snapshot($conn, $asset_id);
}

// Delete asset
$deleteSql = 'DELETE FROM assets WHERE asset_id = ?';
$stmt = mysqli_prepare($conn, $deleteSql);

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

mysqli_stmt_bind_param($stmt, 'i', $asset_id);

if (mysqli_stmt_execute($stmt)) {
    mysqli_stmt_close($stmt);
    if (file_exists(__DIR__ . '/../includes/api_log.php')) {
        require_once __DIR__ . '/../includes/api_log.php';
        api_log($conn, 'assets-controller/assets_delete', 'Asset deleted successfully', 'delete', true);
    }
    if (function_exists('asset_audit_log')) {
        $ticker_for_audit = is_array($asset_audit_old) ? ($asset_audit_old['ticker'] ?? null) : null;
        asset_audit_log($conn, $asset_id, $ticker_for_audit, 'delete', $asset_audit_old, null);
    }
    mysqli_close($conn);
    
    ob_clean();
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Asset deleted successfully',
    ]);
    ob_end_flush();
} else {
    mysqli_stmt_close($stmt);
    if (file_exists(__DIR__ . '/../includes/api_log.php')) {
        require_once __DIR__ . '/../includes/api_log.php';
        api_log($conn, 'assets-controller/assets_delete', 'Asset delete failed', 'delete', false);
    }
    mysqli_close($conn);
    
    ob_clean();
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to delete asset: ' . mysqli_error($conn),
    ]);
    ob_end_flush();
}

?>
