<?php

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

require_once '../conn.php';

$data = json_decode(file_get_contents('php://input'), true);

if (!isset($data['uni_id'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing uni_id']);
    exit;
}

$uni_id = trim($data['uni_id']);
$name = isset($data['name']) ? trim($data['name']) : '';
$email = isset($data['email']) ? trim($data['email']) : '';

// Validation
if (empty($uni_id)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'uni_id is required']);
    exit;
}

if (empty($name) && empty($email)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'At least one field (name or email) must be provided']);
    exit;
}

// Validate email format if provided
if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid email format']);
    exit;
}

// Check if user exists
$stmt = mysqli_prepare($conn, "SELECT uni_id FROM user_credentials WHERE uni_id = ?");
mysqli_stmt_bind_param($stmt, "s", $uni_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$user = mysqli_fetch_assoc($result);
mysqli_stmt_close($stmt);

if (!$user) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'User not found']);
    exit;
}

// Check if email already exists (if email is being updated)
if (!empty($email)) {
    $stmt = mysqli_prepare($conn, "SELECT uni_id FROM user_credentials WHERE email = ? AND uni_id != ?");
    mysqli_stmt_bind_param($stmt, "ss", $email, $uni_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $existingUser = mysqli_fetch_assoc($result);
    mysqli_stmt_close($stmt);

    if ($existingUser) {
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'Email already exists']);
        exit;
    }
}

// Build update query dynamically
$updateFields = [];
$params = [];
$types = '';

if (!empty($name)) {
    $updateFields[] = "name = ?";
    $params[] = $name;
    $types .= 's';
}

if (!empty($email)) {
    $updateFields[] = "email = ?";
    $params[] = $email;
    $types .= 's';
}

$updateFields[] = "updated_at = NOW()";
$types .= 's'; // for uni_id parameter

$sql = "UPDATE user_credentials SET " . implode(', ', $updateFields) . " WHERE uni_id = ?";
$params[] = $uni_id;

$stmt = mysqli_prepare($conn, $sql);
mysqli_stmt_bind_param($stmt, $types, ...$params);

if (mysqli_stmt_execute($stmt)) {
    mysqli_stmt_close($stmt);
    
    // Fetch updated user data
    $stmt = mysqli_prepare($conn, "SELECT uni_id, name, email, status, created_at FROM user_credentials WHERE uni_id = ?");
    mysqli_stmt_bind_param($stmt, "s", $uni_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $updatedUser = mysqli_fetch_assoc($result);
    mysqli_stmt_close($stmt);
    
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Profile updated successfully',
        'user' => [
            'uni_id' => $updatedUser['uni_id'],
            'name' => $updatedUser['name'],
            'email' => $updatedUser['email'],
            'status' => $updatedUser['status'],
            'created_at' => $updatedUser['created_at'],
        ]
    ]);
} else {
    mysqli_stmt_close($stmt);
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to update profile']);
}

mysqli_close($conn);
?>

