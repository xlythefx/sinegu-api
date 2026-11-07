<?php
/**
 * Bybit twin of update_binance_account.php (the bybit_* tables are column-for-column Binance).
 * Update a Bybit account by current api_key (PK).
 * Required: uni_id, api_key (current PK).
 * Optional: name, new_api_key, secret_key, currency_type.
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
if (!is_array($data)) {
    ob_clean();
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid JSON']);
    ob_end_flush();
    exit;
}

$uni_id      = isset($data['uni_id'])      ? trim((string)$data['uni_id'])     : '';
$api_key     = isset($data['api_key'])     ? trim((string)$data['api_key'])    : '';
$new_api_key = isset($data['new_api_key']) ? trim((string)$data['new_api_key']) : '';
$secret_key  = isset($data['secret_key'])  ? trim((string)$data['secret_key']) : '';
$name        = isset($data['name'])        ? trim((string)$data['name'])       : '';
$currency    = isset($data['currency_type']) ? trim((string)$data['currency_type']) : '';

if ($uni_id === '' || $api_key === '') {
    ob_clean();
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'uni_id and api_key are required']);
    ob_end_flush();
    exit;
}

$setParts = [];
$types = '';
$values = [];

if ($new_api_key !== '') { $setParts[] = 'api_key = ?';      $types .= 's'; $values[] = $new_api_key; }
if ($secret_key  !== '') { $setParts[] = 'secret_key = ?';   $types .= 's'; $values[] = $secret_key; }
if ($name        !== '') { $setParts[] = 'name = ?';         $types .= 's'; $values[] = $name; }
if ($currency    !== '') { $setParts[] = 'currency_type = ?'; $types .= 's'; $values[] = $currency; }

if (empty($setParts)) {
    ob_clean();
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'No fields to update']);
    ob_end_flush();
    exit;
}

$sql = 'UPDATE bybit_accounts SET ' . implode(', ', $setParts) . ' WHERE api_key = ? AND uni_id = ? AND deleted_at IS NULL LIMIT 1';
$types .= 'ss';
$values[] = $api_key;
$values[] = $uni_id;

$stmt = mysqli_prepare($conn, $sql);
if (!$stmt) {
    $err = mysqli_error($conn);
    mysqli_close($conn);
    ob_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to prepare: ' . $err]);
    ob_end_flush();
    exit;
}

mysqli_stmt_bind_param($stmt, $types, ...$values);

if (!mysqli_stmt_execute($stmt)) {
    $err = mysqli_error($conn);
    mysqli_stmt_close($stmt);
    mysqli_close($conn);
    ob_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Update failed: ' . $err]);
    ob_end_flush();
    exit;
}

$affected = mysqli_stmt_affected_rows($stmt);
mysqli_stmt_close($stmt);
mysqli_close($conn);

ob_clean();
if ($affected === 0) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Account not found or no changes']);
} else {
    echo json_encode(['success' => true, 'message' => 'Bybit account updated']);
}
ob_end_flush();
