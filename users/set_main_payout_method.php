<?php
/**
 * Set the user's preferred ("main") payout method.
 *
 * Body (JSON):
 *   { uni_id: string, kind: "crypto" | "bank", id: int }
 *
 * Atomically clears is_main on all of the user's payout methods (across both
 * crypto_wallets and bank_wire_accounts) and sets is_main=1 on the chosen row.
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid JSON body']);
    exit;
}

$uni_id = isset($input['uni_id']) ? trim((string) $input['uni_id']) : '';
$kind   = isset($input['kind']) ? trim((string) $input['kind']) : '';
$id     = isset($input['id']) ? (int) $input['id'] : 0;

if ($uni_id === '' || ($kind !== 'crypto' && $kind !== 'bank') || $id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'uni_id, kind (crypto|bank), and id are required']);
    exit;
}

require_once __DIR__ . '/../conn.php';
if (!$conn) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'DB error']);
    exit;
}

mysqli_begin_transaction($conn);
try {
    // Clear existing main flag across both tables for this user.
    $clearCrypto = mysqli_prepare($conn, "UPDATE crypto_wallets SET is_main = 0 WHERE uni_id = ?");
    mysqli_stmt_bind_param($clearCrypto, 's', $uni_id);
    mysqli_stmt_execute($clearCrypto);
    mysqli_stmt_close($clearCrypto);

    $clearBank = mysqli_prepare($conn, "UPDATE bank_wire_accounts SET is_main = 0 WHERE uni_id = ?");
    mysqli_stmt_bind_param($clearBank, 's', $uni_id);
    mysqli_stmt_execute($clearBank);
    mysqli_stmt_close($clearBank);

    // Set is_main = 1 on the chosen row, scoped to this user.
    $table = $kind === 'crypto' ? 'crypto_wallets' : 'bank_wire_accounts';
    $set = mysqli_prepare($conn, "UPDATE $table SET is_main = 1 WHERE id = ? AND uni_id = ?");
    mysqli_stmt_bind_param($set, 'is', $id, $uni_id);
    mysqli_stmt_execute($set);
    $affected = mysqli_stmt_affected_rows($set);
    mysqli_stmt_close($set);

    if ($affected < 1) {
        throw new RuntimeException('Payout method not found for this user');
    }

    mysqli_commit($conn);
    echo json_encode(['success' => true, 'kind' => $kind, 'id' => $id]);
} catch (Throwable $e) {
    mysqli_rollback($conn);
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
} finally {
    mysqli_close($conn);
}
