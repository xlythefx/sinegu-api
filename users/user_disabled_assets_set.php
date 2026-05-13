<?php
/**
 * Add or remove a user_disabled_assets row (user opts out / opts back in).
 * POST JSON: { "uni_id": "...", "ticker": "BTCUSD", "disabled": true }
 * - disabled true  -> INSERT (ignore duplicate)
 * - disabled false -> DELETE
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$raw = file_get_contents('php://input');
$body = json_decode($raw, true);

if (!is_array($body)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid JSON body']);
    exit;
}

$uni_id = isset($body['uni_id']) ? trim((string) $body['uni_id']) : '';
$ticker = isset($body['ticker']) ? trim(strtoupper((string) $body['ticker'])) : '';
$disabled = isset($body['disabled']) ? (bool) $body['disabled'] : null;

if ($uni_id === '' || $ticker === '' || $disabled === null) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'uni_id, ticker, and disabled (boolean) are required',
    ]);
    exit;
}

require_once __DIR__ . '/../conn.php';

if (!$conn) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit;
}

$chk = mysqli_prepare($conn, 'SELECT uni_id FROM user_credentials WHERE uni_id = ? LIMIT 1');
mysqli_stmt_bind_param($chk, 's', $uni_id);
mysqli_stmt_execute($chk);
$cr = mysqli_stmt_get_result($chk);
$okUser = $cr && mysqli_fetch_assoc($cr);
mysqli_stmt_close($chk);

if (!$okUser) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Unknown user']);
    mysqli_close($conn);
    exit;
}

if ($disabled) {
    $ins = mysqli_prepare(
        $conn,
        'INSERT INTO user_disabled_assets (uni_id, ticker) VALUES (?, ?) ON DUPLICATE KEY UPDATE uni_id = uni_id'
    );
    mysqli_stmt_bind_param($ins, 'ss', $uni_id, $ticker);
    $ok = mysqli_stmt_execute($ins);
    $err = mysqli_stmt_error($ins);
    mysqli_stmt_close($ins);
    if (!$ok) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => $err ?: 'Insert failed']);
        mysqli_close($conn);
        exit;
    }
} else {
    $del = mysqli_prepare(
        $conn,
        'DELETE FROM user_disabled_assets WHERE uni_id = ? AND ticker = ?'
    );
    mysqli_stmt_bind_param($del, 'ss', $uni_id, $ticker);
    $ok = mysqli_stmt_execute($del);
    $err = mysqli_stmt_error($del);
    mysqli_stmt_close($del);
    if (!$ok) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => $err ?: 'Delete failed']);
        mysqli_close($conn);
        exit;
    }
}

mysqli_close($conn);
echo json_encode(['success' => true]);
