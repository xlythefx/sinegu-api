<?php

// Start output buffering
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
    echo json_encode([
        'success' => false,
        'message' => 'Method not allowed',
    ]);
    ob_end_flush();
    exit;
}

// Call Python Flask service to refresh deposit balances
// This uses the same balance refresh endpoint but focuses on deposit_amount
$ch = curl_init('http://127.0.0.1:5000/admin/deposits/refresh');
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_TIMEOUT, 60);
curl_setopt($ch, CURLOPT_POSTFIELDS, '{}');

$responseBody = curl_exec($ch);

if ($responseBody === false) {
    $errorMessage = curl_error($ch);
    curl_close($ch);
    ob_clean();
    http_response_code(502);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to contact deposit refresh service',
        'error' => $errorMessage,
    ]);
    ob_end_flush();
    exit;
}

$statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$decoded = json_decode($responseBody, true);

if ($decoded === null) {
    ob_clean();
    http_response_code(502);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid response from deposit refresh service',
        'raw' => $responseBody,
    ]);
    ob_end_flush();
    exit;
}

if ($statusCode < 200 || $statusCode >= 300 || (isset($decoded['success']) && $decoded['success'] === false)) {
    ob_clean();
    http_response_code($statusCode >= 400 ? $statusCode : 502);
    echo json_encode([
        'success' => false,
        'message' => $decoded['message'] ?? 'Unable to refresh deposit balances',
        'details' => $decoded,
    ]);
    ob_end_flush();
    exit;
}

ob_clean();
echo json_encode($decoded);
ob_end_flush();
?>

