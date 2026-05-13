<?php
/**
 * Admin: Delete a referrer payout envelope (undo).
 * POST Body: { "payout_id": 123, "referrer_uni_id": "..." }
 *   - referrer_uni_id is included as a sanity scope guard.
 *   - referrer_payout_items rows cascade-delete via FK.
 */

ob_start();

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ob_end_clean();
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$raw = file_get_contents('php://input');
$input = is_string($raw) ? json_decode($raw, true) : null;

if (!is_array($input)) {
    ob_end_clean();
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid JSON body']);
    exit;
}

$payoutId      = isset($input['payout_id']) ? (int) $input['payout_id'] : 0;
$referrerUniId = isset($input['referrer_uni_id']) ? trim((string) $input['referrer_uni_id']) : '';

if ($payoutId <= 0 || $referrerUniId === '') {
    ob_end_clean();
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'payout_id and referrer_uni_id are required']);
    exit;
}

require_once __DIR__ . '/../../conn.php';

if (!$conn) {
    ob_end_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit;
}

$del = "DELETE FROM referrer_payouts WHERE id = ? AND referrer_uni_id = ? LIMIT 1";
$st = mysqli_prepare($conn, $del);
if (!$st) {
    ob_end_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => mysqli_error($conn)]);
    mysqli_close($conn);
    exit;
}

mysqli_stmt_bind_param($st, 'is', $payoutId, $referrerUniId);
$ok = mysqli_stmt_execute($st);
$affected = mysqli_stmt_affected_rows($st);
mysqli_stmt_close($st);

if (!$ok) {
    ob_end_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Delete failed']);
    mysqli_close($conn);
    exit;
}

ob_end_clean();
echo json_encode(['success' => true, 'deleted' => $affected > 0]);
mysqli_close($conn);
