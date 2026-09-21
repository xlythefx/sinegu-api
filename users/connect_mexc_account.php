<?php
/**
 * Connect a MEXC USDT-M futures account for a user.
 * Expects POST JSON: { uni_id, name, api_key, secret_key, demo? }
 *
 * demo=1 routes the mexc-flask bot to https://futures.testnet.mexc.com (10,000 test
 * USDT, same keys as live — but the testnet REFUSES IP-bound keys with 406, so a demo
 * key must be created without "Link IP"). demo=0 (default) trades real money.
 *
 * mexc_accounts.api_key is the primary key and the row is soft-deleted on disconnect,
 * so reconnecting with the same key would collide on INSERT — an existing row (deleted
 * or not) is restored/updated in place instead, like users/connect_broker.php does for
 * Capital. The bot re-reads enabled accounts on a TTL (mexc_accounts_api.py), so no
 * listener notification is needed — the account is picked up on the next fetch.
 */

ob_start();

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    ob_clean();
    http_response_code(204);
    ob_end_flush();
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ob_clean();
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    ob_end_flush();
    exit;
}

require_once '../conn.php';

if (!$conn) {
    ob_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    ob_end_flush();
    exit;
}

function respond($conn, int $code, array $payload): void
{
    if ($conn) {
        mysqli_close($conn);
    }
    ob_clean();
    http_response_code($code);
    echo json_encode($payload);
    ob_end_flush();
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);

if (!is_array($data)) {
    respond($conn, 400, ['success' => false, 'message' => 'Invalid JSON']);
}

$uni_id     = isset($data['uni_id'])     ? trim((string) $data['uni_id'])     : '';
$name       = isset($data['name'])       ? trim((string) $data['name'])       : '';
$api_key    = isset($data['api_key'])    ? trim((string) $data['api_key'])    : '';
$secret_key = isset($data['secret_key']) ? trim((string) $data['secret_key']) : '';
$demo       = !empty($data['demo']) ? 1 : 0;

if ($uni_id === '' || $name === '' || $api_key === '' || $secret_key === '') {
    respond($conn, 400, ['success' => false, 'message' => 'uni_id, name, api_key and secret_key are required']);
}

// Verify the owner exists (FK would refuse the INSERT anyway, but say so clearly).
$checkUser = mysqli_prepare($conn, "SELECT uni_id FROM user_credentials WHERE uni_id = ? LIMIT 1");
if (!$checkUser) {
    respond($conn, 500, ['success' => false, 'message' => 'Failed to prepare: ' . mysqli_error($conn)]);
}
mysqli_stmt_bind_param($checkUser, "s", $uni_id);
mysqli_stmt_execute($checkUser);
$userRes = mysqli_stmt_get_result($checkUser);
$userFound = $userRes && mysqli_num_rows($userRes) > 0;
if ($userRes) mysqli_free_result($userRes);
mysqli_stmt_close($checkUser);
if (!$userFound) {
    respond($conn, 404, ['success' => false, 'message' => 'User not found']);
}

// Same API key already stored (e.g. previously disconnected) → restore it in place.
$checkKey = mysqli_prepare($conn, "SELECT uni_id, deleted_at FROM mexc_accounts WHERE api_key = ? LIMIT 1");
if (!$checkKey) {
    respond($conn, 500, ['success' => false, 'message' => 'Failed to prepare: ' . mysqli_error($conn)]);
}
mysqli_stmt_bind_param($checkKey, "s", $api_key);
mysqli_stmt_execute($checkKey);
$keyRes = mysqli_stmt_get_result($checkKey);
$existing = $keyRes ? mysqli_fetch_assoc($keyRes) : null;
if ($keyRes) mysqli_free_result($keyRes);
mysqli_stmt_close($checkKey);

if ($existing) {
    // An ACTIVE key that belongs to somebody else must not be silently re-homed.
    if ($existing['deleted_at'] === null && $existing['uni_id'] !== null && $existing['uni_id'] !== $uni_id) {
        respond($conn, 409, ['success' => false, 'message' => 'This API key is already connected to another account']);
    }

    $upd = mysqli_prepare(
        $conn,
        "UPDATE mexc_accounts
            SET deleted_at = NULL, enabled = 1, uni_id = ?, name = ?, secret_key = ?, demo = ?
          WHERE api_key = ?"
    );
    if (!$upd) {
        respond($conn, 500, ['success' => false, 'message' => 'Failed to prepare: ' . mysqli_error($conn)]);
    }
    mysqli_stmt_bind_param($upd, "sssis", $uni_id, $name, $secret_key, $demo, $api_key);
    if (!mysqli_stmt_execute($upd)) {
        $err = mysqli_error($conn);
        mysqli_stmt_close($upd);
        if (mysqli_errno($conn) === 1062) {
            respond($conn, 400, ['success' => false, 'message' => 'Account name already taken']);
        }
        respond($conn, 500, ['success' => false, 'message' => 'Failed to reconnect: ' . $err]);
    }
    mysqli_stmt_close($upd);
    respond($conn, 200, ['success' => true, 'message' => 'MEXC account reconnected', 'reconnected' => true]);
}

// mexc_accounts.name is globally UNIQUE (087) — check first so the user gets a readable
// message instead of a raw "Duplicate entry" error.
$checkName = mysqli_prepare($conn, "SELECT api_key FROM mexc_accounts WHERE name = ? LIMIT 1");
if (!$checkName) {
    respond($conn, 500, ['success' => false, 'message' => 'Failed to prepare: ' . mysqli_error($conn)]);
}
mysqli_stmt_bind_param($checkName, "s", $name);
mysqli_stmt_execute($checkName);
$nameRes = mysqli_stmt_get_result($checkName);
$nameTaken = $nameRes && mysqli_num_rows($nameRes) > 0;
if ($nameRes) mysqli_free_result($nameRes);
mysqli_stmt_close($checkName);
if ($nameTaken) {
    respond($conn, 400, ['success' => false, 'message' => 'Account name already taken']);
}

$stmt = mysqli_prepare(
    $conn,
    "INSERT INTO mexc_accounts
        (uni_id, api_key, secret_key, name, balance, initial_deposit, currency_type, demo, enabled, created_at, deleted_at)
     VALUES (?, ?, ?, ?, 0, 0, 'USDT', ?, 1, NOW(), NULL)"
);
if (!$stmt) {
    respond($conn, 500, ['success' => false, 'message' => 'Failed to prepare: ' . mysqli_error($conn)]);
}
mysqli_stmt_bind_param($stmt, "ssssi", $uni_id, $api_key, $secret_key, $name, $demo);

if (!mysqli_stmt_execute($stmt)) {
    $err = mysqli_error($conn);
    $errno = mysqli_errno($conn);
    mysqli_stmt_close($stmt);
    if ($errno === 1062) {
        respond($conn, 400, ['success' => false, 'message' => 'Account name already taken']);
    }
    respond($conn, 500, ['success' => false, 'message' => 'Failed to connect: ' . $err]);
}
mysqli_stmt_close($stmt);

respond($conn, 201, ['success' => true, 'message' => 'MEXC account connected']);
