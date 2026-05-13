<?php
/**
 * Fetch bot configuration by ID
 * Returns JSON: {"success": true, "config": {"telegram_token": "...", "telegram_chat_id": "...", ...}}
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once '../conn.php';

if (!$conn) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Database connection failed'
    ]);
    exit;
}

// Get ID from query parameter, default to 5163321
$config_id = isset($_GET['id']) ? intval($_GET['id']) : 5163321;

// Escape the ID to prevent SQL injection
$config_id = mysqli_real_escape_string($conn, $config_id);

$sql = "SELECT telegram_token, telegram_chat_id, authorized_user_id, webhook_secret, bot_status, notes, ai_api, created_at, updated_at FROM bot_config WHERE id = $config_id LIMIT 1";
$result = mysqli_query($conn, $sql);

if (!$result) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => mysqli_error($conn)
    ]);
    exit;
}

$row = mysqli_fetch_assoc($result);

if (!$row) {
    http_response_code(404);
    echo json_encode([
        'success' => false,
        'error' => "Bot config with ID $config_id not found"
    ]);
    exit;
}

echo json_encode([
    'success' => true,
    'config' => [
        'telegram_token' => $row['telegram_token'] ?? null,
        'telegram_chat_id' => $row['telegram_chat_id'] ?? null,
        'authorized_user_id' => $row['authorized_user_id'] ?? null,
        'webhook_secret' => $row['webhook_secret'] ?? null,
        'bot_status' => $row['bot_status'] ?? null,
        'notes' => $row['notes'] ?? null,
        'ai_api' => $row['ai_api'] ?? null,
        'created_at' => $row['created_at'] ?? null,
        'updated_at' => $row['updated_at'] ?? null
    ],
    'config_id' => $config_id
]);

mysqli_close($conn);
?>

