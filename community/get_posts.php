<?php
/**
 * API Endpoint: Get Posts
 * 
 * Fetches community posts with pagination and filtering
 * 
 * GET Parameters:
 * - page: Page number (default: 1)
 * - limit: Posts per page (default: 20)
 * - category: Filter by category (optional)
 * - sort: Sort by 'new', 'hot', 'top' (default: 'new')
 * - status: For admin: 'all' (accepted+pending), 'pending', 'rejected'; default = accepted only
 * - search or q: Search in title and content (optional)
 * - course_discussions=1: Filter to posts from authors with same course_level as requester (requires uni_id)
 * - uni_id: Requester's user id (required when course_discussions=1; frontend sends from localStorage)
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

$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$limit = isset($_GET['limit']) ? max(1, min(100, (int)$_GET['limit'])) : 20;
$category = isset($_GET['category']) && $_GET['category'] !== '' && $_GET['category'] !== 'all' ? mysqli_real_escape_string($conn, $_GET['category']) : null;
$sort = isset($_GET['sort']) ? mysqli_real_escape_string($conn, $_GET['sort']) : 'new';
$statusFilter = isset($_GET['status']) ? mysqli_real_escape_string($conn, $_GET['status']) : null;
$search = isset($_GET['search']) ? trim((string)$_GET['search']) : (isset($_GET['q']) ? trim((string)$_GET['q']) : '');
$courseDiscussions = isset($_GET['course_discussions']) && $_GET['course_discussions'] === '1';
$uniId = isset($_GET['uni_id']) ? trim((string)$_GET['uni_id']) : '';
$courseLevelParam = isset($_GET['course_level']) && $_GET['course_level'] !== '' ? trim((string)$_GET['course_level']) : null;
$postTypeFilter = isset($_GET['post_type']) && $_GET['post_type'] !== '' ? mysqli_real_escape_string($conn, $_GET['post_type']) : null;
if ($postTypeFilter !== null && !in_array($postTypeFilter, ['general', 'course_discussion'], true)) {
    $postTypeFilter = null;
}
$authorUniId = isset($_GET['author_uni_id']) && $_GET['author_uni_id'] !== '' ? mysqli_real_escape_string($conn, trim((string)$_GET['author_uni_id'])) : null;

$offset = ($page - 1) * $limit;

// Course-level filter: use explicit course_level param, or fall back to requester's level when course_discussions=1
$filterByCourseLevel = null;
$allowedLevels = ['first', 'second', 'third', 'fourth', 'fifth', 'sixth'];
if ($courseLevelParam !== null && in_array($courseLevelParam, $allowedLevels, true)) {
    // Frontend sends course_level for course tabs (First Course, Second Course, etc.)
    $filterByCourseLevel = mysqli_real_escape_string($conn, $courseLevelParam);
} elseif ($courseDiscussions && $uniId !== '') {
    // Legacy: resolve requester's course_level
    $uniIdEsc = mysqli_real_escape_string($conn, $uniId);
    $levelResult = mysqli_query($conn, "SELECT course_level FROM user_credentials WHERE uni_id = '$uniIdEsc' LIMIT 1");
    if ($levelResult && $row = mysqli_fetch_assoc($levelResult)) {
        $level = $row['course_level'];
        if ($level !== null && in_array($level, $allowedLevels, true)) {
            $filterByCourseLevel = mysqli_real_escape_string($conn, $level);
        }
    }
}

// Build WHERE clause: default = accepted only; all = accepted + pending (hide rejected); pending/rejected = that status only
$whereClause = "1=1";
if ($category) {
    $whereClause .= " AND cp.category = '$category'";
}
if ($statusFilter === 'pending') {
    $whereClause .= " AND cp.status = 'pending'";
} elseif ($statusFilter === 'rejected') {
    $whereClause .= " AND cp.status = 'rejected'";
} elseif ($statusFilter === 'all') {
    $whereClause .= " AND (cp.status = 'accepted' OR cp.status = 'pending')";
} else {
    $whereClause .= " AND cp.status = 'accepted'";
}
if ($search !== '') {
    $searchEsc = mysqli_real_escape_string($conn, $search);
    $searchLike = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $searchEsc) . '%';
    $whereClause .= " AND (cp.title LIKE '$searchLike' OR cp.content LIKE '$searchLike')";
}
if ($filterByCourseLevel !== null) {
    $whereClause .= " AND cp.course_level = '$filterByCourseLevel'";
}
if ($postTypeFilter !== null) {
    $whereClause .= " AND cp.post_type = '$postTypeFilter'";
}
if ($authorUniId !== null) {
    $whereClause .= " AND cp.uni_id = '$authorUniId'";
}

// Build ORDER BY clause
$orderBy = "cp.created_at DESC"; // Default: newest first
switch ($sort) {
    case 'hot':
        // Sort by interactions_count and recent activity
        $orderBy = "cp.interactions_count DESC, cp.created_at DESC";
        break;
    case 'top':
        // Sort by interactions_count only
        $orderBy = "cp.interactions_count DESC";
        break;
    case 'new':
    default:
        $orderBy = "cp.created_at DESC";
        break;
}

// Get posts with author information
$sql = "SELECT 
            cp.id,
            cp.uni_id,
            cp.title,
            cp.content,
            cp.category,
            cp.status,
            cp.post_type,
            cp.interactions_count,
            cp.created_at,
            cp.updated_at,
            uc.name as author_name,
            uc.email as author_email,
            (SELECT COUNT(*) FROM community_comments WHERE post_id = cp.id) as replies_count,
            (SELECT COUNT(*) FROM help_actions WHERE post_id = cp.id) as help_count
        FROM community_posts cp
        INNER JOIN user_credentials uc ON cp.uni_id = uc.uni_id
        WHERE $whereClause
        ORDER BY $orderBy
        LIMIT $limit OFFSET $offset";

$result = mysqli_query($conn, $sql);

if (!$result) {
    echo json_encode([
        'success' => false,
        'error' => 'Database query failed: ' . mysqli_error($conn)
    ]);
    exit;
}

$posts = [];
$postIds = [];
while ($row = mysqli_fetch_assoc($result)) {
    $postIds[] = (int)$row['id'];
    $posts[] = [
        'id' => (int)$row['id'],
        'uni_id' => $row['uni_id'],
        'title' => $row['title'],
        'content' => $row['content'],
        'category' => $row['category'],
        'status' => $row['status'] ?? 'accepted',
        'post_type' => $row['post_type'] ?? 'general',
        'author' => $row['author_name'],
        'author_email' => $row['author_email'],
        'replies' => (int)$row['replies_count'],
        'help_count' => (int)$row['help_count'],
        'interactions_count' => (int)$row['interactions_count'],
        'created_at' => $row['created_at'],
        'updated_at' => $row['updated_at'],
        'attachments' => []
    ];
}

// Fetch attachments for all posts in one query
if (!empty($postIds)) {
    $idsStr = implode(',', $postIds);
    $attachmentsSql = "SELECT post_id, id, file_path, file_type, file_name, file_size, mime_type 
                       FROM community_post_attachments 
                       WHERE post_id IN ($idsStr) 
                       ORDER BY post_id, id ASC";
    $attachmentsResult = mysqli_query($conn, $attachmentsSql);
    
    if ($attachmentsResult) {
        // Create a map of posts by ID for quick lookup
        $postsMap = [];
        foreach ($posts as &$post) {
            $postsMap[$post['id']] = &$post;
        }
        
        // Add attachments to their respective posts
        while ($attachmentRow = mysqli_fetch_assoc($attachmentsResult)) {
            $postId = (int)$attachmentRow['post_id'];
            if (isset($postsMap[$postId])) {
                $postsMap[$postId]['attachments'][] = [
                    'id' => (int)$attachmentRow['id'],
                    'file_path' => $attachmentRow['file_path'],
                    'file_type' => $attachmentRow['file_type'],
                    'file_name' => $attachmentRow['file_name'],
                    'file_size' => $attachmentRow['file_size'] ? (int)$attachmentRow['file_size'] : null,
                    'mime_type' => $attachmentRow['mime_type']
                ];
            }
        }
    }
}

// Get total count for pagination (use same JOIN as main query when filtering by course_level)
if ($filterByCourseLevel !== null) {
    $countSql = "SELECT COUNT(*) as total FROM community_posts cp INNER JOIN user_credentials uc ON cp.uni_id = uc.uni_id WHERE $whereClause";
} else {
    $countSql = "SELECT COUNT(*) as total FROM community_posts cp WHERE $whereClause";
}
$countResult = mysqli_query($conn, $countSql);
$countRow = mysqli_fetch_assoc($countResult);
$total = (int)$countRow['total'];

echo json_encode([
    'success' => true,
    'posts' => $posts,
    'pagination' => [
        'page' => $page,
        'limit' => $limit,
        'total' => $total,
        'total_pages' => ceil($total / $limit)
    ]
]);

mysqli_close($conn);
?>

