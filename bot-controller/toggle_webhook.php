<?php
/**
 * Toggle Bot Status
 * Updates bot_status in bot_config table (ON/OFF)
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
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($input['enabled']) || !is_bool($input['enabled'])) {
        throw new Exception('enabled parameter is required (boolean)');
    }
    
    // Hardcoded ID as reference
    $botConfigId = 5163321;
    
    // Convert boolean to ON/OFF string
    $botStatus = $input['enabled'] ? 'ON' : 'OFF';
    
    $updateQuery = "UPDATE bot_config 
                    SET bot_status = '$botStatus',
                        updated_at = NOW()
                    WHERE id = $botConfigId";
    
    if (!mysqli_query($conn, $updateQuery)) {
        throw new Exception("Failed to update bot status: " . mysqli_error($conn));
    }
    
    $affectedRows = mysqli_affected_rows($conn);
    
    if ($affectedRows === 0) {
        throw new Exception("No rows updated. Bot config with ID $botConfigId may not exist.");
    }
    
    echo json_encode([
        'success' => true,
        'message' => "Bot status set to $botStatus",
        'bot_status' => $botStatus,
        'enabled' => $input['enabled']
    ]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

mysqli_close($conn);
?>

