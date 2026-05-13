<?php
// community/like_post.php

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../conn.php';
require_once __DIR__ . '/update_last_activity.php';

if (!$conn) {
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Check if user has liked the post
    $post_id = $_GET['post_id'] ?? null;
    $uni_id = $_GET['uni_id'] ?? null;

    if (!$post_id || !$uni_id) {
        echo json_encode(['success' => false, 'error' => 'Missing post_id or uni_id']);
        exit;
    }

    $escapedPostId = mysqli_real_escape_string($conn, $post_id);
    $escapedUniId = mysqli_real_escape_string($conn, $uni_id);

    $checkSql = "SELECT id FROM community_post_likes WHERE post_id = '$escapedPostId' AND uni_id = '$escapedUniId' LIMIT 1";
    $result = mysqli_query($conn, $checkSql);

    if ($result) {
        $isLiked = mysqli_num_rows($result) > 0;
        
        // Get total likes count
        $countSql = "SELECT COUNT(*) as total FROM community_post_likes WHERE post_id = '$escapedPostId'";
        $countResult = mysqli_query($conn, $countSql);
        $countRow = mysqli_fetch_assoc($countResult);
        $likesCount = (int)$countRow['total'];

        echo json_encode([
            'success' => true,
            'is_liked' => $isLiked,
            'likes_count' => $likesCount
        ]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Failed to check like status']);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Only POST method is allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$post_id = $input['post_id'] ?? null;
$uni_id = $input['uni_id'] ?? null;
$action = $input['action'] ?? 'toggle'; // 'add', 'remove', or 'toggle'

if (!$post_id || !$uni_id) {
    echo json_encode(['success' => false, 'error' => 'Missing required fields: post_id, uni_id']);
    exit;
}

$escapedPostId = mysqli_real_escape_string($conn, $post_id);
$escapedUniId = mysqli_real_escape_string($conn, $uni_id);

// Check if post exists
$postCheckSql = "SELECT id FROM community_posts WHERE id = '$escapedPostId' LIMIT 1";
$postResult = mysqli_query($conn, $postCheckSql);
if (!$postResult || mysqli_num_rows($postResult) === 0) {
    echo json_encode(['success' => false, 'error' => 'Post not found']);
    exit;
}

// Check current like status
$checkSql = "SELECT id FROM community_post_likes WHERE post_id = '$escapedPostId' AND uni_id = '$escapedUniId' LIMIT 1";
$checkResult = mysqli_query($conn, $checkSql);
$isLiked = mysqli_num_rows($checkResult) > 0;

// Determine action
if ($action === 'toggle') {
    $action = $isLiked ? 'remove' : 'add';
}

if ($action === 'add' && !$isLiked) {
    // Add like
    $insertSql = "INSERT INTO community_post_likes (post_id, uni_id) VALUES ('$escapedPostId', '$escapedUniId')";
    if (mysqli_query($conn, $insertSql)) {
        // Update likes_count
        $updateCountSql = "UPDATE community_posts SET likes_count = likes_count + 1 WHERE id = '$escapedPostId'";
        mysqli_query($conn, $updateCountSql);
        
        // Update last_activity
        updateUserLastActivity($conn, $escapedUniId);
        
        // Get updated count
        $countSql = "SELECT COUNT(*) as total FROM community_post_likes WHERE post_id = '$escapedPostId'";
        $countResult = mysqli_query($conn, $countSql);
        $countRow = mysqli_fetch_assoc($countResult);
        $likesCount = (int)$countRow['total'];
        
        echo json_encode([
            'success' => true,
            'action' => 'added',
            'is_liked' => true,
            'likes_count' => $likesCount,
            'message' => 'Post liked successfully'
        ]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Failed to like post: ' . mysqli_error($conn)]);
    }
} elseif ($action === 'remove' && $isLiked) {
    // Remove like
    $deleteSql = "DELETE FROM community_post_likes WHERE post_id = '$escapedPostId' AND uni_id = '$escapedUniId'";
    if (mysqli_query($conn, $deleteSql)) {
        // Update likes_count
        $updateCountSql = "UPDATE community_posts SET likes_count = GREATEST(likes_count - 1, 0) WHERE id = '$escapedPostId'";
        mysqli_query($conn, $updateCountSql);
        
        // Update last_activity
        updateUserLastActivity($conn, $escapedUniId);
        
        // Get updated count
        $countSql = "SELECT COUNT(*) as total FROM community_post_likes WHERE post_id = '$escapedPostId'";
        $countResult = mysqli_query($conn, $countSql);
        $countRow = mysqli_fetch_assoc($countResult);
        $likesCount = (int)$countRow['total'];
        
        echo json_encode([
            'success' => true,
            'action' => 'removed',
            'is_liked' => false,
            'likes_count' => $likesCount,
            'message' => 'Like removed successfully'
        ]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Failed to remove like: ' . mysqli_error($conn)]);
    }
} else {
    echo json_encode([
        'success' => true,
        'action' => 'no_change',
        'is_liked' => $isLiked,
        'message' => 'No change needed'
    ]);
}

mysqli_close($conn);
?>

