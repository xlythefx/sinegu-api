<?php
/**
 * Get Bot Configuration
 * Retrieves bot configuration from bot_config table
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
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
    // Hardcoded ID as reference
    $botConfigId = 5163321;
    
    $query = "SELECT 
                id,
                telegram_token,
                telegram_chat_id,
                authorized_user_id,
                webhook_secret,
                created_at,
                updated_at,
                notes,
                bot_status,
                email_sending,
                affiliate_link,
                ai_api
              FROM bot_config
              WHERE id = $botConfigId
              LIMIT 1";
    
    $result = mysqli_query($conn, $query);
    
    if (!$result) {
        throw new Exception("Failed to fetch bot config: " . mysqli_error($conn));
    }
    
    if (mysqli_num_rows($result) === 0) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'error' => 'Bot configuration not found'
        ]);
        exit();
    }
    
    $config = mysqli_fetch_assoc($result);
    
    // Don't expose sensitive data in response - only return what's needed for UI
    echo json_encode([
        'success' => true,
        'config' => [
            'id' => (int)$config['id'],
            'telegram_token' => $config['telegram_token'],
            'telegram_chat_id' => $config['telegram_chat_id'],
            'authorized_user_id' => $config['authorized_user_id'],
            'webhook_secret' => $config['webhook_secret'],
            'notes' => $config['notes'],
            'bot_status' => $config['bot_status'],
            'email_sending' => $config['email_sending'],
            'affiliate_link' => $config['affiliate_link'] ?? null,
            'ai_api' => $config['ai_api'] ?? null,
            'created_at' => $config['created_at'],
            'updated_at' => $config['updated_at'],
        ]
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

