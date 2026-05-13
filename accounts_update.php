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

if (!is_array($input) || empty($input['id'])) {
    ob_clean();
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Account id is required',
    ]);
    ob_end_flush();
    exit;
}

$id = (int) $input['id'];

$updatableFields = [
    'name' => 's',
    'email' => 's',
    'api_key' => 's',
    'password' => 's',
    'demo' => 'i',
    'enabled' => 'i',
    'currency_type' => 's',
];

$setParts = [];
$types = '';
$values = [];

foreach ($updatableFields as $field => $type) {
    if (array_key_exists($field, $input)) {
        $setParts[] = "$field = ?";
        $types .= $type;
        if ($type === 'i') {
            $values[] = (int) $input[$field];
        } else {
            $values[] = (string) $input[$field];
        }
    }
}

if (empty($setParts)) {
    ob_clean();
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'No fields to update',
    ]);
    ob_end_flush();
    exit;
}

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

// Check if name is being updated and if it already exists (excluding current account)
if (isset($input['name'])) {
    $name = mysqli_real_escape_string($conn, trim($input['name']));
    $checkSql = "SELECT id FROM accounts WHERE name = '$name' AND id != $id LIMIT 1";
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
}

$sql = 'UPDATE accounts SET ' . implode(', ', $setParts) . ' WHERE id = ?';

$stmt = mysqli_prepare($conn, $sql);

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

$types .= 'i';
$values[] = $id;

mysqli_stmt_bind_param($stmt, $types, ...$values);

if (!mysqli_stmt_execute($stmt)) {
    $error = mysqli_error($conn);
    mysqli_stmt_close($stmt);
    mysqli_close($conn);
    ob_clean();
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to update account: ' . $error,
    ]);
    ob_end_flush();
    exit;
}

mysqli_stmt_close($stmt);
mysqli_close($conn);

ob_clean();
echo json_encode([
    'success' => true,
    'message' => 'Account updated successfully',
]);
ob_end_flush();

