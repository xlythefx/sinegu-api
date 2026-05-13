<?php

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
    ob_clean();
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid JSON']);
    ob_end_flush();
    exit;
}

$uni_id = isset($data['uni_id']) ? trim((string) $data['uni_id']) : '';
$name = isset($data['name']) ? trim((string) $data['name']) : '';
$api_key = isset($data['api_key']) ? trim((string) $data['api_key']) : '';
$secret_key = isset($data['secret_key']) ? trim((string) $data['secret_key']) : '';

if (empty($uni_id) || empty($name) || empty($api_key) || empty($secret_key)) {
    mysqli_close($conn);
    ob_clean();
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'uni_id, name, api_key and secret_key are required']);
    ob_end_flush();
    exit;
}

$stmt = mysqli_prepare($conn, "INSERT INTO binance_accounts (uni_id, api_key, secret_key, name, balance, initial_deposit, currency_type, created_at, deleted_at) VALUES (?, ?, ?, ?, 0, 0, 'USDT', NOW(), NULL)");

if (!$stmt) {
    $err = mysqli_error($conn);
    mysqli_close($conn);
    ob_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to prepare: ' . $err]);
    ob_end_flush();
    exit;
}

mysqli_stmt_bind_param($stmt, "ssss", $uni_id, $api_key, $secret_key, $name);

if (mysqli_stmt_execute($stmt)) {
    mysqli_stmt_close($stmt);
    mysqli_close($conn);
    ob_clean();
    http_response_code(201);
    echo json_encode(['success' => true, 'message' => 'Binance account connected']);
    ob_end_flush();
} else {
    $err = mysqli_error($conn);
    mysqli_stmt_close($stmt);
    mysqli_close($conn);
    ob_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to connect: ' . $err]);
    ob_end_flush();
}
