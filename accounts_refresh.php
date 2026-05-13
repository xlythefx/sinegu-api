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
    echo json_encode([
        'success' => false,
        'message' => 'Method not allowed',
    ]);
    exit;
}

$inputRaw = file_get_contents('php://input');
$input = json_decode($inputRaw, true);

if (!is_array($input)) {
    $input = [];
}

$accountId = null;
if (isset($input['account_id'])) {
    $accountId = (int) $input['account_id'];
    if ($accountId <= 0) {
        $accountId = null;
    }
}

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


