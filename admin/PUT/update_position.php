<?php
/**
 * PUT update position (admin active positions) - edit any column on the row.
 * PUT /admin/PUT/update_position.php
 * Body: { "id": 12, "ticker": "GOLD", "price": 3300.00, "increments": 1.5,
 *         "unrealized_pnl": 12.34, "strategy": "VWAP-Deviation", "side": "BUY" }
 *
 * Only the fields present in the body are updated.
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

if (array_key_exists('ticker', $input)) {
    $setParts[] = 'ticker = ?';
    $types .= 's';
    $values[] = (string) $input['ticker'];
}

if (array_key_exists('price', $input)) {
    $setParts[] = 'price = ?';
    $types .= 's';
    $values[] = (string) $input['price'];
}

if (array_key_exists('increments', $input)) {
    $setParts[] = 'increments = ?';
    $types .= 's';
    $values[] = (string) $input['increments'];
}

if (array_key_exists('unrealized_pnl', $input)) {
    $setParts[] = 'unrealized_pnl = ?';
    $types .= 'd';
    $values[] = (float) $input['unrealized_pnl'];
}

if (array_key_exists('strategy', $input)) {
    $setParts[] = 'strategy = ?';
    $types .= 's';
    $values[] = (string) $input['strategy'];
}

if (array_key_exists('side', $input)) {
    $setParts[] = 'side = ?';
    $types .= 's';
    $values[] = (string) $input['side'];
}

if (empty($setParts)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Provide at least one of: ticker, price, increments, unrealized_pnl, strategy, side',
    ]);
    exit();
}

// touch updated_at so the row reflects the admin edit
$setParts[] = 'updated_at = NOW()';

require_once __DIR__ . '/../../conn.php';

if (!$conn) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit();
}

$sql = 'UPDATE positions SET ' . implode(', ', $setParts) . ' WHERE id = ?';
$types .= 'i';
$values[] = $id;

$stmt = mysqli_prepare($conn, $sql);
if (!$stmt) {
    $err = mysqli_error($conn);
    mysqli_close($conn);
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Prepare failed: ' . $err]);
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
    echo json_encode(['success' => false, 'error' => 'Position not found or no change']);
    exit();
}

echo json_encode([
    'success' => true,
    'message' => 'Position updated',
    'id' => $id,
]);
