<?php
/**
 * Sync Individual Account Balance Only (No Positions)
 * 
 * This script:
 * 1. Accepts broker_accountID in the request body
 * 2. Calls the Flask /admin/sync_balance_by_broker_account_id endpoint to sync only balances for that specific account
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'error' => 'Method not allowed'
    ]);
    exit();
}

// Get request body
$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (!$data || (!isset($data['broker_accountID']) && !isset($data['broker_accountid']))) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'broker_accountID is required'
    ]);
    exit();
}

$brokerAccountID = $data['broker_accountID'] ?? $data['broker_accountid'] ?? null;

if (!$brokerAccountID) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'broker_accountID is required'
    ]);
    exit();
}

try {
    // Call Flask /admin/sync_balance_by_broker_account_id endpoint
    $flaskUrl = 'http://127.0.0.1:5000/admin/sync_balance_by_broker_account_id';
    
    $ch = curl_init($flaskUrl);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30); // Shorter timeout for balance-only sync
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['broker_accountID' => $brokerAccountID]));
    
    $responseBody = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    if (curl_errno($ch)) {
        $curlError = curl_error($ch);
        curl_close($ch);
        throw new Exception("Failed to contact Flask service: " . $curlError);
    }
    
    curl_close($ch);
    
    $syncResult = json_decode($responseBody, true);
    
    if ($httpCode < 200 || $httpCode >= 300) {
        throw new Exception("Flask sync failed with HTTP $httpCode: " . ($syncResult['message'] ?? 'Unknown error'));
    }
    
    if (!$syncResult || !isset($syncResult['success'])) {
        throw new Exception("Invalid response from Flask service");
    }
    
    if (!$syncResult['success']) {
        throw new Exception("Flask sync failed: " . ($syncResult['message'] ?? 'Unknown error'));
    }
    
    echo json_encode([
        'success' => true,
        'message' => $syncResult['message'] ?? 'Balance synced successfully',
        'account_name' => $syncResult['account_name'] ?? null,
        'account_id' => $syncResult['account_id'] ?? null,
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>
