<?php
/**
 * Soft-refresh the Binance Flask service.
 *
 * Proxies to the Binance Flask /admin/refresh-assets endpoint (port 5001),
 * which force-refreshes the in-process asset + account caches so a changed
 * base_size / position sizing takes effect immediately — no NSSM restart,
 * no dropped in-flight webhook trades.
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

$ch = curl_init('http://127.0.0.1:5001/admin/refresh-assets');
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_TIMEOUT, 20);
curl_setopt($ch, CURLOPT_POSTFIELDS, '{}');

$responseBody = curl_exec($ch);

if ($responseBody === false) {
    $errorMessage = curl_error($ch);
    curl_close($ch);
    ob_clean();
    http_response_code(502);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to contact Binance Flask on port 5001',
        'error'   => $errorMessage,
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
        'message' => 'Invalid response from Binance Flask',
        'raw'     => $responseBody,
    ]);
    ob_end_flush();
    exit;
}

if ($statusCode < 200 || $statusCode >= 300 || (isset($decoded['success']) && $decoded['success'] === false)) {
    ob_clean();
    http_response_code($statusCode >= 400 ? $statusCode : 502);
    echo json_encode([
        'success' => false,
        'message' => $decoded['error'] ?? 'Unable to refresh Binance Flask',
        'details' => $decoded,
    ]);
    ob_end_flush();
    exit;
}

ob_clean();
echo json_encode($decoded);
ob_end_flush();
