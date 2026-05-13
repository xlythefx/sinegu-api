<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

$uni_id = isset($_GET['uni_id']) ? trim($_GET['uni_id']) : '';
if ($uni_id === '') { http_response_code(400); echo json_encode(['success' => false, 'message' => 'uni_id required']); exit; }

require_once __DIR__ . '/../conn.php';
if (!$conn) { http_response_code(500); echo json_encode(['success' => false, 'message' => 'DB error']); exit; }

$accounts = [];
$st = mysqli_prepare($conn, "SELECT id, label, currency, name, bank_name, bank_address, account_type, routing_number, account_number, swift_bic, iban, is_main, created_at FROM bank_wire_accounts WHERE uni_id = ? ORDER BY is_main DESC, created_at DESC");
if ($st) {
    mysqli_stmt_bind_param($st, 's', $uni_id);
    mysqli_stmt_execute($st);
    $res = mysqli_stmt_get_result($st);
    while ($r = mysqli_fetch_assoc($res)) {
        $accounts[] = $r;
    }
    mysqli_stmt_close($st);
}

echo json_encode(['success' => true, 'accounts' => $accounts]);
mysqli_close($conn);
