<?php
/**
 * Create a new IG account row in ig_accounts.
 * POST JSON:
 * {
 *   "uni_id": "...",
 *   "api_key": "...",
 *   "name": "master",
 *   "email": "ig-login@example.com",
 *   "password": "ig-password",
 *   "secret_key": "...",              // optional
 *   "currency_type": "USD",           // optional
 *   "demo": 0,                        // optional
 *   "enabled": 1                      // optional
 * }
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed. Use POST.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid JSON input']);
    exit;
}

require_once __DIR__ . '/../conn.php';
if (!$conn) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit;
}

$uni_id = isset($input['uni_id']) ? trim((string) $input['uni_id']) : '';
$api_key = isset($input['api_key']) ? trim((string) $input['api_key']) : '';
$name = isset($input['name']) ? trim((string) $input['name']) : '';
$email = isset($input['email']) ? trim((string) $input['email']) : '';
$password = isset($input['password']) ? trim((string) $input['password']) : '';
$secret_key = isset($input['secret_key']) ? trim((string) $input['secret_key']) : '';
$currency_type = isset($input['currency_type']) ? trim((string) $input['currency_type']) : 'USD';
$demo = isset($input['demo']) ? (int) !!$input['demo'] : 0;
$enabled = isset($input['enabled']) ? (int) !!$input['enabled'] : 1;

if ($uni_id === '' || $api_key === '' || $name === '' || $email === '' || $password === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing required fields: uni_id, api_key, name, email, password']);
    exit;
}

$stmt = mysqli_prepare(
    $conn,
    "INSERT INTO ig_accounts (uni_id, api_key, secret_key, name, email, password, currency_type, demo, enabled, created_at, updated_at)
     VALUES (?, ?, NULLIF(?, ''), ?, ?, ?, ?, ?, ?, NOW(), NOW())"
);
if (!$stmt) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => mysqli_error($conn)]);
    mysqli_close($conn);
    exit;
}

mysqli_stmt_bind_param($stmt, 'sssssssii', $uni_id, $api_key, $secret_key, $name, $email, $password, $currency_type, $demo, $enabled);
$ok = mysqli_stmt_execute($stmt);
$insert_id = $ok ? mysqli_insert_id($conn) : 0;
mysqli_stmt_close($stmt);

if (!$ok) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => mysqli_error($conn)]);
    mysqli_close($conn);
    exit;
}

echo json_encode([
    'success' => true,
    'message' => 'IG account created successfully',
    'id' => $insert_id,
]);
mysqli_close($conn);
