<?php
/**
 * Trigger Metrics Refresh API
 * Creates a signal file to trigger the Python script to collect metrics immediately
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

try {
    // Get VPS ID from request (default to 0 for Main Server)
    $vps_id = isset($_POST['vps_id']) ? (int)$_POST['vps_id'] : 0;
    
    // Create signal file in the same directory as the Python script
    $signalFile = __DIR__ . '/refresh_metrics_' . $vps_id . '.signal';
    
    // Create/update the signal file
    if (file_put_contents($signalFile, time()) === false) {
        throw new Exception("Failed to create refresh signal file");
    }
    
    echo json_encode([
        'success' => true,
        'message' => 'Refresh signal sent. Metrics will be collected within 5 seconds.',
        'vps_id' => $vps_id
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>

