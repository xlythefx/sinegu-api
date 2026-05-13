<?php
/**
 * API Endpoint: Upload Community Profile Image
 * 
 * Handles profile image uploads for communities
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

require_once __DIR__ . '/../conn.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode([
        'success' => false,
        'error' => 'Only POST method is allowed'
    ]);
    exit;
}

if (!$conn) {
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit;
}

// Get user_uni_id from POST data
$user_uni_id = $_POST['user_uni_id'] ?? '';

if (empty($user_uni_id)) {
    echo json_encode([
        'success' => false,
        'error' => 'User ID is required'
    ]);
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
    echo json_encode([
        'success' => false,
        'error' => 'Only mentors can upload community images'
    ]);
    exit;
}

// Check if file was uploaded
if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode([
        'success' => false,
        'error' => 'No file uploaded or upload error'
    ]);
    exit;
}

$file = $_FILES['image'];

// Validate file type
$allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
$fileType = mime_content_type($file['tmp_name']);

if (!in_array($fileType, $allowedTypes)) {
    echo json_encode([
        'success' => false,
        'error' => 'Invalid file type. Only JPEG, PNG, GIF, and WebP images are allowed.'
    ]);
    exit;
}

// Validate file size (max 5MB for profile images)
$maxSize = 5 * 1024 * 1024; // 5MB
if ($file['size'] > $maxSize) {
    echo json_encode([
        'success' => false,
        'error' => 'File size exceeds 5MB limit'
    ]);
    exit;
}

// Create uploads directory if it doesn't exist
$uploadDir = __DIR__ . '/../uploads/community-assets/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

// Generate unique filename
$extension = pathinfo($file['name'], PATHINFO_EXTENSION);
$filename = 'profile_' . uniqid('', true) . '_' . time() . '.' . $extension;
$filepath = $uploadDir . $filename;

// Move uploaded file
if (!move_uploaded_file($file['tmp_name'], $filepath)) {
    echo json_encode([
        'success' => false,
        'error' => 'Failed to save uploaded file'
    ]);
    exit;
}

// Get or create community_details record
$stmt = mysqli_prepare($conn, "SELECT community_id FROM community_details WHERE referral_id = ? LIMIT 1");
if ($stmt) {
    mysqli_stmt_bind_param($stmt, "i", $referral_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $existing = mysqli_fetch_assoc($result);
    mysqli_stmt_close($stmt);
    
    // Relative path for database storage
    $relativePath = 'uploads/community-assets/' . $filename;
    
    if ($existing) {
        // Update existing - delete old profile image if exists
        $stmt = mysqli_prepare($conn, "SELECT profile_image FROM community_details WHERE referral_id = ? LIMIT 1");
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, "i", $referral_id);
            mysqli_stmt_execute($stmt);
            $result = mysqli_stmt_get_result($stmt);
            $oldData = mysqli_fetch_assoc($result);
            mysqli_stmt_close($stmt);
            
            // Delete old profile image file if exists
            if ($oldData && !empty($oldData['profile_image']) && file_exists(__DIR__ . '/../' . $oldData['profile_image'])) {
                @unlink(__DIR__ . '/../' . $oldData['profile_image']);
            }
        }
        
        // Update with new profile image
        $stmt = mysqli_prepare($conn, "UPDATE community_details SET profile_image = ?, updated_at = NOW() WHERE referral_id = ?");
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, "si", $relativePath, $referral_id);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        }
    } else {
        // Insert new
        $stmt = mysqli_prepare($conn, "INSERT INTO community_details (referral_id, profile_image, created_at, updated_at) VALUES (?, ?, NOW(), NOW())");
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, "is", $referral_id, $relativePath);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        }
    }
    
    echo json_encode([
        'success' => true,
        'file_url' => $relativePath,
        'file_name' => $filename,
        'message' => 'Profile image uploaded successfully'
    ]);
} else {
    echo json_encode([
        'success' => false,
        'error' => 'Database error'
    ]);
}
?>

