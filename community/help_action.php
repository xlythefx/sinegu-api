<?php
/**
 * API Endpoint: Help Action
 * 
 * Handles toggling "This helped me" on posts and comments
 * 
 * Methods:
 * - POST: Add or remove help action
 * - GET: Check if current user has marked as helpful
 */

// Handle CORS preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    header('Access-Control-Max-Age: 86400');
    http_response_code(200);
    exit;
}

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

require_once __DIR__ . '/../conn.php';
require_once __DIR__ . '/update_last_activity.php';
require_once __DIR__ . '/calculate_monthly_activity.php';

if (!$conn) {
    echo json_encode([
        'success' => false,
        'error' => 'Database connection failed'
    ]);
    exit;
}

// Get uni_id from request (should come from authenticated session)
// For now, we'll accept it from POST/GET, but in production this should come from session
$uni_id = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $uni_id = $input['uni_id'] ?? $_POST['uni_id'] ?? null;
    $post_id = $input['post_id'] ?? $_POST['post_id'] ?? null;
    $comment_id = $input['comment_id'] ?? $_POST['comment_id'] ?? null;
    $action = $input['action'] ?? $_POST['action'] ?? 'add'; // 'add' or 'remove'
} else {
    // GET request - check status
    $uni_id = $_GET['uni_id'] ?? null;
    $post_id = $_GET['post_id'] ?? null;
    $comment_id = $_GET['comment_id'] ?? null;
}

if (!$uni_id) {
    echo json_encode([
        'success' => false,
        'error' => 'User ID (uni_id) is required'
    ]);
    exit;
}

$escapedUniId = mysqli_real_escape_string($conn, $uni_id);

// Validate that either post_id or comment_id is provided (but not both)
if (!$post_id && !$comment_id) {
    echo json_encode([
        'success' => false,
        'error' => 'Either post_id or comment_id is required'
    ]);
    exit;
}

if ($post_id && $comment_id) {
    echo json_encode([
        'success' => false,
        'error' => 'Cannot provide both post_id and comment_id'
    ]);
    exit;
}

// Determine action type and get helped user
$action_type = $post_id ? 'post_help' : 'comment_help';
$target_id = $post_id ? (int)$post_id : (int)$comment_id;
$escapedTargetId = mysqli_real_escape_string($conn, $target_id);

if ($post_id) {
    // Get post author
    $sql = "SELECT uni_id FROM community_posts WHERE id = $escapedTargetId LIMIT 1";
} else {
    // Get comment author
    $sql = "SELECT uni_id FROM community_comments WHERE id = $escapedTargetId LIMIT 1";
}

$result = mysqli_query($conn, $sql);

if (!$result || mysqli_num_rows($result) === 0) {
    echo json_encode([
        'success' => false,
        'error' => 'Post or comment not found'
    ]);
    exit;
}

$row = mysqli_fetch_assoc($result);
$helped_uni_id = $row['uni_id'];
$escapedHelpedUniId = mysqli_real_escape_string($conn, $helped_uni_id);

// Prevent self-help
if ($uni_id === $helped_uni_id) {
    echo json_encode([
        'success' => false,
        'error' => 'Cannot mark your own post or comment as helpful'
    ]);
    exit;
}

// Handle GET request - check if help action exists
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $checkSql = "SELECT id, created_at FROM help_actions 
                 WHERE helper_uni_id = '$escapedUniId' 
                 AND " . ($post_id ? "post_id = $escapedTargetId" : "comment_id = $escapedTargetId") . "
                 LIMIT 1";
    
    $checkResult = mysqli_query($conn, $checkSql);
    $exists = ($checkResult && mysqli_num_rows($checkResult) > 0);
    
    // Also get count of help actions for this post/comment
    $countSql = "SELECT COUNT(*) as count FROM help_actions 
                 WHERE " . ($post_id ? "post_id = $escapedTargetId" : "comment_id = $escapedTargetId");
    $countResult = mysqli_query($conn, $countSql);
    $countRow = mysqli_fetch_assoc($countResult);
    $helpCount = (int)$countRow['count'];
    
    echo json_encode([
        'success' => true,
        'is_helped' => $exists,
        'help_count' => $helpCount
    ]);
    exit;
}

// Handle POST request - add or remove help action
$action = strtolower($action);

