<?php
/**
 * API Endpoint: Update Comment
 * 
 * Updates an existing comment
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
$content = $input['content'] ?? $_POST['content'] ?? null;

if (!$comment_id || !$uni_id || !$content) {
    echo json_encode([
        'success' => false,
        'error' => 'Missing required fields: comment_id, uni_id, content'
    ]);
    exit;
}

$escapedCommentId = (int)$comment_id;
$escapedUniId = mysqli_real_escape_string($conn, $uni_id);
$escapedContent = mysqli_real_escape_string($conn, $content);

// Verify comment exists and belongs to user
$checkSql = "SELECT uni_id FROM community_comments WHERE id = $escapedCommentId AND uni_id = '$escapedUniId' LIMIT 1";
$checkResult = mysqli_query($conn, $checkSql);

if (!$checkResult || mysqli_num_rows($checkResult) === 0) {
    echo json_encode([
        'success' => false,
        'error' => 'Comment not found or you do not have permission to edit it'
    ]);
    exit;
}

// Update comment
$updateSql = "UPDATE community_comments SET content = '$escapedContent', updated_at = NOW() WHERE id = $escapedCommentId AND uni_id = '$escapedUniId'";
$updateResult = mysqli_query($conn, $updateSql);

if (!$updateResult) {
    if (file_exists(__DIR__ . '/../includes/api_log.php')) {
        require_once __DIR__ . '/../includes/api_log.php';
        api_log($conn, 'community/update_comment', 'Update comment failed', 'update', false);
    }
    echo json_encode([
        'success' => false,
        'error' => 'Failed to update comment: ' . mysqli_error($conn)
    ]);
    exit;
}

// Update last_activity
updateUserLastActivity($conn, $uni_id);

if (file_exists(__DIR__ . '/../includes/api_log.php')) {
    require_once __DIR__ . '/../includes/api_log.php';
    api_log($conn, 'community/update_comment', "Update comment id = $escapedCommentId", 'update', true);
}

echo json_encode([
    'success' => true,
    'message' => 'Comment updated successfully'
]);

mysqli_close($conn);
?>

