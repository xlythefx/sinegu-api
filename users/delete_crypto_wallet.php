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

if (!is_array($input) || empty(trim($input['uni_id'] ?? '')) || empty(trim($input['address'] ?? ''))) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'uni_id and address are required']);
    exit;
}

$uni_id = trim($input['uni_id']);
$address = trim($input['address']);

$stmt = mysqli_prepare($conn, "DELETE FROM crypto_wallets WHERE uni_id = ? AND address = ?");
mysqli_stmt_bind_param($stmt, "ss", $uni_id, $address);

if (!mysqli_stmt_execute($stmt)) {
    $err = mysqli_error($conn);
    mysqli_stmt_close($stmt);
    mysqli_close($conn);
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to delete wallet', 'error' => $err]);
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
    'message' => 'Wallet deleted successfully',
]);
