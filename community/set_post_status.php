<?php
/**
 * API Endpoint: Set post status (admin)
 * POST body: { "post_id": int, "status": "accepted"|"rejected" }
 * Auth: protect via admin-only route in frontend; add server-side auth if needed.
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
$status = isset($input['status']) ? trim((string) $input['status']) : '';

if ($post_id < 1 || !in_array($status, ['accepted', 'rejected'], true)) {
    echo json_encode([
        'success' => false,
        'error' => 'Invalid post_id or status. Use status: accepted or rejected'
    ]);
    exit;
}

$escapedStatus = mysqli_real_escape_string($conn, $status);
$sql = "UPDATE community_posts SET status = '$escapedStatus', updated_at = NOW() WHERE id = $post_id";
$result = mysqli_query($conn, $sql);

if (!$result) {
    if (file_exists(__DIR__ . '/../includes/api_log.php')) {
        require_once __DIR__ . '/../includes/api_log.php';
        api_log($conn, 'community/set_post_status', 'Set post status failed', 'update', false);
    }
    echo json_encode(['success' => false, 'error' => 'Update failed: ' . mysqli_error($conn)]);
    mysqli_close($conn);
    exit;
}

if (mysqli_affected_rows($conn) === 0) {
    echo json_encode(['success' => false, 'error' => 'Post not found or unchanged']);
    mysqli_close($conn);
    exit;
}

if (file_exists(__DIR__ . '/../includes/api_log.php')) {
    require_once __DIR__ . '/../includes/api_log.php';
    $desc = $status === 'accepted' ? "Admin approved post id = $post_id" : "Admin rejected post id = $post_id";
    api_log($conn, 'community/set_post_status', $desc, 'update', true);
}

echo json_encode(['success' => true, 'post_id' => $post_id, 'status' => $status]);
mysqli_close($conn);
