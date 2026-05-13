<?php
/**
 * API Endpoint: Admin delete post (no ownership check).
 * POST body: { "post_id": int }
 * Use from admin UI only; add admin auth if required.
 */

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
    echo json_encode(['success' => false, 'error' => 'Only POST is allowed']);
    exit;
}

require_once __DIR__ . '/../conn.php';

if (!$conn) {
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$post_id = isset($input['post_id']) ? (int) $input['post_id'] : 0;

if ($post_id < 1) {
    echo json_encode(['success' => false, 'error' => 'Invalid post_id']);
    exit;
}

// CASCADE will remove comments and attachments
$sql = "DELETE FROM community_posts WHERE id = $post_id";
$result = mysqli_query($conn, $sql);

if (!$result) {
    if (file_exists(__DIR__ . '/../includes/api_log.php')) {
        require_once __DIR__ . '/../includes/api_log.php';
        api_log($conn, 'community/admin_delete_post', 'Admin delete post failed', 'delete', false);
    }
    echo json_encode(['success' => false, 'error' => 'Delete failed: ' . mysqli_error($conn)]);
    mysqli_close($conn);
    exit;
}

if (mysqli_affected_rows($conn) === 0) {
    echo json_encode(['success' => false, 'error' => 'Post not found']);
    mysqli_close($conn);
    exit;
}

if (file_exists(__DIR__ . '/../includes/api_log.php')) {
    require_once __DIR__ . '/../includes/api_log.php';
    api_log($conn, 'community/admin_delete_post', "Admin deleted post id = $post_id", 'delete', true);
}

echo json_encode(['success' => true, 'message' => 'Post deleted', 'post_id' => $post_id]);
mysqli_close($conn);
