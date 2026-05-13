<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success' => false, 'message' => 'Method not allowed']); exit; }

$input = json_decode(file_get_contents('php://input'), true);
$uni_id = trim($input['uni_id'] ?? '');
$id     = (int)($input['id'] ?? 0);

if ($uni_id === '' || $id <= 0) { http_response_code(400); echo json_encode(['success' => false, 'message' => 'uni_id and id required']); exit; }

require_once __DIR__ . '/../conn.php';
if (!$conn) { http_response_code(500); echo json_encode(['success' => false, 'message' => 'DB error']); exit; }

$st = mysqli_prepare($conn, "DELETE FROM bank_wire_accounts WHERE id = ? AND uni_id = ?");
if (!$st) { echo json_encode(['success' => false, 'message' => mysqli_error($conn)]); exit; }
mysqli_stmt_bind_param($st, 'is', $id, $uni_id);
$ok = mysqli_stmt_execute($st);
$affected = mysqli_stmt_affected_rows($st);
mysqli_stmt_close($st);

echo json_encode(['success' => $ok && $affected > 0, 'message' => $ok && $affected > 0 ? 'Deleted' : 'Not found or not yours']);
mysqli_close($conn);
