<?php
/**
 * API Endpoint: Get Comments
 * 
 * Fetches comments for a post with nested replies (one level deep)
 * 
 * GET Parameters:
 * - post_id: Post ID (required)
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

$post_id = isset($_GET['post_id']) ? (int)$_GET['post_id'] : null;

if (!$post_id) {
    echo json_encode([
        'success' => false,
        'error' => 'Post ID is required'
    ]);
    exit;
}

// Get top-level comments (parent_comment_id IS NULL)
$sql = "SELECT 
            cc.id,
            cc.post_id,
            cc.uni_id,
            cc.parent_comment_id,
            cc.content,
            cc.created_at,
            cc.updated_at,
            uc.name as author_name,
            uc.email as author_email,
            (SELECT COUNT(*) FROM help_actions WHERE comment_id = cc.id) as help_count
        FROM community_comments cc
        INNER JOIN user_credentials uc ON cc.uni_id = uc.uni_id
        WHERE cc.post_id = $post_id AND cc.parent_comment_id IS NULL
        ORDER BY cc.created_at DESC";

$result = mysqli_query($conn, $sql);

if (!$result) {
    echo json_encode([
        'success' => false,
        'error' => 'Database query failed: ' . mysqli_error($conn)
    ]);
    exit;
}

$comments = [];
$commentIds = [];

// Fetch all top-level comments
while ($row = mysqli_fetch_assoc($result)) {
    $commentIds[] = (int)$row['id'];
    $comments[] = [
        'id' => (int)$row['id'],
        'post_id' => (int)$row['post_id'],
        'uni_id' => $row['uni_id'],
        'parent_comment_id' => $row['parent_comment_id'] ? (int)$row['parent_comment_id'] : null,
        'author' => $row['author_name'],
        'author_email' => $row['author_email'],
        'content' => $row['content'],
        'help_count' => (int)$row['help_count'],
        'created_at' => $row['created_at'],
        'updated_at' => $row['updated_at'],
        'replies' => [] // Will be populated below
    ];
}

// If we have comments, fetch their replies
if (!empty($commentIds)) {
    $idsStr = implode(',', $commentIds);
    $repliesSql = "SELECT 
                        cc.id,
                        cc.post_id,
                        cc.uni_id,
                        cc.parent_comment_id,
                        cc.content,
                        cc.created_at,
                        cc.updated_at,
                        uc.name as author_name,
                        uc.email as author_email,
                        (SELECT COUNT(*) FROM help_actions WHERE comment_id = cc.id) as help_count
                    FROM community_comments cc
                    INNER JOIN user_credentials uc ON cc.uni_id = uc.uni_id
                    WHERE cc.post_id = $post_id AND cc.parent_comment_id IN ($idsStr)
                    ORDER BY cc.created_at DESC";
    
    $repliesResult = mysqli_query($conn, $repliesSql);
    
    if ($repliesResult) {
        // Create a map of comments by ID for quick lookup
        $commentsMap = [];
        foreach ($comments as &$comment) {
            $commentsMap[$comment['id']] = &$comment;
        }
        
        // Add replies to their parent comments
        while ($replyRow = mysqli_fetch_assoc($repliesResult)) {
            $parentId = (int)$replyRow['parent_comment_id'];
            if (isset($commentsMap[$parentId])) {
                $commentsMap[$parentId]['replies'][] = [
                    'id' => (int)$replyRow['id'],
                    'post_id' => (int)$replyRow['post_id'],
                    'uni_id' => $replyRow['uni_id'],
                    'parent_comment_id' => (int)$replyRow['parent_comment_id'],
                    'author' => $replyRow['author_name'],
                    'author_email' => $replyRow['author_email'],
                    'content' => $replyRow['content'],
                    'help_count' => (int)$replyRow['help_count'],
                    'created_at' => $replyRow['created_at'],
                    'updated_at' => $replyRow['updated_at'],
                ];
            }
        }
    }
}

echo json_encode([
    'success' => true,
    'comments' => $comments,
    'count' => count($comments)
]);

mysqli_close($conn);
?>

