<?php
/**
 * PUT update billing period (admin invoice edit)
 * Edits: total_fee, status (paid | pending | overdue | failed)
 * PUT /admin/PUT/update_billing_period.php
 * Body: { "id": 184, "total_fee": 72.00, "status": "paid" }
 */

ini_set('display_errors', 0);
error_reporting(E_ALL);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: PUT, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

$method = $_SERVER['REQUEST_METHOD'];
if ($method !== 'PUT' && $method !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit();
}

$raw = file_get_contents('php://input');
$input = json_decode($raw, true);

if (!is_array($input) || empty($input['id'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'id is required']);
    exit();
}

$id = (int) $input['id'];
if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Valid id is required']);
    exit();
}

$setParts = [];
$types = '';
$values = [];

// total_fee
if (array_key_exists('total_fee', $input)) {
    $setParts[] = 'total_fee = ?';
    $types .= 'd';
    $values[] = (float) $input['total_fee'];
}

// status: paid | pending | overdue | failed
if (array_key_exists('status', $input)) {
    $status = trim((string) $input['status']);
    $allowed = ['pending', 'paid', 'overdue', 'failed'];
    if (!in_array($status, $allowed, true)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'status must be one of: ' . implode(', ', $allowed)]);
        exit();
    }
    $setParts[] = 'status = ?';
    $types .= 's';
    $values[] = $status;
}

if (empty($setParts)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Provide at least one of: total_fee, status']);
    exit();
}

// Always set updated_at (literal, no placeholder)
$setParts[] = 'updated_at = NOW()';

require_once __DIR__ . '/../../conn.php';

if (!$conn) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit();
}

$sql = 'UPDATE billing_periods SET ' . implode(', ', $setParts) . ' WHERE id = ?';
$types .= 'i';
$values[] = $id;

$stmt = mysqli_prepare($conn, $sql);
if (!$stmt) {
    mysqli_close($conn);
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Prepare failed: ' . mysqli_error($conn)]);
    exit();
}

mysqli_stmt_bind_param($stmt, $types, ...$values);

if (!mysqli_stmt_execute($stmt)) {
    $err = mysqli_error($conn);
    mysqli_stmt_close($stmt);
    mysqli_close($conn);
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Update failed: ' . $err]);
    exit();
}

$affected = mysqli_stmt_affected_rows($stmt);
mysqli_stmt_close($stmt);
mysqli_close($conn);

if ($affected === 0) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Billing period not found or no change']);
    exit();
}

echo json_encode([
    'success' => true,
    'message' => 'Billing period updated',
    'id' => $id,
]);
