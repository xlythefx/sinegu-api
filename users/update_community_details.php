<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

require_once __DIR__ . '/../conn.php';

if (!$conn) {
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$user_uni_id = $input['user_uni_id'] ?? '';
$community_name = $input['community_name'] ?? '';
$bio = $input['bio'] ?? '';

if (empty($user_uni_id)) {
    echo json_encode(['success' => false, 'message' => 'User ID is required']);
    exit;
}

// Check if user is mentor
$referral_id = null;
$isMentor = false;

$stmt = mysqli_prepare($conn, "SELECT referral_id FROM referral_codes WHERE user_uni_id = ? LIMIT 1");
if ($stmt) {
    mysqli_stmt_bind_param($stmt, "s", $user_uni_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $codeData = mysqli_fetch_assoc($result);
    mysqli_stmt_close($stmt);
    
    if ($codeData && !empty($codeData['referral_id'])) {
        $referral_id = $codeData['referral_id'];
        $isMentor = true;
    }
}

if (!$isMentor) {
    echo json_encode(['success' => false, 'message' => 'Only mentors can update community details']);
    exit;
}

// Update or insert community details
$stmt = mysqli_prepare($conn, "SELECT community_id FROM community_details WHERE referral_id = ? LIMIT 1");
if ($stmt) {
    mysqli_stmt_bind_param($stmt, "i", $referral_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $existing = mysqli_fetch_assoc($result);
    mysqli_stmt_close($stmt);
    
    if ($existing) {
        // Update existing
        $stmt = mysqli_prepare($conn, "UPDATE community_details SET community_name = ?, bio = ?, updated_at = NOW() WHERE referral_id = ?");
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, "ssi", $community_name, $bio, $referral_id);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        }
    } else {
        // Insert new
        $stmt = mysqli_prepare($conn, "INSERT INTO community_details (referral_id, community_name, bio, created_at, updated_at) VALUES (?, ?, ?, NOW(), NOW())");
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, "iss", $referral_id, $community_name, $bio);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        }
    }
    
    echo json_encode(['success' => true, 'message' => 'Community details updated successfully']);
} else {
    echo json_encode(['success' => false, 'message' => 'Failed to update community details']);
}
?>

