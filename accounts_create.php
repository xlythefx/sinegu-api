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
    echo json_encode([
        'success' => false,
        'message' => 'Method not allowed',
    ]);
    ob_end_flush();
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

if (!is_array($input)) {
    ob_clean();
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid request body',
    ]);
    ob_end_flush();
    exit;
}

$requiredFields = ['name', 'api_key', 'password'];

foreach ($requiredFields as $field) {
    if (empty($input[$field])) {
        ob_clean();
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => "Field '{$field}' is required",
        ]);
        ob_end_flush();
        exit;
    }
}

$name = (string) $input['name'];
$email = isset($input['email']) ? (string) $input['email'] : '';
$apiKey = (string) $input['api_key'];
$password = (string) $input['password'];
$demo = isset($input['demo']) ? (int) $input['demo'] : 0;
$enabled = isset($input['enabled']) ? (int) $input['enabled'] : 1;
$currency_type = isset($input['currency_type']) && in_array($input['currency_type'], ['EUR', 'USD']) 
    ? $input['currency_type'] 
    : 'USD';
$uni_id = isset($input['uni_id']) ? trim($input['uni_id']) : null;

require_once __DIR__ . '/conn.php';

if (!$conn) {
    ob_clean();
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database connection failed',
    ]);
    ob_end_flush();
    exit;
}

// If same API key already exists (e.g. previously disconnected), restore it instead of inserting
$checkApiKey = mysqli_prepare($conn, "SELECT id FROM accounts WHERE api_key = ? LIMIT 1");
if ($checkApiKey) {
    mysqli_stmt_bind_param($checkApiKey, "s", $apiKey);
    mysqli_stmt_execute($checkApiKey);
    $apiKeyRes = mysqli_stmt_get_result($checkApiKey);
    if ($apiKeyRes && mysqli_num_rows($apiKeyRes) > 0) {
        $existing = mysqli_fetch_assoc($apiKeyRes);
        $existingId = (int) $existing['id'];
        mysqli_stmt_close($checkApiKey);
        if ($uni_id) {
            $upd = mysqli_prepare($conn, "UPDATE accounts SET deleted_at = NULL, enabled = 1, uni_id = ?, name = ?, email = ?, password = ?, demo = ?, currency_type = ? WHERE id = ?");
            if ($upd) mysqli_stmt_bind_param($upd, "ssssisi", $uni_id, $name, $email, $password, $demo, $currency_type, $existingId);
        } else {
            $upd = mysqli_prepare($conn, "UPDATE accounts SET deleted_at = NULL, enabled = 1, name = ?, email = ?, password = ?, demo = ?, currency_type = ? WHERE id = ?");
            if ($upd) mysqli_stmt_bind_param($upd, "sssisi", $name, $email, $password, $demo, $currency_type, $existingId);
        }
        if ($upd && mysqli_stmt_execute($upd)) {
            mysqli_stmt_close($upd);
            mysqli_close($conn);
            ob_clean();
            echo json_encode(['success' => true, 'account_id' => $existingId]);
            ob_end_flush();
            exit;
        }
        if ($upd) mysqli_stmt_close($upd);
        mysqli_close($conn);
        ob_clean();
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to reconnect account']);
        ob_end_flush();
        exit;
    }
    if ($apiKeyRes) mysqli_free_result($apiKeyRes);
    mysqli_stmt_close($checkApiKey);
}

// Check if name already exists (among active accounts only)
$nameEscaped = mysqli_real_escape_string($conn, trim($name));
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

// If uni_id is provided, verify it exists in user_credentials
if ($uni_id) {
    $check_user = mysqli_prepare($conn, "SELECT uni_id FROM user_credentials WHERE uni_id = ?");
    if ($check_user) {
        mysqli_stmt_bind_param($check_user, "s", $uni_id);
        mysqli_stmt_execute($check_user);
        $result = mysqli_stmt_get_result($check_user);

        if (mysqli_num_rows($result) === 0) {
            mysqli_stmt_close($check_user);
            mysqli_close($conn);
            ob_clean();
            http_response_code(404);
            echo json_encode([
                'success' => false,
                'message' => 'User not found',
            ]);
            ob_end_flush();
            exit;
        }
        mysqli_stmt_close($check_user);
    }
}

if ($uni_id) {
    $stmt = mysqli_prepare(
        $conn,
        'INSERT INTO accounts (uni_id, name, email, api_key, password, demo, enabled, currency_type, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())'
    );
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 'sssssiis', $uni_id, $name, $email, $apiKey, $password, $demo, $enabled, $currency_type);
    }
} else {
    $stmt = mysqli_prepare(
        $conn,
        'INSERT INTO accounts (name, email, api_key, password, demo, enabled, currency_type, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())'
    );
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 'ssssiis', $name, $email, $apiKey, $password, $demo, $enabled, $currency_type);
    }
}

if (!$stmt) {
    mysqli_close($conn);
    ob_clean();
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to prepare statement: ' . mysqli_error($conn),
    ]);
    ob_end_flush();
    exit;
}

if (!mysqli_stmt_execute($stmt)) {
    $error = mysqli_error($conn);
    mysqli_stmt_close($stmt);
    mysqli_close($conn);
    ob_clean();
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to create account: ' . $error,
    ]);
    ob_end_flush();
    exit;
}

$newId = mysqli_insert_id($conn);

mysqli_stmt_close($stmt);
mysqli_close($conn);

ob_clean();
echo json_encode([
    'success' => true,
    'account_id' => $newId,
]);
ob_end_flush();

