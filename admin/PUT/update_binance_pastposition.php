<?php
/**
 * PUT update Binance past position (admin past positions) - edit price/PNL/strategy/side.
 * PUT /admin/PUT/update_binance_pastposition.php
 * Body uses the dashboard's generic field names, mapped to binance_pastpositions columns:
 *   ticker   -> symbol
 *   price    -> exit_price
 *   pnl      -> realized_pnl
 *   strategy -> strategy
 *   side     -> side
 * Body: { "id": 12, "price": 65000.00, "pnl": 111.00 }
 *
 * Only the fields present in the body are updated. pnl_percentage has no column and is ignored.
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
    $setParts[] = 'symbol = ?';
    $types .= 's';
    $values[] = (string) $input['ticker'];
}

if (array_key_exists('price', $input)) {
    $setParts[] = 'exit_price = ?';
    $types .= 's';
    $values[] = (string) $input['price'];
}

if (array_key_exists('pnl', $input)) {
    $setParts[] = 'realized_pnl = ?';
    $types .= 'd';
    $values[] = (float) $input['pnl'];
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
    echo json_encode(['success' => false, 'error' => 'Provide at least one of: ticker, price, pnl, strategy, side']);
    exit();
}

require_once __DIR__ . '/../../conn.php';

if (!$conn) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit();
}

$sql = 'UPDATE binance_pastpositions SET ' . implode(', ', $setParts) . ' WHERE id = ?';
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
    echo json_encode(['success' => false, 'error' => 'Trade not found or no change']);
    exit();
}

echo json_encode([
    'success' => true,
    'message' => 'Trade updated',
    'id' => $id,
]);
