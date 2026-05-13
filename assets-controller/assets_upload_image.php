<?php
/**
 * Upload asset image (custom ticker logo).
 * Accepts multipart/form-data with: image (file), asset_id (int).
 * Stores file under uploads/asset-images/ and writes the relative path to assets.asset_image.
 */

ob_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    http_response_code(204);
    ob_end_flush();
    exit;
}

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ob_clean();
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    ob_end_flush();
    exit;
}

require_once __DIR__ . '/../conn.php';
if (!$conn) {
    ob_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    ob_end_flush();
    exit;
}

$asset_id = isset($_POST['asset_id']) ? (int) $_POST['asset_id'] : 0;
if ($asset_id < 1) {
    ob_clean();
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'asset_id is required']);
    ob_end_flush();
    exit;
}

if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
    ob_clean();
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'No file uploaded or upload error']);
    ob_end_flush();
    exit;
}

$file = $_FILES['image'];
$allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/webp'];
$fileType = mime_content_type($file['tmp_name']);
if (!in_array($fileType, $allowedTypes, true)) {
    ob_clean();
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid file type. Only JPG, PNG, WebP allowed.']);
    ob_end_flush();
    exit;
}

if ($file['size'] > 5 * 1024 * 1024) {
    ob_clean();
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'File size exceeds 5MB']);
    ob_end_flush();
    exit;
}

$uploadDir = __DIR__ . '/../uploads/asset-images/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
    $ext = 'png';
}
$filename = 'asset_' . $asset_id . '_' . uniqid('', true) . '_' . time() . '.' . $ext;
$filepath = $uploadDir . $filename;

if (!move_uploaded_file($file['tmp_name'], $filepath)) {
    ob_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to save uploaded file']);
    ob_end_flush();
    exit;
}

$relativePath = 'uploads/asset-images/' . $filename;

// Delete old image if any
$old = null;
$selStmt = mysqli_prepare($conn, 'SELECT asset_image FROM assets WHERE asset_id = ? LIMIT 1');
if ($selStmt) {
    mysqli_stmt_bind_param($selStmt, 'i', $asset_id);
    mysqli_stmt_execute($selStmt);
    $res = mysqli_stmt_get_result($selStmt);
    if ($row = mysqli_fetch_assoc($res)) {
        $old = $row['asset_image'];
    }
    mysqli_stmt_close($selStmt);
}

$updStmt = mysqli_prepare($conn, 'UPDATE assets SET asset_image = ?, updated_at = NOW() WHERE asset_id = ?');
if (!$updStmt) {
    @unlink($filepath);
    mysqli_close($conn);
    ob_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to prepare update']);
    ob_end_flush();
    exit;
}
mysqli_stmt_bind_param($updStmt, 'si', $relativePath, $asset_id);
$ok = mysqli_stmt_execute($updStmt);
mysqli_stmt_close($updStmt);

if (!$ok) {
    $err = mysqli_error($conn);
    @unlink($filepath);
    mysqli_close($conn);
    ob_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'DB update failed: ' . $err]);
    ob_end_flush();
    exit;
}

if ($old && file_exists(__DIR__ . '/../' . $old)) {
    @unlink(__DIR__ . '/../' . $old);
}

mysqli_close($conn);
ob_clean();
echo json_encode([
    'success' => true,
    'asset_id' => $asset_id,
    'asset_image' => $relativePath,
]);
ob_end_flush();
