<?php
/**
 * Admin: Get community posts by a specific user (author).
 * GET /admin/GET/get_user_posts.php?uni_id=...
 * Returns: posts array
 */

ob_start();

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    ob_end_clean();
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$uni_id = isset($_GET['uni_id']) ? trim($_GET['uni_id']) : '';

if (empty($uni_id)) {
    ob_end_clean();
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'uni_id is required']);
    exit;
}

require_once __DIR__ . '/../../conn.php';

if (!$conn) {
    ob_end_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit;
}

// community_posts: id, uni_id, title, content, category, status, post_type, course_level (if exists), interactions_count, created_at, updated_at, likes_count
$cols = ['id', 'uni_id', 'title', 'content', 'category', 'status', 'post_type', 'interactions_count', 'created_at', 'updated_at', 'likes_count'];
$desc = mysqli_query($conn, "DESCRIBE community_posts");
if ($desc) {
    $hasCourseLevel = false;
    while ($dr = mysqli_fetch_assoc($desc)) {
        if ($dr['Field'] === 'course_level') {
            $hasCourseLevel = true;
            break;
        }
    }
    if ($hasCourseLevel) {
        array_splice($cols, 7, 0, ['course_level']);
    }
}

$selectList = implode(', ', $cols);
$sql = "SELECT $selectList FROM community_posts WHERE uni_id = ? ORDER BY created_at DESC";

$posts = [];

if ($stmt = mysqli_prepare($conn, $sql)) {
    mysqli_stmt_bind_param($stmt, 's', $uni_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($result)) {
        $post = [
            'id' => (int) $row['id'],
            'uni_id' => $row['uni_id'],
            'title' => $row['title'],
            'content' => $row['content'],
            'category' => $row['category'],
            'status' => $row['status'],
            'post_type' => $row['post_type'] ?? 'general',
            'interactions_count' => (int) ($row['interactions_count'] ?? 0),
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
            'likes_count' => (int) ($row['likes_count'] ?? 0),
        ];
        if (isset($row['course_level'])) {
            $post['course_level'] = $row['course_level'];
        }
        $post['attachments'] = [];
        $posts[] = $post;
    }
    mysqli_stmt_close($stmt);
}

// Fetch attachments for all post IDs (community_post_attachments: id, post_id, file_path, file_type, file_name, ...)
$postIds = array_column($posts, 'id');
if (!empty($postIds)) {
    $tbl_check = mysqli_query($conn, "SHOW TABLES LIKE 'community_post_attachments'");
    if ($tbl_check && mysqli_num_rows($tbl_check) > 0) {
        $placeholders = implode(',', array_fill(0, count($postIds), '?'));
        $types = str_repeat('i', count($postIds));
        $attSql = "SELECT id, post_id, file_path, file_type, file_name, mime_type FROM community_post_attachments WHERE post_id IN ($placeholders) ORDER BY post_id, id";
        if ($stmt = mysqli_prepare($conn, $attSql)) {
            $bindParams = array_merge([$types], $postIds);
            $refs = [];
            foreach ($bindParams as $k => $v) {
                $refs[$k] = &$bindParams[$k];
            }
            call_user_func_array([$stmt, 'bind_param'], $refs);
            mysqli_stmt_execute($stmt);
            $aResult = mysqli_stmt_get_result($stmt);
            $attachmentsByPost = [];
            while ($aRow = mysqli_fetch_assoc($aResult)) {
                $pid = (int) $aRow['post_id'];
                if (!isset($attachmentsByPost[$pid])) {
                    $attachmentsByPost[$pid] = [];
                }
                $attachmentsByPost[$pid][] = [
                    'id' => (int) $aRow['id'],
                    'post_id' => $pid,
                    'file_path' => $aRow['file_path'],
                    'file_type' => $aRow['file_type'] ?? 'image',
                    'file_name' => $aRow['file_name'] ?? '',
                    'mime_type' => $aRow['mime_type'] ?? '',
                ];
            }
            mysqli_stmt_close($stmt);
            foreach ($posts as &$p) {
                $p['attachments'] = $attachmentsByPost[$p['id']] ?? [];
            }
            unset($p);
        }
    }
}

ob_end_clean();
echo json_encode([
    'success' => true,
    'posts' => $posts,
]);
mysqli_close($conn);
