<?php
/**
 * API Endpoint: Get Single Post
 * 
 * Fetches a single post with all details
 * 
 * GET Parameters:
 * - id: Post ID (required)
 */

// Handle CORS preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    header('Access-Control-Max-Age: 86400');
    http_response_code(200);
    exit;
}

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

require_once __DIR__ . '/../conn.php';

if (!$conn) {
    echo json_encode([
        'success' => false,
        'error' => 'Database connection failed'
    ]);
    exit;
}

$post_id = isset($_GET['id']) ? (int)$_GET['id'] : null;

if (!$post_id) {
    echo json_encode([
        'success' => false,
        'error' => 'Post ID is required'
    ]);
    exit;
}

// Get post with author information
$sql = "SELECT 
            cp.id,
            cp.uni_id,
            cp.title,
            cp.content,
            cp.category,
            cp.status,
            cp.interactions_count,
            cp.created_at,
            cp.updated_at,
            uc.name as author_name,
            uc.email as author_email,
            (SELECT COUNT(*) FROM community_comments WHERE post_id = cp.id) as replies_count,
            (SELECT COUNT(*) FROM help_actions WHERE post_id = cp.id) as help_count,
            COALESCE(cp.likes_count, 0) as likes_count
        FROM community_posts cp
        INNER JOIN user_credentials uc ON cp.uni_id = uc.uni_id
        WHERE cp.id = $post_id
        LIMIT 1";

$result = mysqli_query($conn, $sql);

if (!$result || mysqli_num_rows($result) === 0) {
    echo json_encode([
        'success' => false,
        'error' => 'Post not found'
    ]);
    exit;
}

$row = mysqli_fetch_assoc($result);

$post = [
    'id' => (int)$row['id'],
    'uni_id' => $row['uni_id'],
    'title' => $row['title'],
    'content' => $row['content'],
    'category' => $row['category'],
    'status' => $row['status'] ?? 'accepted',
    'author' => $row['author_name'],
    'author_email' => $row['author_email'],
    'replies' => (int)$row['replies_count'],
    'help_count' => (int)$row['help_count'],
    'likes_count' => (int)$row['likes_count'],
    'interactions_count' => (int)$row['interactions_count'],
    'created_at' => $row['created_at'],
    'updated_at' => $row['updated_at'],
    'attachments' => []
];

// Get attachments for this post
$attachmentsSql = "SELECT id, file_path, file_type, file_name, file_size, mime_type 
                   FROM community_post_attachments 
                   WHERE post_id = $post_id 
                   ORDER BY id ASC";
$attachmentsResult = mysqli_query($conn, $attachmentsSql);

if ($attachmentsResult) {
    while ($attachmentRow = mysqli_fetch_assoc($attachmentsResult)) {
        $post['attachments'][] = [
            'id' => (int)$attachmentRow['id'],
            'file_path' => $attachmentRow['file_path'],
            'file_type' => $attachmentRow['file_type'],
            'file_name' => $attachmentRow['file_name'],
            'file_size' => $attachmentRow['file_size'] ? (int)$attachmentRow['file_size'] : null,
            'mime_type' => $attachmentRow['mime_type']
        ];
    }
}

echo json_encode([
    'success' => true,
    'post' => $post
]);

mysqli_close($conn);
?>

