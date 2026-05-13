<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success' => false, 'message' => 'Method not allowed']); exit; }

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) { http_response_code(400); echo json_encode(['success' => false, 'message' => 'Invalid JSON']); exit; }

$uni_id       = trim($input['uni_id'] ?? '');
$currency     = strtoupper(trim($input['currency'] ?? 'USD'));
$name         = trim($input['name'] ?? '');
$bank_name    = trim($input['bank_name'] ?? '');
$bank_address = trim($input['bank_address'] ?? '');
$label        = trim($input['label'] ?? '');

if ($uni_id === '' || $name === '' || $bank_name === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'uni_id, name, and bank_name are required']);
    exit;
}

// Currency-specific fields
$account_type   = trim($input['account_type'] ?? '');
$routing_number = trim($input['routing_number'] ?? '');
$account_number = trim($input['account_number'] ?? '');
$swift_bic      = trim($input['swift_bic'] ?? '');
$iban           = trim($input['iban'] ?? '');

require_once __DIR__ . '/../conn.php';
if (!$conn) { http_response_code(500); echo json_encode(['success' => false, 'message' => 'DB error']); exit; }

$st = mysqli_prepare($conn,
    "INSERT INTO bank_wire_accounts (uni_id, label, currency, name, bank_name, bank_address, account_type, routing_number, account_number, swift_bic, iban)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
);
if (!$st) { echo json_encode(['success' => false, 'message' => mysqli_error($conn)]); exit; }

mysqli_stmt_bind_param($st, 'sssssssssss',
    $uni_id, $label, $currency, $name, $bank_name, $bank_address,
    $account_type, $routing_number, $account_number, $swift_bic, $iban
);
$ok = mysqli_stmt_execute($st);
$insertId = mysqli_insert_id($conn);
mysqli_stmt_close($st);

echo json_encode(['success' => $ok, 'id' => $insertId, 'message' => $ok ? 'Account saved' : mysqli_error($conn)]);
mysqli_close($conn);
