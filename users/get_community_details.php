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

$user_uni_id = $_GET['user_uni_id'] ?? '';

if (empty($user_uni_id)) {
    echo json_encode(['success' => false, 'message' => 'User ID is required']);
    exit;
}

$referral_id = null;
$communityDetails = null;

// Get the referral code that the current user used when registering
$stmt = mysqli_prepare($conn, "SELECT DISTINCT referral_code FROM referral_tracking WHERE referred_user_uni_id = ? LIMIT 1");
if ($stmt) {
    mysqli_stmt_bind_param($stmt, "s", $user_uni_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $userReferralData = mysqli_fetch_assoc($result);
    mysqli_stmt_close($stmt);
    
    if ($userReferralData && !empty($userReferralData['referral_code'])) {
        $referral_code = $userReferralData['referral_code'];
        
        // Get the referral_id from referral_codes
        $stmt = mysqli_prepare($conn, "SELECT referral_id FROM referral_codes WHERE code = ? LIMIT 1");
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, "s", $referral_code);
            mysqli_stmt_execute($stmt);
            $result = mysqli_stmt_get_result($stmt);
            $codeData = mysqli_fetch_assoc($result);
            mysqli_stmt_close($stmt);
            
            if ($codeData && !empty($codeData['referral_id'])) {
                $referral_id = $codeData['referral_id'];
            }
        }
    } else {
        // User hasn't used a referral code, check if they created one
        $stmt = mysqli_prepare($conn, "SELECT referral_id FROM referral_codes WHERE user_uni_id = ? LIMIT 1");
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, "s", $user_uni_id);
            mysqli_stmt_execute($stmt);
            $result = mysqli_stmt_get_result($stmt);
            $createdCode = mysqli_fetch_assoc($result);
            mysqli_stmt_close($stmt);
            
            if ($createdCode && !empty($createdCode['referral_id'])) {
                $referral_id = $createdCode['referral_id'];
            }
        }
    }
}

// Get community details if referral_id exists
if ($referral_id) {
    $stmt = mysqli_prepare($conn, "SELECT community_id, referral_id, community_name, profile_banner, profile_image, bio, created_at, updated_at FROM community_details WHERE referral_id = ? LIMIT 1");
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "i", $referral_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $communityDetails = mysqli_fetch_assoc($result);
        mysqli_stmt_close($stmt);
    }
}

// Check if user is mentor
$isMentor = false;
if ($referral_id) {
    $stmt = mysqli_prepare($conn, "SELECT user_uni_id FROM referral_codes WHERE referral_id = ? LIMIT 1");
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "i", $referral_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $mentorData = mysqli_fetch_assoc($result);
        mysqli_stmt_close($stmt);
        
        if ($mentorData && $mentorData['user_uni_id'] === $user_uni_id) {
            $isMentor = true;
        }
    }
}

if ($communityDetails) {
    echo json_encode([
        'success' => true,
        'community' => $communityDetails,
        'is_mentor' => $isMentor
    ]);
} else {
    echo json_encode([
        'success' => false,
        'message' => 'Community details not found',
        'is_mentor' => $isMentor
    ]);
}
?>

