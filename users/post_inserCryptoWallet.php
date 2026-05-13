<?php

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

require_once __DIR__ . '/../conn.php';

$input = json_decode(file_get_contents('php://input'), true);

if (!is_array($input) || empty(trim($input['uni_id'] ?? ''))) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'uni_id is required']);
    exit;
}

$uni_id = trim($input['uni_id']);
$name = isset($input['name']) ? trim($input['name']) : '';
$network = isset($input['network']) ? trim($input['network']) : '';
$address = isset($input['address']) ? trim($input['address']) : '';

if (empty($name)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'name (Wallet Name) is required']);
    exit;
}

if (empty($network)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'network is required']);
    exit;
}

if (empty($address)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'address is required']);
    exit;
}

// Optional: basic address format (hex-like or bech32)
if (strlen($address) < 20) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'address appears invalid']);
    exit;
}

// Check user exists
$stmt = mysqli_prepare($conn, "SELECT uni_id FROM user_credentials WHERE uni_id = ?");
mysqli_stmt_bind_param($stmt, "s", $uni_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$user = mysqli_fetch_assoc($result);
mysqli_stmt_close($stmt);

if (!$user) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'User not found']);
    exit;
}

$now = date('Y-m-d H:i:s');

$stmt = mysqli_prepare($conn, "INSERT INTO crypto_wallets (uni_id, network, address, name, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)");
mysqli_stmt_bind_param($stmt, "ssssss", $uni_id, $network, $address, $name, $now, $now);

if (!mysqli_stmt_execute($stmt)) {
    $err = mysqli_error($conn);
    mysqli_stmt_close($stmt);
    mysqli_close($conn);
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to save wallet', 'error' => $err]);
    exit;
}

mysqli_stmt_close($stmt);
mysqli_close($conn);

echo json_encode([
    'success' => true,
    'message' => 'Cryptocurrency wallet added successfully',
]);
