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

$referrer_uni_id = $_GET['referrer_uni_id'] ?? '';

if (empty($referrer_uni_id)) {
    echo json_encode(['success' => false, 'message' => 'Referrer ID is required']);
    exit;
}

$referredUsers = [];
$stmt = mysqli_prepare($conn, "SELECT DISTINCT referred_user_uni_id FROM referral_tracking WHERE referrer_uni_id = ?");
if ($stmt) {
    mysqli_stmt_bind_param($stmt, "s", $referrer_uni_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($result)) {
        $referredUsers[] = $row;
    }
    mysqli_stmt_close($stmt);
}

echo json_encode([
    'success' => true,
    'referred_users' => $referredUsers
]);
?>

