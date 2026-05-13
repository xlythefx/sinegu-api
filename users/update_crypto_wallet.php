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
$old_address = isset($input['old_address']) ? trim($input['old_address']) : '';
$name = isset($input['name']) ? trim($input['name']) : '';
$network = isset($input['network']) ? trim($input['network']) : '';
$address = isset($input['address']) ? trim($input['address']) : '';

if (empty($old_address)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'old_address is required to identify the wallet']);
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

if (strlen($address) < 20) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'address appears invalid']);
    exit;
}

if (empty($name)) {
    $name = $network . ' wallet';
}

$stmt = mysqli_prepare($conn, "UPDATE crypto_wallets SET name = ?, network = ?, address = ?, updated_at = ? WHERE uni_id = ? AND address = ?");
$now = date('Y-m-d H:i:s');
mysqli_stmt_bind_param($stmt, "ssssss", $name, $network, $address, $now, $uni_id, $old_address);

if (!mysqli_stmt_execute($stmt)) {
    $err = mysqli_error($conn);
    mysqli_stmt_close($stmt);
    mysqli_close($conn);
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to update wallet', 'error' => $err]);
    exit;
}

$affected = mysqli_affected_rows($conn);
mysqli_stmt_close($stmt);
mysqli_close($conn);

if ($affected === 0) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Wallet not found']);
    exit;
}

echo json_encode([
    'success' => true,
    'message' => 'Wallet updated successfully',
]);
