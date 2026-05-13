<?php
/**
 * Soft delete a Binance account (set deleted_at).
 * Expects POST JSON: { uni_id, api_key }
 */

ob_start();

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
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    ob_end_flush();
    exit;
}

require_once '../conn.php';

if (!$conn) {
    ob_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    ob_end_flush();
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);

if (!$data) {
    mysqli_close($conn);
    ob_clean();
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid JSON']);
    ob_end_flush();
    exit;
}

$uni_id = isset($data['uni_id']) ? trim((string) $data['uni_id']) : '';
$api_key = isset($data['api_key']) ? trim((string) $data['api_key']) : '';

if (empty($uni_id) || empty($api_key)) {
    mysqli_close($conn);
    ob_clean();
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'uni_id and api_key are required']);
    ob_end_flush();
    exit;
}

$stmt = mysqli_prepare($conn, "UPDATE binance_accounts SET deleted_at = NOW() WHERE uni_id = ? AND api_key = ? AND deleted_at IS NULL");

if (!$stmt) {
    $err = mysqli_error($conn);
    mysqli_close($conn);
    ob_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to prepare: ' . $err]);
    ob_end_flush();
    exit;
}

mysqli_stmt_bind_param($stmt, "ss", $uni_id, $api_key);

if (mysqli_stmt_execute($stmt)) {
    $affected = mysqli_stmt_affected_rows($stmt);
    mysqli_stmt_close($stmt);
    mysqli_close($conn);
    ob_clean();
    if ($affected > 0) {
        echo json_encode(['success' => true, 'message' => 'Binance account disconnected']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Account not found or already disconnected']);
    }
    ob_end_flush();
} else {
    $err = mysqli_error($conn);
    mysqli_stmt_close($stmt);
    mysqli_close($conn);
    ob_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed: ' . $err]);
    ob_end_flush();
}
