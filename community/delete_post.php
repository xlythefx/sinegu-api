<?php
/**
 * API Endpoint: Delete Post
 * 
 * Deletes a post (CASCADE will handle comments and attachments)
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

if (!$post_id || !$uni_id) {
    echo json_encode([
        'success' => false,
        'error' => 'Missing required fields: post_id, uni_id'
    ]);
    exit;
}

$escapedPostId = (int)$post_id;
$escapedUniId = mysqli_real_escape_string($conn, $uni_id);

// Verify post exists and belongs to user
$checkSql = "SELECT uni_id FROM community_posts WHERE id = $escapedPostId AND uni_id = '$escapedUniId' LIMIT 1";
$checkResult = mysqli_query($conn, $checkSql);

if (!$checkResult || mysqli_num_rows($checkResult) === 0) {
    echo json_encode([
        'success' => false,
        'error' => 'Post not found or you do not have permission to delete it'
    ]);
    exit;
}

// Delete post (CASCADE will handle comments and attachments)
$deleteSql = "DELETE FROM community_posts WHERE id = $escapedPostId AND uni_id = '$escapedUniId'";
$deleteResult = mysqli_query($conn, $deleteSql);

if (!$deleteResult) {
    if (file_exists(__DIR__ . '/../includes/api_log.php')) {
        require_once __DIR__ . '/../includes/api_log.php';
        api_log($conn, 'community/delete_post', 'Delete post failed', 'delete', false);
    }
    echo json_encode([
        'success' => false,
        'error' => 'Failed to delete post: ' . mysqli_error($conn)
    ]);
    exit;
}

// Update last_activity
updateUserLastActivity($conn, $uni_id);

if (file_exists(__DIR__ . '/../includes/api_log.php')) {
    require_once __DIR__ . '/../includes/api_log.php';
    api_log($conn, 'community/delete_post', "Delete post id = $escapedPostId", 'delete', true);
}

echo json_encode([
    'success' => true,
    'message' => 'Post deleted successfully'
]);

mysqli_close($conn);
?>

