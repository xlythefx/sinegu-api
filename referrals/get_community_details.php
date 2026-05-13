<?php
/**
 * API Endpoint: Get Community Details
 * 
 * Fetches community details for a given referral_id
 * 
 * GET Parameters:
 * - referral_id: Referral ID (required)
 */

// Handle CORS preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    header('Access-Control-Max-Age: 86400');
    http_response_code(200);
    exit;
}

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

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

if (empty($_GET['referral_id'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Referral ID is required']);
    exit;
}

$referralId = (int)$_GET['referral_id'];

// Get community details for the referral_id
$sql = 'SELECT 
            cd.community_id,
            cd.referral_id,
            cd.community_name,
            cd.profile_banner,
            cd.profile_image,
            cd.bio,
            cd.created_at,
            cd.updated_at,
            rc.code as referral_code
        FROM community_details cd
        INNER JOIN referral_codes rc ON cd.referral_id = rc.referral_id
        WHERE cd.referral_id = ? 
        LIMIT 1';

$communityDetails = null;

if ($stmt = mysqli_prepare($conn, $sql)) {
    mysqli_stmt_bind_param($stmt, 'i', $referralId);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $row = mysqli_fetch_assoc($result);
    if ($row) {
        $communityDetails = [
            'community_id' => (int)$row['community_id'],
            'referral_id' => (int)$row['referral_id'],
            'community_name' => $row['community_name'],
            'profile_banner' => $row['profile_banner'],
            'profile_image' => $row['profile_image'],
            'bio' => $row['bio'],
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
            'referral_code' => $row['referral_code']
        ];
    }
    mysqli_stmt_close($stmt);
}

if ($communityDetails) {
    echo json_encode([
        'success' => true,
        'community' => $communityDetails
    ]);
} else {
    echo json_encode([
        'success' => true,
        'community' => null,
        'message' => 'No community details found for this referral ID'
    ]);
}

mysqli_close($conn);
?>

