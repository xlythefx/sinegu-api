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

if (!isset($data['id']) || !isset($data['uni_id'])) {
    ob_clean();
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Account id and uni_id are required']);
    ob_end_flush();
    exit;
}

$id = (int)$data['id'];
$uni_id = trim($data['uni_id']);

// Verify that the account belongs to this user
$check_account = mysqli_prepare($conn, "SELECT id FROM accounts WHERE id = ? AND uni_id = ?");
if (!$check_account) {
    mysqli_close($conn);
    ob_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to prepare statement: ' . mysqli_error($conn)]);
    ob_end_flush();
    exit;
}

mysqli_stmt_bind_param($check_account, "is", $id, $uni_id);
mysqli_stmt_execute($check_account);
$result = mysqli_stmt_get_result($check_account);

if (mysqli_num_rows($result) === 0) {
    mysqli_stmt_close($check_account);
    mysqli_close($conn);
    ob_clean();
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Account not found or access denied']);
    ob_end_flush();
    exit;
}
mysqli_stmt_close($check_account);

// Check if name is being updated and if it already exists (excluding current account)
if (isset($data['name'])) {
    $name = mysqli_real_escape_string($conn, trim($data['name']));
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

// Build update query
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
    if (array_key_exists($field, $data)) {
        $setParts[] = "$field = ?";
        $types .= $type;
        if ($type === 'i') {
            $values[] = (int)$data[$field];
        } else {
            $values[] = (string)$data[$field];
        }
    }
}

if (empty($setParts)) {
    mysqli_close($conn);
    ob_clean();
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'No fields to update']);
    ob_end_flush();
    exit;
}

$sql = 'UPDATE accounts SET ' . implode(', ', $setParts) . ' WHERE id = ? AND uni_id = ?';
$types .= 'is';
$values[] = $id;
$values[] = $uni_id;

$stmt = mysqli_prepare($conn, $sql);

if (!$stmt) {
    mysqli_close($conn);
    ob_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to prepare statement: ' . mysqli_error($conn)]);
    ob_end_flush();
    exit;
}

mysqli_stmt_bind_param($stmt, $types, ...$values);

if (!mysqli_stmt_execute($stmt)) {
    $error = mysqli_error($conn);
    mysqli_stmt_close($stmt);
    mysqli_close($conn);
    ob_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to update account: ' . $error]);
    ob_end_flush();
    exit;
}

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
echo json_encode([
    'success' => true,
    'message' => 'Broker updated successfully',
]);
ob_end_flush();

?>

