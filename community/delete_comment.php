<?php
/**
 * API Endpoint: Delete Comment
 * 
 * Deletes a comment (and its replies if any)
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
$comment_id = $input['comment_id'] ?? $_POST['comment_id'] ?? null;
$uni_id = $input['uni_id'] ?? $_POST['uni_id'] ?? null;

if (!$comment_id || !$uni_id) {
    echo json_encode([
        'success' => false,
        'error' => 'Missing required fields: comment_id, uni_id'
    ]);
    exit;
}

$escapedCommentId = (int)$comment_id;
$escapedUniId = mysqli_real_escape_string($conn, $uni_id);

// Verify comment exists and belongs to user
$checkSql = "SELECT uni_id, post_id FROM community_comments WHERE id = $escapedCommentId AND uni_id = '$escapedUniId' LIMIT 1";
$checkResult = mysqli_query($conn, $checkSql);

if (!$checkResult || mysqli_num_rows($checkResult) === 0) {
    echo json_encode([
        'success' => false,
        'error' => 'Comment not found or you do not have permission to delete it'
    ]);
    exit;
}

$commentRow = mysqli_fetch_assoc($checkResult);
$post_id = $commentRow['post_id'];

// Delete comment (CASCADE will handle replies)
$deleteSql = "DELETE FROM community_comments WHERE id = $escapedCommentId AND uni_id = '$escapedUniId'";
$deleteResult = mysqli_query($conn, $deleteSql);

if (!$deleteResult) {
    if (file_exists(__DIR__ . '/../includes/api_log.php')) {
        require_once __DIR__ . '/../includes/api_log.php';
        api_log($conn, 'community/delete_comment', 'Delete comment failed', 'delete', false);
    }
    echo json_encode([
        'success' => false,
        'error' => 'Failed to delete comment: ' . mysqli_error($conn)
    ]);
    exit;
}

// Update interactions_count on post
$updatePostSql = "UPDATE community_posts SET interactions_count = GREATEST(0, interactions_count - 1) WHERE id = " . (int)$post_id;
mysqli_query($conn, $updatePostSql);

// Update last_activity
updateUserLastActivity($conn, $uni_id);

if (file_exists(__DIR__ . '/../includes/api_log.php')) {
    require_once __DIR__ . '/../includes/api_log.php';
    api_log($conn, 'community/delete_comment', "Delete comment id = $escapedCommentId", 'delete', true);
}

echo json_encode([
    'success' => true,
    'message' => 'Comment deleted successfully'
]);

mysqli_close($conn);
?>

