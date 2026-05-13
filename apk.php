<?php
/**
 * APK (or any file) upload and download.
 * POST: form field "apk" (file) → saved as uploads/apk/sinegual-android.apk (overwrites).
 * GET: download that file.
 * Only .apk files are accepted; saved as sinegual-android.apk for the Download APP link.
 */

// CORS: allow all origins (download/upload from any domain)
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Accept');
header('Access-Control-Max-Age: 86400');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

$baseDir = __DIR__ . '/uploads/apk';
$filename = 'sinegual-android.apk';
$filepath = $baseDir . '/' . $filename;

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    if (is_file($filepath)) {
        header('Content-Length: ' . filesize($filepath));
        readfile($filepath);
    } else {
        http_response_code(404);
        echo 'File not found';
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

if (empty($_FILES['apk']) || $_FILES['apk']['error'] !== UPLOAD_ERR_OK) {
    $code = empty($_FILES['apk']) ? 0 : (int) $_FILES['apk']['error'];
    $msg = 'No file in request. Use form field name "apk".';
    if ($code === UPLOAD_ERR_INI_SIZE || $code === UPLOAD_ERR_FORM_SIZE) {
        $msg = 'File too large. Increase upload_max_filesize and post_max_size in php.ini.';
    } elseif ($code !== 0) {
        $msg = 'Upload error code: ' . $code;
    }
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $msg, 'code' => $code]);
    exit;
}

$uploadName = $_FILES['apk']['name'];
if (!is_string($uploadName) || strtolower(substr($uploadName, -4)) !== '.apk') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid file type. Use an APK file.']);
    exit;
}

if (!is_dir($baseDir)) {
    if (!@mkdir($baseDir, 0755, true)) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Could not create uploads/apk directory']);
        exit;
    }
}

$tmp = $_FILES['apk']['tmp_name'];
if (!is_uploaded_file($tmp)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid upload']);
    exit;
}

if (!@move_uploaded_file($tmp, $filepath)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Could not save file']);
    exit;
}

echo json_encode([
    'success' => true,
    'message' => 'File uploaded as ' . $filename,
]);
