<?php
/**
 * Refresh Existing Positions
 * 
 * This script:
 * 1. Deletes all rows from the positions table
 * 2. Calls the Flask /admin/sync endpoint to sync and insert existing positions
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

require_once __DIR__ . '/../conn.php';

if (!$conn) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Database connection failed'
    ]);
    exit();
}

try {
    // Step 1: Delete all rows from positions table
    $deleteQuery = "DELETE FROM positions";
    
    if (!mysqli_query($conn, $deleteQuery)) {
        throw new Exception("Failed to delete positions: " . mysqli_error($conn));
    }
    
    $deletedCount = mysqli_affected_rows($conn);
    
    // Step 2: Call Flask /admin/sync endpoint to sync and insert existing positions
    $flaskUrl = 'http://127.0.0.1:5000/admin/sync';
    
    $ch = curl_init($flaskUrl);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60); // Increased timeout for sync operation
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([]));
    
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
    
    // Count newly inserted positions
    $countQuery = "SELECT COUNT(*) as total FROM positions";
    $countResult = mysqli_query($conn, $countQuery);
    $countData = mysqli_fetch_assoc($countResult);
    $newPositionCount = (int)$countData['total'];
    
    echo json_encode([
        'success' => true,
        'message' => 'Positions refreshed successfully',
        'deleted_count' => $deletedCount,
        'synced_positions' => $newPositionCount,
        'accounts' => $syncResult['accounts'] ?? []
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

mysqli_close($conn);
?>

