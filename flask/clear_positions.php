<?php
/**
 * Clear all positions for an account (or all accounts if account_id is 0)
 * POST JSON: {"account_id": 1} or {"account_id": 0} to clear all
 * Returns JSON: {"success": true, "deleted_count": 10, "message": "Positions cleared"}
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
    echo json_encode([
        'success' => false,
        'error' => 'Method not allowed. Use POST.'
    ]);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

if (!$input || !isset($input['account_id'])) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Missing required field: account_id'
    ]);
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

$account_id = intval($input['account_id']);

// If account_id is 0, clear all positions
if ($account_id === 0) {
    $sql = "DELETE FROM positions";
    $message = "All positions cleared";
} else {
    $sql = "DELETE FROM positions WHERE account_id = {$account_id}";
    $message = "Positions cleared for account_id {$account_id}";
}

$result = mysqli_query($conn, $sql);

if (!$result) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => mysqli_error($conn)
    ]);
    exit;
}

$deleted_count = mysqli_affected_rows($conn);

echo json_encode([
    'success' => true,
    'deleted_count' => $deleted_count,
    'message' => $message
]);

mysqli_close($conn);
?>