if ($action === 'add') {
    // Check anti-gaming constraints before adding
    
    // 1. Check if help action already exists
    $checkSql = "SELECT id FROM help_actions 
                 WHERE helper_uni_id = '$escapedUniId' 
                 AND " . ($post_id ? "post_id = $escapedTargetId" : "comment_id = $escapedTargetId") . "
                 LIMIT 1";
    $checkResult = mysqli_query($conn, $checkSql);
    
    if ($checkResult && mysqli_num_rows($checkResult) > 0) {
        echo json_encode([
            'success' => false,
            'error' => 'Help action already exists'
        ]);
        exit;
    }
    
    // 2. Check max 2 help actions per same person this month
    $monthStart = date('Y-m-01');
    $monthEnd = date('Y-m-t');
    
    $countSql = "SELECT COUNT(*) as count FROM help_actions 
                 WHERE helper_uni_id = '$escapedUniId' 
                 AND helped_uni_id = '$escapedHelpedUniId'
                 AND verified = 1
                 AND DATE(created_at) >= '$monthStart'
                 AND DATE(created_at) <= '$monthEnd'";
    
    $countResult = mysqli_query($conn, $countSql);
    $countRow = mysqli_fetch_assoc($countResult);
    $existingCount = (int)$countRow['count'];
    
    if ($existingCount >= 2) {
        echo json_encode([
            'success' => false,
            'error' => 'Maximum 2 help actions per person per month reached'
        ]);
        exit;
    }
    
    // 3. Insert help action
    $insertSql = "INSERT INTO help_actions 
                  (helper_uni_id, helped_uni_id, " . ($post_id ? "post_id" : "comment_id") . ", action_type, verified)
                  VALUES ('$escapedUniId', '$escapedHelpedUniId', $escapedTargetId, '$action_type', 1)";
    
    $insertResult = mysqli_query($conn, $insertSql);
    
    if (!$insertResult) {
        echo json_encode([
            'success' => false,
            'error' => 'Failed to create help action: ' . mysqli_error($conn)
        ]);
        exit;
    }
    
    $help_action_id = mysqli_insert_id($conn);
    
    // Update last_activity for both users
    updateUserLastActivity($conn, $uni_id);
    updateUserLastActivity($conn, $helped_uni_id);
    
    // Recalculate monthly activity for helper
    $currentMonth = date('Y-m');
    calculateMonthlyActivity($conn, $uni_id, $currentMonth);
    
    // Get updated help count
    $countSql = "SELECT COUNT(*) as count FROM help_actions 
                 WHERE " . ($post_id ? "post_id = $escapedTargetId" : "comment_id = $escapedTargetId");
    $countResult = mysqli_query($conn, $countSql);
    $countRow = mysqli_fetch_assoc($countResult);
    $helpCount = (int)$countRow['count'];
    
    echo json_encode([
        'success' => true,
        'help_action_id' => $help_action_id,
        'action' => 'added',
        'helped_user' => $helped_uni_id,
        'help_count' => $helpCount,
        'message' => 'Help action recorded'
    ]);
    
} elseif ($action === 'remove') {
    // Remove help action
    $deleteSql = "DELETE FROM help_actions 
                   WHERE helper_uni_id = '$escapedUniId' 
                   AND " . ($post_id ? "post_id = $escapedTargetId" : "comment_id = $escapedTargetId") . "
                   LIMIT 1";
    
    $deleteResult = mysqli_query($conn, $deleteSql);
    
    if (!$deleteResult) {
        echo json_encode([
            'success' => false,
            'error' => 'Failed to remove help action: ' . mysqli_error($conn)
        ]);
        exit;
    }
    
    // Update last_activity for helper (not for helped user on removal)
    updateUserLastActivity($conn, $uni_id);
    
    // Recalculate monthly activity for helper
    $currentMonth = date('Y-m');
    calculateMonthlyActivity($conn, $uni_id, $currentMonth);
    
    // Get updated help count
    $countSql = "SELECT COUNT(*) as count FROM help_actions 
                   WHERE " . ($post_id ? "post_id = $escapedTargetId" : "comment_id = $escapedTargetId");
    $countResult = mysqli_query($conn, $countSql);
    $countRow = mysqli_fetch_assoc($countResult);
    $helpCount = (int)$countRow['count'];
    
    echo json_encode([
        'success' => true,
        'action' => 'removed',
        'help_count' => $helpCount,
        'message' => 'Help action removed'
    ]);
    
} else {
    echo json_encode([
        'success' => false,
        'error' => 'Invalid action. Use "add" or "remove"'
    ]);
}

mysqli_close($conn);
?>

