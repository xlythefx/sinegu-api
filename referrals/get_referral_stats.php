<?php

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

require_once __DIR__ . '/../conn.php';

if (!$conn) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit;
}

if (empty($_GET['user_uni_id'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'User uni_id is required']);
    exit;
}

$userUniId = trim($_GET['user_uni_id']);

// Get referral code and referral_id
$codeSql = 'SELECT referral_id, code FROM referral_codes WHERE user_uni_id = ? LIMIT 1';
$referralCode = null;
$referralId = null;

if ($stmt = mysqli_prepare($conn, $codeSql)) {
    mysqli_stmt_bind_param($stmt, 's', $userUniId);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $row = mysqli_fetch_assoc($result);
    if ($row) {
        $referralCode = $row['code'];
        $referralId = (int)$row['referral_id'];
    }
    mysqli_stmt_close($stmt);
}

if (!$referralCode) {
    echo json_encode([
        'success' => true,
        'code' => null,
        'referral_id' => null,
        'total_referrals' => 0,
        'referrals' => []
    ]);
    mysqli_close($conn);
    exit;
}

// Get referral stats
$statsSql = 'SELECT 
    rt.referred_user_uni_id,
    rt.created_at,
    u.name,
    u.email
FROM referral_tracking rt
LEFT JOIN user_credentials u ON rt.referred_user_uni_id = u.uni_id
WHERE rt.referrer_uni_id = ?
ORDER BY rt.created_at DESC';

$referrals = [];
$totalReferrals = 0;

if ($stmt = mysqli_prepare($conn, $statsSql)) {
    mysqli_stmt_bind_param($stmt, 's', $userUniId);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($result)) {
        $referrals[] = [
            'user_uni_id' => $row['referred_user_uni_id'],
            'name' => $row['name'] ?? 'Unknown',
            'email' => $row['email'] ?? 'N/A',
            'referred_at' => $row['created_at']
        ];
        $totalReferrals++;
    }
    mysqli_stmt_close($stmt);
}

echo json_encode([
    'success' => true,
    'code' => $referralCode,
    'referral_id' => $referralId,
    'total_referrals' => $totalReferrals,
    'referrals' => $referrals
]);

mysqli_close($conn);

?>

