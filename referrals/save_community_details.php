<?php
/**
 * API Endpoint: Save Community Details
 * 
 * Creates or updates community details for a referral_id
 * 
 * POST Parameters (JSON):
 * - referral_id: Referral ID (required)
 * - community_name: Community name (required)
 * - bio: Community bio/description (required)
 * - profile_banner: Profile banner URL/path (optional)
 * - profile_image: Profile image URL/path (optional)
 */

// Handle CORS preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    header('Access-Control-Max-Age: 86400');
    http_response_code(200);
    exit;
}

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

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

if (empty($input['referral_id'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Referral ID is required']);
    exit;
}

if (empty($input['community_name']) || trim($input['community_name']) === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Community name is required']);
    exit;
}

if (empty($input['bio']) || trim($input['bio']) === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Bio is required']);
    exit;
}

$referralId = (int)$input['referral_id'];
$communityName = trim($input['community_name']);
$bio = trim($input['bio']);
$profileBanner = isset($input['profile_banner']) ? trim($input['profile_banner']) : null;
$profileImage = isset($input['profile_image']) ? trim($input['profile_image']) : null;

// Validate referral_id exists
$checkReferralSql = 'SELECT referral_id FROM referral_codes WHERE referral_id = ? LIMIT 1';
$referralExists = false;

if ($stmt = mysqli_prepare($conn, $checkReferralSql)) {
    mysqli_stmt_bind_param($stmt, 'i', $referralId);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    if (mysqli_num_rows($result) > 0) {
        $referralExists = true;
    }
    mysqli_stmt_close($stmt);
}

if (!$referralExists) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Referral ID not found']);
    exit;
}

// Check if community details already exist
$checkExistsSql = 'SELECT community_id FROM community_details WHERE referral_id = ? LIMIT 1';
$exists = false;
$communityId = null;

if ($stmt = mysqli_prepare($conn, $checkExistsSql)) {
    mysqli_stmt_bind_param($stmt, 'i', $referralId);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $row = mysqli_fetch_assoc($result);
    if ($row) {
        $exists = true;
        $communityId = (int)$row['community_id'];
    }
    mysqli_stmt_close($stmt);
}

if ($exists) {
    // Update existing record
    $updateSql = 'UPDATE community_details 
                   SET community_name = ?, 
                       bio = ?, 
                       profile_banner = ?, 
                       profile_image = ? 
                   WHERE referral_id = ?';
    
    if ($stmt = mysqli_prepare($conn, $updateSql)) {
        mysqli_stmt_bind_param($stmt, 'ssssi', $communityName, $bio, $profileBanner, $profileImage, $referralId);
        if (mysqli_stmt_execute($stmt)) {
            mysqli_stmt_close($stmt);
            echo json_encode([
                'success' => true,
                'message' => 'Community details updated successfully',
                'community_id' => $communityId
            ]);
        } else {
            mysqli_stmt_close($stmt);
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Failed to update community details: ' . mysqli_error($conn)]);
        }
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to prepare update statement']);
    }
} else {
    // Insert new record
    $insertSql = 'INSERT INTO community_details (referral_id, community_name, bio, profile_banner, profile_image) 
                   VALUES (?, ?, ?, ?, ?)';
    
    if ($stmt = mysqli_prepare($conn, $insertSql)) {
        mysqli_stmt_bind_param($stmt, 'issss', $referralId, $communityName, $bio, $profileBanner, $profileImage);
        if (mysqli_stmt_execute($stmt)) {
            $newCommunityId = mysqli_insert_id($conn);
            mysqli_stmt_close($stmt);
            echo json_encode([
                'success' => true,
                'message' => 'Community details created successfully',
                'community_id' => $newCommunityId
            ]);
        } else {
            mysqli_stmt_close($stmt);
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Failed to create community details: ' . mysqli_error($conn)]);
        }
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to prepare insert statement']);
    }
}

mysqli_close($conn);
?>

