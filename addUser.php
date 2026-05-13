<?php

// Start output buffering to catch any errors/warnings
ob_start();

// Disable error display and warnings
error_reporting(0);
ini_set('display_errors', 0);
ini_set('xdebug.default_enable', 0);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

require_once 'conn.php';

$data = json_decode(file_get_contents('php://input'), true);

// Validate required fields
if (!isset($data['name']) || !isset($data['email']) || !isset($data['password']) || !isset($data['confirmPassword'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing required fields: name, email, password, and confirmPassword are required']);
    exit;
}

$name = trim($data['name']);
$email = trim($data['email']);
$password = $data['password'];
$confirmPassword = $data['confirmPassword'];
$status = isset($data['status']) ? trim($data['status']) : 'pending';

// Validation
if (empty($name) || empty($email) || empty($password) || empty($confirmPassword)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'All required fields must be filled']);
    exit;
}

// Check if passwords match
if ($password !== $confirmPassword) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Passwords do not match']);
    exit;
}

// Password length validation
if (strlen($password) < 6) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Password must be at least 6 characters']);
    exit;
}

// Email validation
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid email format']);
    exit;
}

// Status validation
if (!in_array($status, ['pending', 'active', 'suspended'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid status. Must be: pending, active, or suspended']);
    exit;
}

// Check if email already exists (bind type: email varchar(255) -> "s")
$check = mysqli_prepare($conn, "SELECT uni_id FROM user_credentials WHERE email = ? LIMIT 1");
if (!$check) {
    ob_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error']);
    mysqli_close($conn);
    exit;
}
mysqli_stmt_bind_param($check, "s", $email);
mysqli_stmt_execute($check);
$result = mysqli_stmt_get_result($check);
if (mysqli_num_rows($result) > 0) {
    mysqli_stmt_close($check);
    if (file_exists(__DIR__ . '/includes/api_log.php')) {
        require_once __DIR__ . '/includes/api_log.php';
        api_log($conn, 'addUser', 'Admin create user failed - email already exists', 'create', false);
    }
    http_response_code(409);
    echo json_encode(['success' => false, 'message' => 'Email already registered']);
    exit;
}
mysqli_stmt_close($check);

// Generate unique ID (UUID v4)
function generateUUID() {
    return sprintf(
        '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
    );
}

$uni_id = generateUUID();

// Hash password
$hashed_password = password_hash($password, PASSWORD_DEFAULT);

// Insert with prepared statement; bind types match user_credentials: uni_id varchar(36), name varchar(255), email varchar(255), password varchar(255), status enum -> "sssss"
$uni_id_s = (string) $uni_id;
$name_s = (string) $name;
$email_s = (string) $email;
$hashed_password_s = (string) $hashed_password;
$status_s = (string) $status;

$insert = mysqli_prepare($conn, "INSERT INTO user_credentials (uni_id, name, email, password, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, NOW(), NOW())");
if (!$insert) {
    ob_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to prepare insert: ' . mysqli_error($conn)]);
    mysqli_close($conn);
    exit;
}
mysqli_stmt_bind_param($insert, "sssss", $uni_id_s, $name_s, $email_s, $hashed_password_s, $status_s);

// Clear any output that might have been generated
ob_clean();

if (mysqli_stmt_execute($insert)) {
    mysqli_stmt_close($insert);
    if (file_exists(__DIR__ . '/includes/api_log.php')) {
        require_once __DIR__ . '/includes/api_log.php';
        api_log($conn, 'addUser', 'Admin create user successful', 'create', true);
    }
    http_response_code(201);
    echo json_encode([
        'success' => true,
        'message' => 'User created successfully',
        'user' => [
            'uni_id' => $uni_id,
            'name' => $name,
            'email' => $email,
            'status' => $status
        ]
    ]);
} else {
    $err = mysqli_stmt_error($insert);
    mysqli_stmt_close($insert);
    if (file_exists(__DIR__ . '/includes/api_log.php')) {
        require_once __DIR__ . '/includes/api_log.php';
        api_log($conn, 'addUser', 'Admin create user failed', 'create', false);
    }
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to create user. Please try again.',
        'error' => $err
    ]);
}

mysqli_close($conn);

// End output buffering and send output
ob_end_flush();
?>

