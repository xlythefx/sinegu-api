<?php
/**
 * POST /users/ig_accounts_update.php
 * Update an existing ig.com account.
 * Required: id, uni_id, name, api_key, email
 * Optional: secret_key, demo
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

$id         = isset($data['id'])         ? (int) $data['id']               : 0;
$uni_id     = isset($data['uni_id'])     ? trim((string) $data['uni_id'])   : '';
$name       = isset($data['name'])       ? trim((string) $data['name'])     : '';
$api_key    = isset($data['api_key'])    ? trim((string) $data['api_key'])  : '';
$email      = isset($data['email'])      ? trim((string) $data['email'])    : '';
$password   = isset($data['password'])   ? trim((string) $data['password']) : '';
$secret_key = isset($data['secret_key']) ? trim((string) $data['secret_key']) : null;
$demo       = isset($data['demo'])       ? (int) $data['demo']              : 0;

if (!$id || empty($uni_id) || empty($name) || empty($api_key) || empty($email)) {
    ob_clean();
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'id, uni_id, name, api_key, and email are required']);
    ob_end_flush();
    exit;
}

if ($secret_key !== null && $secret_key !== '' && $password !== '') {
    $stmt = mysqli_prepare($conn,
        "UPDATE ig_accounts SET name=?, api_key=?, email=?, password=?, secret_key=?, demo=?, updated_at=NOW()
         WHERE id=? AND uni_id=? AND (deleted_at IS NULL) LIMIT 1"
    );
    mysqli_stmt_bind_param($stmt, 'sssssiis', $name, $api_key, $email, $password, $secret_key, $demo, $id, $uni_id);
} elseif ($secret_key !== null && $secret_key !== '' && $password === '') {
    $stmt = mysqli_prepare($conn,
        "UPDATE ig_accounts SET name=?, api_key=?, email=?, secret_key=?, demo=?, updated_at=NOW()
         WHERE id=? AND uni_id=? AND (deleted_at IS NULL) LIMIT 1"
    );
    mysqli_stmt_bind_param($stmt, 'ssssiis', $name, $api_key, $email, $secret_key, $demo, $id, $uni_id);
} elseif (($secret_key === null || $secret_key === '') && $password !== '') {
    $stmt = mysqli_prepare($conn,
        "UPDATE ig_accounts SET name=?, api_key=?, email=?, password=?, demo=?, updated_at=NOW()
         WHERE id=? AND uni_id=? AND (deleted_at IS NULL) LIMIT 1"
    );
    mysqli_stmt_bind_param($stmt, 'ssssiis', $name, $api_key, $email, $password, $demo, $id, $uni_id);
} else {
    $stmt = mysqli_prepare($conn,
        "UPDATE ig_accounts SET name=?, api_key=?, email=?, demo=?, updated_at=NOW()
         WHERE id=? AND uni_id=? AND (deleted_at IS NULL) LIMIT 1"
    );
    mysqli_stmt_bind_param($stmt, 'sssiis', $name, $api_key, $email, $demo, $id, $uni_id);
}

if (!$stmt) {
    $err = mysqli_error($conn);
    mysqli_close($conn);
    ob_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Prepare failed: ' . $err]);
    ob_end_flush();
    exit;
}

if (mysqli_stmt_execute($stmt)) {
    mysqli_stmt_close($stmt);
    mysqli_close($conn);
    ob_clean();
    echo json_encode(['success' => true, 'message' => 'ig.com account updated']);
    ob_end_flush();
} else {
    $err = mysqli_error($conn);
    mysqli_stmt_close($stmt);
    mysqli_close($conn);
    ob_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to update: ' . $err]);
    ob_end_flush();
}
