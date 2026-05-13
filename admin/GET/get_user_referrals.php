<?php
/**
 * Admin: Get referrals for a specific user (as referrer).
 * GET /admin/GET/get_user_referrals.php?uni_id=...
 * uni_id = referrer_uni_id (user who referred others)
 * Returns: referrals array (referred_user_uni_id, name, email, referred_at)
 */

ob_start();

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    ob_end_clean();
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$uni_id = isset($_GET['uni_id']) ? trim($_GET['uni_id']) : '';

if (empty($uni_id)) {
    ob_end_clean();
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'uni_id is required']);
    exit;
}

require_once __DIR__ . '/../../conn.php';

if (!$conn) {
    ob_end_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit;
}

$sql = "SELECT rt.referred_user_uni_id, rt.created_at, u.name, u.email
        FROM referral_tracking rt
        LEFT JOIN user_credentials u ON rt.referred_user_uni_id = u.uni_id
        WHERE rt.referrer_uni_id = ?
        ORDER BY rt.created_at DESC";

$referrals = [];

if ($stmt = mysqli_prepare($conn, $sql)) {
    mysqli_stmt_bind_param($stmt, 's', $uni_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($result)) {
        $referrals[] = [
            'referred_user_uni_id' => $row['referred_user_uni_id'],
            'name' => $row['name'] ?? 'Unknown',
            'email' => $row['email'] ?? 'N/A',
            'referred_at' => $row['created_at'],
        ];
    }
    mysqli_stmt_close($stmt);
}

ob_end_clean();
echo json_encode([
    'success' => true,
    'referrals' => $referrals,
    'total' => count($referrals),
]);
mysqli_close($conn);
