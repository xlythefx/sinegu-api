<?php
/**
 * Update Bot Configuration
 * Updates bot configuration in bot_config table
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
    
    if (!$input) {
        throw new Exception("Invalid JSON data");
    }
    
    // Hardcoded ID as reference
    $botConfigId = 5163321;
    
    // Build update query dynamically based on provided fields
    $updateFields = [];
    $allowedFields = [
        'telegram_token',
        'telegram_chat_id',
        'authorized_user_id',
        'webhook_secret',
        'notes',
        'bot_status',
        'email_sending',
        'affiliate_link',
        'ai_api'
    ];
    
    foreach ($allowedFields as $field) {
        if (isset($input[$field])) {
            $value = mysqli_real_escape_string($conn, $input[$field]);
            $updateFields[] = "$field = '$value'";
        }
    }
    
    if (empty($updateFields)) {
        throw new Exception("No valid fields to update");
    }
    
    // Always update updated_at timestamp
    $updateFields[] = "updated_at = NOW()";
    
    $updateQuery = "UPDATE bot_config 
                    SET " . implode(', ', $updateFields) . "
                    WHERE id = $botConfigId";
    
    if (!mysqli_query($conn, $updateQuery)) {
        throw new Exception("Failed to update bot config: " . mysqli_error($conn));
    }
    
    $affectedRows = mysqli_affected_rows($conn);
    
    if ($affectedRows === 0) {
        throw new Exception("No rows updated. Bot config with ID $botConfigId may not exist.");
    }
    
    echo json_encode([
        'success' => true,
        'message' => 'Bot configuration updated successfully',
        'affected_rows' => $affectedRows
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

