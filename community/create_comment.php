<?php
/**
 * API Endpoint: Create Comment
 * 
 * Creates a new comment or reply and updates user last_activity
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
$uni_id = $input['uni_id'] ?? $_POST['uni_id'] ?? null;
$post_id = $input['post_id'] ?? $_POST['post_id'] ?? null;
$comment_id = $input['comment_id'] ?? $_POST['comment_id'] ?? null; // For replies
$parent_comment_id = $input['parent_comment_id'] ?? $_POST['parent_comment_id'] ?? null;
$content = $input['content'] ?? $_POST['content'] ?? null;

if (!$uni_id || !$content) {
    echo json_encode([
        'success' => false,
        'error' => 'Missing required fields: uni_id, content'
    ]);
    exit;
}

// Must have either post_id (top-level comment) or parent_comment_id (reply)
if (!$post_id && !$parent_comment_id) {
    echo json_encode([
        'success' => false,
        'error' => 'Either post_id (for top-level comment) or parent_comment_id (for reply) is required'
    ]);
    exit;
}

$escapedUniId = mysqli_real_escape_string($conn, $uni_id);
$escapedContent = mysqli_real_escape_string($conn, $content);

// Verify user exists
$userCheck = "SELECT uni_id FROM user_credentials WHERE uni_id = '$escapedUniId' LIMIT 1";
$userResult = mysqli_query($conn, $userCheck);

if (!$userResult || mysqli_num_rows($userResult) === 0) {
    echo json_encode([
        'success' => false,
        'error' => 'User not found'
    ]);
    exit;
}

// If parent_comment_id is provided, get the post_id from the parent comment
if ($parent_comment_id) {
    $parentCheck = "SELECT post_id FROM community_comments WHERE id = " . (int)$parent_comment_id . " LIMIT 1";
    $parentResult = mysqli_query($conn, $parentCheck);
    
    if (!$parentResult || mysqli_num_rows($parentResult) === 0) {
        echo json_encode([
            'success' => false,
            'error' => 'Parent comment not found'
        ]);
        exit;
    }
    
    $parentRow = mysqli_fetch_assoc($parentResult);
    $post_id = $parentRow['post_id'];
}

// Verify post exists
$postCheck = "SELECT id FROM community_posts WHERE id = " . (int)$post_id . " LIMIT 1";
$postResult = mysqli_query($conn, $postCheck);

if (!$postResult || mysqli_num_rows($postResult) === 0) {
    echo json_encode([
        'success' => false,
        'error' => 'Post not found'
    ]);
    exit;
}

// Insert comment
$escapedPostId = (int)$post_id;
$escapedParentId = $parent_comment_id ? (int)$parent_comment_id : 'NULL';

$sql = "INSERT INTO community_comments (post_id, uni_id, parent_comment_id, content)
        VALUES ($escapedPostId, '$escapedUniId', " . ($escapedParentId === 'NULL' ? 'NULL' : $escapedParentId) . ", '$escapedContent')";

$result = mysqli_query($conn, $sql);

if (!$result) {
    echo json_encode([
        'success' => false,
        'error' => 'Failed to create comment: ' . mysqli_error($conn)
    ]);
    exit;
}

$comment_id = mysqli_insert_id($conn);

// Update interactions_count on post
$updatePostSql = "UPDATE community_posts SET interactions_count = interactions_count + 1 WHERE id = $escapedPostId";
mysqli_query($conn, $updatePostSql);

// Update last_activity
updateUserLastActivity($conn, $uni_id);

echo json_encode([
    'success' => true,
    'comment_id' => $comment_id,
    'message' => 'Comment created successfully'
]);

mysqli_close($conn);
?>

