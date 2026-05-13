<?php
/**
 * API Endpoint: Update Post
 * 
 * Updates an existing post
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
require_once __DIR__ . '/update_last_activity.php';

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
$post_id = $input['post_id'] ?? $_POST['post_id'] ?? null;
$uni_id = $input['uni_id'] ?? $_POST['uni_id'] ?? null;
$title = $input['title'] ?? $_POST['title'] ?? null;
$content = $input['content'] ?? $_POST['content'] ?? null;
$category = $input['category'] ?? $_POST['category'] ?? null;
$image_urls = $input['image_urls'] ?? $_POST['image_urls'] ?? [];

if (!$post_id || !$uni_id || !$title || !$content) {
    echo json_encode([
        'success' => false,
        'error' => 'Missing required fields: post_id, uni_id, title, content'
    ]);
    exit;
}

$escapedPostId = (int)$post_id;
$escapedUniId = mysqli_real_escape_string($conn, $uni_id);
$escapedTitle = mysqli_real_escape_string($conn, $title);
$escapedContent = mysqli_real_escape_string($conn, $content);
$escapedCategory = $category ? mysqli_real_escape_string($conn, $category) : 'NULL';

// Verify post exists and belongs to user
$checkSql = "SELECT uni_id FROM community_posts WHERE id = $escapedPostId AND uni_id = '$escapedUniId' LIMIT 1";
$checkResult = mysqli_query($conn, $checkSql);

if (!$checkResult || mysqli_num_rows($checkResult) === 0) {
    echo json_encode([
        'success' => false,
        'error' => 'Post not found or you do not have permission to edit it'
    ]);
    exit;
}

// Update post
$updateSql = "UPDATE community_posts 
              SET title = '$escapedTitle', 
                  content = '$escapedContent', 
                  category = " . ($escapedCategory === 'NULL' ? 'NULL' : "'$escapedCategory'") . ",
                  updated_at = NOW() 
              WHERE id = $escapedPostId AND uni_id = '$escapedUniId'";
$updateResult = mysqli_query($conn, $updateSql);

if (!$updateResult) {
    echo json_encode([
        'success' => false,
        'error' => 'Failed to update post: ' . mysqli_error($conn)
    ]);
    exit;
}

// Replace attachments when image_urls is provided (array; may be empty)
if (is_array($image_urls)) {
    mysqli_query($conn, "DELETE FROM community_post_attachments WHERE post_id = $escapedPostId");
    foreach ($image_urls as $imageUrl) {
        $escapedImageUrl = mysqli_real_escape_string($conn, $imageUrl);
        $filename = basename($imageUrl);
        $escapedFilename = mysqli_real_escape_string($conn, $filename);
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $fileType = 'image';
        $mimeType = 'image/' . $extension;
        $filePath = __DIR__ . '/../uploads/community/' . $filename;
        $fileSize = file_exists($filePath) ? filesize($filePath) : null;
        $attachmentSql = "INSERT INTO community_post_attachments 
                          (post_id, file_path, file_type, file_name, file_size, mime_type)
                          VALUES ($escapedPostId, '$escapedImageUrl', '$fileType', '$escapedFilename', " .
            ($fileSize ? $fileSize : 'NULL') . ", '$mimeType')";
        mysqli_query($conn, $attachmentSql);
    }
}

// Update last_activity
updateUserLastActivity($conn, $uni_id);

echo json_encode([
    'success' => true,
    'message' => 'Post updated successfully'
]);

mysqli_close($conn);
?>

