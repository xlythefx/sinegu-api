<?php
/**
 * API Endpoint: Create Post
 * 
 * Creates a new community post and updates user last_activity
 */

// Suppress errors to prevent breaking JSON responses
error_reporting(E_ALL);
ini_set('display_errors', 0);

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

// Check if files exist before requiring
$connPath = __DIR__ . '/../conn.php';
$updateActivityPath = __DIR__ . '/update_last_activity.php';

if (!file_exists($connPath)) {
    echo json_encode([
        'success' => false,
        'error' => 'Database configuration file not found: ' . $connPath
    ]);
    exit;
}

if (!file_exists($updateActivityPath)) {
    echo json_encode([
        'success' => false,
        'error' => 'Helper file not found: ' . $updateActivityPath
    ]);
    exit;
}

require_once $connPath;
require_once $updateActivityPath;

$openaiModeratePath = __DIR__ . '/../includes/openai_moderate.php';
if (file_exists($openaiModeratePath)) {
    require_once $openaiModeratePath;
}

if (!$conn) {
    echo json_encode([
        'success' => false,
        'error' => 'Database connection failed'
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode([
        'success' => false,
        'error' => 'Only POST method is allowed'
    ]);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$uni_id = $input['uni_id'] ?? $_POST['uni_id'] ?? null;
$title = $input['title'] ?? $_POST['title'] ?? null;
$content = $input['content'] ?? $_POST['content'] ?? null;
$category = $input['category'] ?? $_POST['category'] ?? null;
$image_urls = $input['image_urls'] ?? $_POST['image_urls'] ?? []; // Array of uploaded image URLs
$post_type = $input['post_type'] ?? $_POST['post_type'] ?? 'general';
if (!in_array($post_type, ['general', 'course_discussion'], true)) {
    $post_type = 'general';
}
$submittedCourseLevel = isset($input['course_level']) ? trim((string)$input['course_level']) : (isset($_POST['course_level']) ? trim((string)$_POST['course_level']) : null);

if (!$uni_id || !$title || !$content) {
    echo json_encode([
        'success' => false,
        'error' => 'Missing required fields: uni_id, title, content'
    ]);
    exit;
}

$escapedUniId = mysqli_real_escape_string($conn, $uni_id);
$escapedTitle = mysqli_real_escape_string($conn, $title);
$escapedContent = mysqli_real_escape_string($conn, $content);
$escapedCategory = $category ? mysqli_real_escape_string($conn, $category) : 'NULL';

// Verify user exists and get course_level, name for course_discussion posts and logging
$userCheck = "SELECT uni_id, name, course_level FROM user_credentials WHERE uni_id = '$escapedUniId' LIMIT 1";
$userResult = mysqli_query($conn, $userCheck);

if (!$userResult || mysqli_num_rows($userResult) === 0) {
    echo json_encode([
        'success' => false,
        'error' => 'User not found'
    ]);
    exit;
}

$userRow = mysqli_fetch_assoc($userResult);
$course_level = 'general';
if ($post_type === 'course_discussion') {
    $allowedLevels = ['first', 'second', 'third', 'fourth', 'fifth', 'sixth'];
    $userLevel = $userRow['course_level'] ?? null;
    $userIdx = $userLevel !== null && in_array($userLevel, $allowedLevels, true) ? array_search($userLevel, $allowedLevels, true) : 0;
    if ($submittedCourseLevel !== null && in_array($submittedCourseLevel, $allowedLevels, true)) {
        $submittedIdx = array_search($submittedCourseLevel, $allowedLevels, true);
        if ($submittedIdx <= $userIdx) {
            $course_level = $submittedCourseLevel;
        } else {
            $course_level = $allowedLevels[$userIdx];
        }
    } else {
        $course_level = ($userLevel !== null && in_array($userLevel, $allowedLevels, true)) ? $userLevel : 'first';
    }
}

// Determine status: posts with images → pending; text-only → OpenAI moderation
$status = 'pending';
$hasImages = !empty($image_urls) && is_array($image_urls);
if (!$hasImages && function_exists('openai_content_flagged')) {
    $textToCheck = $title . "\n" . $content;
    if (openai_content_flagged($textToCheck)) {
        echo json_encode([
            'success' => false,
            'error' => "Post contains content that doesn't meet community guidelines",
            'status' => 'rejected'
        ]);
        exit;
    }
}

$escapedStatus = mysqli_real_escape_string($conn, $status);
$escapedPostType = mysqli_real_escape_string($conn, $post_type);
$escapedCourseLevel = mysqli_real_escape_string($conn, $course_level);

// Insert post
$sql = "INSERT INTO community_posts (uni_id, title, content, category, status, post_type, course_level)
        VALUES ('$escapedUniId', '$escapedTitle', '$escapedContent', " . ($escapedCategory === 'NULL' ? 'NULL' : "'$escapedCategory'") . ", '$escapedStatus', '$escapedPostType', '$escapedCourseLevel')";

$result = mysqli_query($conn, $sql);

if (!$result) {
    if (file_exists(__DIR__ . '/../includes/api_log.php')) {
        require_once __DIR__ . '/../includes/api_log.php';
        api_log($conn, 'community/create_post', 'Create post failed', 'create', false);
    }
    echo json_encode([
        'success' => false,
        'error' => 'Failed to create post: ' . mysqli_error($conn)
    ]);
    exit;
}

$post_id = mysqli_insert_id($conn);

// Save image attachments if provided
if (!empty($image_urls) && is_array($image_urls)) {
    foreach ($image_urls as $imageUrl) {
        $escapedImageUrl = mysqli_real_escape_string($conn, $imageUrl);
        
        // Extract filename from URL
        $filename = basename($imageUrl);
        $escapedFilename = mysqli_real_escape_string($conn, $filename);
        
        // Determine file type from extension
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $fileType = 'image';
        $mimeType = 'image/' . $extension;
        
        // Get file size if file exists
        $filePath = __DIR__ . '/../uploads/community/' . $filename;
        $fileSize = file_exists($filePath) ? filesize($filePath) : null;
        
        $attachmentSql = "INSERT INTO community_post_attachments 
                          (post_id, file_path, file_type, file_name, file_size, mime_type)
                          VALUES ($post_id, '$escapedImageUrl', '$fileType', '$escapedFilename', " . 
                          ($fileSize ? $fileSize : 'NULL') . ", '$mimeType')";
        
        mysqli_query($conn, $attachmentSql);
    }
}

// Update last_activity
updateUserLastActivity($conn, $uni_id);

if (file_exists(__DIR__ . '/../includes/api_log.php')) {
    require_once __DIR__ . '/../includes/api_log.php';
    $userName = isset($userRow['name']) ? $userRow['name'] : 'User';
    api_log($conn, 'community/create_post', "User ($userName) created a post", 'create', true);
}

echo json_encode([
    'success' => true,
    'post_id' => $post_id,
    'status' => $status,
    'message' => 'Post created successfully'
]);

mysqli_close($conn);
?>

