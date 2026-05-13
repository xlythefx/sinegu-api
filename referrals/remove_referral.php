<?php
/**
 * Remove a referral from the referrer's network (delete from referral_tracking).
 * POST: { "referrer_uni_id": "...", "referred_user_uni_id": "..." }
 * Only the referrer can remove their own referral link.
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input) || empty(trim($input['referrer_uni_id'] ?? '')) || empty(trim($input['referred_user_uni_id'] ?? ''))) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'referrer_uni_id and referred_user_uni_id are required']);
    exit;
}

$referrerUniId = trim($input['referrer_uni_id']);
$referredUserUniId = trim($input['referred_user_uni_id']);

require_once __DIR__ . '/../conn.php';
if (!$conn) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit;
}

$delSql = 'DELETE FROM referral_tracking WHERE referrer_uni_id = ? AND referred_user_uni_id = ?';
if (!($stmt = mysqli_prepare($conn, $delSql))) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => mysqli_error($conn)]);
    mysqli_close($conn);
    exit;
}
mysqli_stmt_bind_param($stmt, 'ss', $referrerUniId, $referredUserUniId);
$ok = mysqli_stmt_execute($stmt);
$affected = mysqli_stmt_affected_rows($stmt);
mysqli_stmt_close($stmt);
mysqli_close($conn);

if (!$ok) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to remove referral']);
    exit;
}

echo json_encode([
    'success' => true,
    'message' => $affected > 0 ? 'Referral removed from your network' : 'No matching referral found',
    'removed' => $affected > 0,
]);
