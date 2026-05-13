<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Content-Type');

require_once __DIR__ . '/../conn.php';

if (!$conn) {
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit;
}

$referred_user_uni_id = $_GET['referred_user_uni_id'] ?? '';

if (empty($referred_user_uni_id)) {
    echo json_encode(['success' => false, 'message' => 'Referred user ID is required']);
    exit;
}

$referrer_uni_id = null;
$stmt = mysqli_prepare($conn, "SELECT DISTINCT referrer_uni_id FROM referral_tracking WHERE referred_user_uni_id = ? LIMIT 1");
if ($stmt) {
    mysqli_stmt_bind_param($stmt, "s", $referred_user_uni_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $referrer = mysqli_fetch_assoc($result);
    mysqli_stmt_close($stmt);
    
    if ($referrer && !empty($referrer['referrer_uni_id'])) {
        $referrer_uni_id = $referrer['referrer_uni_id'];
    }
}

echo json_encode([
    'success' => true,
    'referrer_uni_id' => $referrer_uni_id
]);
?>

