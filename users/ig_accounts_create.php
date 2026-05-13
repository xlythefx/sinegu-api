<?php
/**
 * POST /users/ig_accounts_create.php
 * Connect a new ig.com account for a user.
 * Required: uni_id, name, api_key, email, password
 * Optional: secret_key, demo (0|1)
 */

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

if (!$data) {
    ob_clean();
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid JSON']);
    ob_end_flush();
    exit;
}

$uni_id    = isset($data['uni_id'])    ? trim((string) $data['uni_id'])    : '';
$name      = isset($data['name'])      ? trim((string) $data['name'])      : '';
$api_key   = isset($data['api_key'])   ? trim((string) $data['api_key'])   : '';
$email     = isset($data['email'])     ? trim((string) $data['email'])     : '';
$password  = isset($data['password'])  ? trim((string) $data['password'])  : '';
$secret_key = isset($data['secret_key']) ? trim((string) $data['secret_key']) : '';
$demo      = isset($data['demo'])      ? (int) $data['demo']               : 0;

if (empty($uni_id) || empty($name) || empty($api_key) || empty($email) || empty($password)) {
    mysqli_close($conn);
    ob_clean();
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'uni_id, name, api_key, email and password are required']);
    ob_end_flush();
    exit;
}

// Check for duplicate api_key for this user
$check = mysqli_prepare($conn, "SELECT id FROM ig_accounts WHERE uni_id = ? AND api_key = ? AND (deleted_at IS NULL) LIMIT 1");
if ($check) {
    mysqli_stmt_bind_param($check, 'ss', $uni_id, $api_key);
    mysqli_stmt_execute($check);
    mysqli_stmt_store_result($check);
    if (mysqli_stmt_num_rows($check) > 0) {
        mysqli_stmt_close($check);
        mysqli_close($conn);
        ob_clean();
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'An ig.com account with this API key already exists for your profile']);
        ob_end_flush();
        exit;
    }
    mysqli_stmt_close($check);
}

$stmt = mysqli_prepare($conn,
    "INSERT INTO ig_accounts (uni_id, name, api_key, email, password, secret_key, balance, initial_deposit, currency_type, demo, enabled, created_at)
     VALUES (?, ?, ?, ?, ?, ?, 0, 0, 'USD', ?, 1, NOW())"
);

if (!$stmt) {
    $err = mysqli_error($conn);
    mysqli_close($conn);
    ob_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to prepare: ' . $err]);
    ob_end_flush();
    exit;
}

mysqli_stmt_bind_param($stmt, 'ssssssi', $uni_id, $name, $api_key, $email, $password, $secret_key, $demo);

if (mysqli_stmt_execute($stmt)) {
    $new_id = mysqli_insert_id($conn);
    mysqli_stmt_close($stmt);
    mysqli_close($conn);
    ob_clean();
    http_response_code(201);
    echo json_encode(['success' => true, 'message' => 'ig.com account connected', 'id' => $new_id]);
    ob_end_flush();
} else {
    $err = mysqli_error($conn);
    mysqli_stmt_close($stmt);
    mysqli_close($conn);
    ob_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to connect: ' . $err]);
    ob_end_flush();
}
