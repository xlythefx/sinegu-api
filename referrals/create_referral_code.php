<?php

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

require_once __DIR__ . '/../conn.php';

if (!$conn) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

if (empty($input['user_uni_id'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'User uni_id is required']);
    exit;
}

$userUniId = trim($input['user_uni_id']);

// Check if user already has a referral code
$checkSql = 'SELECT code FROM referral_codes WHERE user_uni_id = ? LIMIT 1';
$existingCode = null;

if ($stmt = mysqli_prepare($conn, $checkSql)) {
    mysqli_stmt_bind_param($stmt, 's', $userUniId);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $row = mysqli_fetch_assoc($result);
    if ($row) {
        $existingCode = $row['code'];
    }
    mysqli_stmt_close($stmt);
}

if ($existingCode) {
    echo json_encode([
        'success' => true,
        'message' => 'Referral code already exists',
        'code' => $existingCode,
        'already_exists' => true
    ]);
    mysqli_close($conn);
    exit;
}

// Generate a unique referral code
function generateReferralCode($length = 8) {
    $characters = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // Removed confusing characters like 0, O, I, 1
    $code = '';
    for ($i = 0; $i < $length; $i++) {
        $code .= $characters[random_int(0, strlen($characters) - 1)];
    }
    return $code;
}

// Generate unique code (retry if collision)
$maxAttempts = 10;
$code = null;
for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
    $generatedCode = generateReferralCode();
    
    // Check if code already exists
    $checkCodeSql = 'SELECT referral_id FROM referral_codes WHERE code = ? LIMIT 1';
    if ($stmt = mysqli_prepare($conn, $checkCodeSql)) {
        mysqli_stmt_bind_param($stmt, 's', $generatedCode);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        if (mysqli_num_rows($result) === 0) {
            $code = $generatedCode;
            mysqli_stmt_close($stmt);
            break;
        }
        mysqli_stmt_close($stmt);
    }
}

if (!$code) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to generate unique referral code']);
    mysqli_close($conn);
    exit;
}

// Insert new referral code
$insertSql = 'INSERT INTO referral_codes (code, user_uni_id) VALUES (?, ?)';
if ($stmt = mysqli_prepare($conn, $insertSql)) {
    mysqli_stmt_bind_param($stmt, 'ss', $code, $userUniId);
    if (mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        mysqli_close($conn);
        echo json_encode([
            'success' => true,
            'message' => 'Referral code created successfully',
            'code' => $code
        ]);
        exit;
    } else {
        mysqli_stmt_close($stmt);
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to create referral code: ' . mysqli_error($conn)]);
        mysqli_close($conn);
        exit;
    }
} else {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to prepare statement']);
    mysqli_close($conn);
    exit;
}

?>

