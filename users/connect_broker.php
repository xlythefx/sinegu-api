<?php

// Start output buffering to prevent any HTML output
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

require_once '../conn.php';

if (!$conn) {
    ob_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    ob_end_flush();
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);

if (!isset($data['uni_id']) || !isset($data['name']) || !isset($data['api_key']) || !isset($data['password'])) {
    ob_clean();
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing required fields']);
    ob_end_flush();
    exit;
}

$uni_id = trim($data['uni_id']);
$name = trim($data['name']);
$api_key = trim($data['api_key']);
$password = trim($data['password']);
$email = isset($data['email']) ? trim($data['email']) : '';
$demo = isset($data['demo']) ? (int)$data['demo'] : 0;
$enabled = isset($data['enabled']) ? (int)$data['enabled'] : 1;
$currency_type = isset($data['currency_type']) && in_array($data['currency_type'], ['EUR', 'USD']) 
    ? $data['currency_type'] 
    : 'USD';

// Validation
if (empty($uni_id) || empty($name) || empty($api_key) || empty($password)) {
    mysqli_close($conn);
    ob_clean();
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'All required fields must be filled']);
    ob_end_flush();
    exit;
}

// If same API key already exists (e.g. previously disconnected), restore it instead of inserting
$checkApiKey = mysqli_prepare($conn, "SELECT id FROM accounts WHERE api_key = ? LIMIT 1");
if ($checkApiKey) {
    mysqli_stmt_bind_param($checkApiKey, "s", $api_key);
    mysqli_stmt_execute($checkApiKey);
    $apiKeyRes = mysqli_stmt_get_result($checkApiKey);
    if ($apiKeyRes && mysqli_num_rows($apiKeyRes) > 0) {
        $existing = mysqli_fetch_assoc($apiKeyRes);
        $existingId = (int) $existing['id'];
        mysqli_stmt_close($checkApiKey);
        $upd = mysqli_prepare($conn, "UPDATE accounts SET deleted_at = NULL, enabled = 1, uni_id = ?, name = ?, email = ?, password = ?, demo = ?, currency_type = ? WHERE id = ?");
        if ($upd) {
            mysqli_stmt_bind_param($upd, "ssssiisi", $uni_id, $name, $email, $password, $demo, $currency_type, $existingId);
            if (mysqli_stmt_execute($upd)) {
                mysqli_stmt_close($upd);
                mysqli_close($conn);
                if (file_exists(__DIR__ . '/../flask/config_bot_listener.php')) {
                    require_once __DIR__ . '/../flask/config_bot_listener.php';
                    $listenerPayload = json_encode(['secret' => $BOT_LISTENER_SECRET]);
                    $ctx = stream_context_create(['http' => ['method' => 'POST', 'header' => "Content-Type: application/json\r\n", 'content' => $listenerPayload, 'timeout' => 2.0]]);
                    @file_get_contents($BOT_LISTENER_URL . '/accounts_updated', false, $ctx);
                }
                ob_clean();
                http_response_code(200);
                echo json_encode(['success' => true, 'message' => 'Broker reconnected successfully', 'account_id' => $existingId]);
                ob_end_flush();
                exit;
            }
            mysqli_stmt_close($upd);
        }
        mysqli_close($conn);
        ob_clean();
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to reconnect broker']);
        ob_end_flush();
        exit;
    }
    if ($apiKeyRes) mysqli_free_result($apiKeyRes);
    mysqli_stmt_close($checkApiKey);
}

// Check if name already exists (among active accounts only)
$nameEscaped = mysqli_real_escape_string($conn, $name);
$checkSql = "SELECT id FROM accounts WHERE name = '$nameEscaped' AND (deleted_at IS NULL) LIMIT 1";
$checkResult = mysqli_query($conn, $checkSql);

if ($checkResult && mysqli_num_rows($checkResult) > 0) {
    mysqli_free_result($checkResult);
    mysqli_close($conn);
    ob_clean();
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Name ID Already Taken',
    ]);
    ob_end_flush();
    exit;
}
if ($checkResult) {
    mysqli_free_result($checkResult);
}

// Verify that uni_id exists in user_credentials
$check_user = mysqli_prepare($conn, "SELECT uni_id FROM user_credentials WHERE uni_id = ?");
if (!$check_user) {
    mysqli_close($conn);
    ob_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to prepare statement: ' . mysqli_error($conn)]);
    ob_end_flush();
    exit;
}

mysqli_stmt_bind_param($check_user, "s", $uni_id);
mysqli_stmt_execute($check_user);
$result = mysqli_stmt_get_result($check_user);

if (mysqli_num_rows($result) === 0) {
    mysqli_stmt_close($check_user);
    mysqli_close($conn);
    ob_clean();
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'User not found']);
    ob_end_flush();
    exit;
}
mysqli_stmt_close($check_user);

// Insert broker account with uni_id
$stmt = mysqli_prepare($conn, "INSERT INTO accounts (uni_id, name, email, api_key, password, demo, enabled, currency_type, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())");
if (!$stmt) {
    mysqli_close($conn);
    ob_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to prepare statement: ' . mysqli_error($conn)]);
    ob_end_flush();
    exit;
}

mysqli_stmt_bind_param($stmt, "sssssiis", $uni_id, $name, $email, $api_key, $password, $demo, $enabled, $currency_type);

if (mysqli_stmt_execute($stmt)) {
    $newId = mysqli_insert_id($conn);
    mysqli_stmt_close($stmt);
    mysqli_close($conn);

    // Notify accounts listener so main.py refreshes accounts (fire-and-forget)
    if (file_exists(__DIR__ . '/../flask/config_bot_listener.php')) {
        require_once __DIR__ . '/../flask/config_bot_listener.php';
        $listenerPayload = json_encode(['secret' => $BOT_LISTENER_SECRET]);
        $ctx = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\n",
                'content' => $listenerPayload,
                'timeout' => 2.0,
            ]
        ]);
        @file_get_contents($BOT_LISTENER_URL . '/accounts_updated', false, $ctx);
    }

    ob_clean();
    http_response_code(201);
    echo json_encode([
        'success' => true,
        'message' => 'Broker connected successfully',
        'account_id' => $newId
    ]);
    ob_end_flush();
} else {
    $error = mysqli_error($conn);
    mysqli_stmt_close($stmt);
    mysqli_close($conn);
    ob_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to connect broker: ' . $error]);
    ob_end_flush();
}
?>

