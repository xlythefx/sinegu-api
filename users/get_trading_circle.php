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

$circleMembers = [];
$mentorId = null;
$communityName = null;
$referral_id = null;

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
        
        // Get the mentor and referral_id (user who created this referral code)
        $stmt = mysqli_prepare($conn, "SELECT user_uni_id, referral_id FROM referral_codes WHERE code = ? LIMIT 1");
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, "s", $referral_code);
            mysqli_stmt_execute($stmt);
            $result = mysqli_stmt_get_result($stmt);
            $mentorData = mysqli_fetch_assoc($result);
            mysqli_stmt_close($stmt);
            
            if ($mentorData && !empty($mentorData['user_uni_id'])) {
                $mentorId = $mentorData['user_uni_id'];
                $referral_id = $mentorData['referral_id'];
                $circleMembers[] = $mentorId;
                
                // Get community name from community_details
                if ($referral_id) {
                    $stmt = mysqli_prepare($conn, "SELECT community_name FROM community_details WHERE referral_id = ? LIMIT 1");
                    if ($stmt) {
                        mysqli_stmt_bind_param($stmt, "i", $referral_id);
                        mysqli_stmt_execute($stmt);
                        $result = mysqli_stmt_get_result($stmt);
                        $communityData = mysqli_fetch_assoc($result);
                        mysqli_stmt_close($stmt);
                        
                        if ($communityData && !empty($communityData['community_name'])) {
                            $communityName = $communityData['community_name'];
                        }
                    }
                }
            }
        }
        
        // Get all users who used the same referral code (trading circle members)
        $stmt = mysqli_prepare($conn, "SELECT DISTINCT referred_user_uni_id FROM referral_tracking WHERE referral_code = ?");
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, "s", $referral_code);
            mysqli_stmt_execute($stmt);
            $result = mysqli_stmt_get_result($stmt);
            while ($row = mysqli_fetch_assoc($result)) {
                if (!in_array($row['referred_user_uni_id'], $circleMembers)) {
                    $circleMembers[] = $row['referred_user_uni_id'];
                }
            }
            mysqli_stmt_close($stmt);
        }
    } else {
        // User hasn't used a referral code, check if they created one
        $stmt = mysqli_prepare($conn, "SELECT code, referral_id FROM referral_codes WHERE user_uni_id = ? LIMIT 1");
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, "s", $user_uni_id);
            mysqli_stmt_execute($stmt);
            $result = mysqli_stmt_get_result($stmt);
            $createdCode = mysqli_fetch_assoc($result);
            mysqli_stmt_close($stmt);
            
            if ($createdCode && !empty($createdCode['code'])) {
                // User is a mentor, they created the code
                $mentorId = $user_uni_id;
                $circleMembers[] = $user_uni_id;
                $referral_code = $createdCode['code'];
                $referral_id = $createdCode['referral_id'];
                
                // Get community name from community_details
                if ($referral_id) {
                    $stmt = mysqli_prepare($conn, "SELECT community_name FROM community_details WHERE referral_id = ? LIMIT 1");
                    if ($stmt) {
                        mysqli_stmt_bind_param($stmt, "i", $referral_id);
                        mysqli_stmt_execute($stmt);
                        $result = mysqli_stmt_get_result($stmt);
                        $communityData = mysqli_fetch_assoc($result);
                        mysqli_stmt_close($stmt);
                        
                        if ($communityData && !empty($communityData['community_name'])) {
                            $communityName = $communityData['community_name'];
                        }
                    }
                }
                
                // Get all users who used this referral code
                $stmt = mysqli_prepare($conn, "SELECT DISTINCT referred_user_uni_id FROM referral_tracking WHERE referral_code = ?");
                if ($stmt) {
                    mysqli_stmt_bind_param($stmt, "s", $referral_code);
                    mysqli_stmt_execute($stmt);
                    $result = mysqli_stmt_get_result($stmt);
                    while ($row = mysqli_fetch_assoc($result)) {
                        if (!in_array($row['referred_user_uni_id'], $circleMembers)) {
                            $circleMembers[] = $row['referred_user_uni_id'];
                        }
                    }
                    mysqli_stmt_close($stmt);
                }
            } else {
                // User has no referral code relationship, just include themselves
                $circleMembers[] = $user_uni_id;
            }
        }
    }
} else {
    // Fallback: just include the current user
    $circleMembers[] = $user_uni_id;
}

echo json_encode([
    'success' => true,
    'members' => $circleMembers,
    'mentor_id' => $mentorId,
    'community_name' => $communityName
]);
?>

