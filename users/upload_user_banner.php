<?php
/**
 * API Endpoint: Upload User Banner
 * 
 * Handles banner image uploads for user profiles
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
    echo json_encode([
        'success' => false,
        'error' => 'Only POST method is allowed'
    ]);
    exit;
}

// Check if file was uploaded
if (!isset($_FILES['banner']) || $_FILES['banner']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode([
        'success' => false,
        'error' => 'No file uploaded or upload error'
    ]);
    exit;
}

// Check if uni_id is provided
if (empty($_POST['uni_id'])) {
    echo json_encode([
        'success' => false,
        'error' => 'User uni_id is required'
    ]);
    exit;
}

$uniId = trim($_POST['uni_id']);

$file = $_FILES['banner'];

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

// Validate file size (max 5MB)
$maxSize = 5 * 1024 * 1024; // 5MB
if ($file['size'] > $maxSize) {
    echo json_encode([
        'success' => false,
        'error' => 'File size exceeds 5MB limit'
    ]);
    exit;
}

// Create uploads directory if it doesn't exist
$uploadDir = __DIR__ . '/../uploads/users/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

// Generate unique filename
$extension = pathinfo($file['name'], PATHINFO_EXTENSION);
$filename = 'banner_' . uniqid('', true) . '_' . time() . '.' . $extension;
$filepath = $uploadDir . $filename;

// Move uploaded file
if (!move_uploaded_file($file['tmp_name'], $filepath)) {
    echo json_encode([
        'success' => false,
        'error' => 'Failed to save uploaded file'
    ]);
    exit;
}

// Return file URL (relative path that can be used by frontend)
$fileUrl = 'uploads/users/' . $filename;

// Update user_credentials table with the banner URL
require_once __DIR__ . '/../conn.php';

if (!$conn) {
    echo json_encode([
        'success' => false,
        'error' => 'Database connection failed'
    ]);
    exit;
}

$updateSql = 'UPDATE user_credentials SET user_banner = ? WHERE uni_id = ?';
if ($stmt = mysqli_prepare($conn, $updateSql)) {
    mysqli_stmt_bind_param($stmt, 'ss', $fileUrl, $uniId);
    if (mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        mysqli_close($conn);
        echo json_encode([
            'success' => true,
            'file_url' => $fileUrl,
            'file_name' => $filename,
            'file_size' => $file['size'],
            'mime_type' => $fileType,
            'message' => 'Banner uploaded and saved successfully'
        ]);
        exit;
    } else {
        mysqli_stmt_close($stmt);
        mysqli_close($conn);
        echo json_encode([
            'success' => true,
            'file_url' => $fileUrl,
            'file_name' => $filename,
            'file_size' => $file['size'],
            'mime_type' => $fileType,
            'warning' => 'File uploaded but failed to update database: ' . mysqli_error($conn)
        ]);
        exit;
    }
} else {
    mysqli_close($conn);
    echo json_encode([
        'success' => true,
        'file_url' => $fileUrl,
        'file_name' => $filename,
        'file_size' => $file['size'],
        'mime_type' => $fileType,
        'warning' => 'File uploaded but failed to update database'
    ]);
    exit;
}
?>

