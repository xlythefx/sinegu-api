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

require_once '../conn.php';

$data = json_decode(file_get_contents('php://input'), true);

if (!isset($data['uni_id'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'uni_id is required']);
    exit;
}

$uni_id = trim($data['uni_id']);
$accountId = isset($data['account_id']) ? (int)$data['account_id'] : null;

// If account_id is provided, verify it belongs to the user
if ($accountId !== null) {
    $check_account = mysqli_prepare($conn, "SELECT id FROM accounts WHERE id = ? AND uni_id = ?");
    mysqli_stmt_bind_param($check_account, "is", $accountId, $uni_id);
    mysqli_stmt_execute($check_account);
    $result = mysqli_stmt_get_result($check_account);

    if (mysqli_num_rows($result) === 0) {
        mysqli_stmt_close($check_account);
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Account not found or access denied']);
        exit;
    }
    mysqli_stmt_close($check_account);
}

// Prepare payload for balance service
$payload = $accountId !== null ? json_encode(['account_id' => $accountId]) : '{}';

$ch = curl_init('http://127.0.0.1:5000/admin/balances/refresh');
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);
curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);

$responseBody = curl_exec($ch);

if ($responseBody === false) {
    $errorMessage = curl_error($ch);
    curl_close($ch);
    http_response_code(502);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to contact balance service',
        'error' => $errorMessage,
    ]);
    exit;
}

$statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$decoded = json_decode($responseBody, true);

if ($decoded === null) {
    http_response_code(502);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid response from balance service',
        'raw' => $responseBody,
    ]);
    exit;
}

if ($statusCode < 200 || $statusCode >= 300 || (isset($decoded['success']) && $decoded['success'] === false)) {
    http_response_code($statusCode >= 400 ? $statusCode : 502);
    echo json_encode([
        'success' => false,
        'message' => $decoded['message'] ?? 'Unable to refresh balances',
        'details' => $decoded,
    ]);
    exit;
}

echo json_encode($decoded);

mysqli_close($conn);
?>

