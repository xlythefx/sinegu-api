<?php
/**
 * Admin: reveal broker account credentials (api_key + secret_key, plus email/password for IG).
 *
 * GET /admin/GET/get_broker_credentials.php?broker=capital&id=<accounts.id>
 *                                              &broker=binance&api_key=<binance_accounts.api_key>
 *                                              &broker=ig&id=<ig_accounts.id>
 *
 * Returns:
 *   { success: true, broker, credentials: { ...broker-specific fields... } }
 *   { success: false, message }
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Admin-Password');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

/*
 * Password-gated, and this is the endpoint that most needs it: it is keyed by a
 * sequential integer, so while it was open anyone could walk id=1,2,3… and
 * harvest every broker api_key, secret_key and plaintext password in the system.
 * Gating the account *lists* was pointless while this stood open beside them.
 */
require_once __DIR__ . '/../../includes/admin_action_auth.php';
requireAdminActionPassword();

require_once __DIR__ . '/../../conn.php';

if (!$conn) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit;
}

$broker = isset($_GET['broker']) ? strtolower(trim((string)$_GET['broker'])) : '';
if (!in_array($broker, ['capital', 'binance', 'ig', 'mexc', 'bybit'], true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'broker must be capital, binance, ig, mexc, or bybit']);
    exit;
}

$creds = null;
$err = null;

if ($broker === 'capital') {
    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if (!$id) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'id is required for capital']);
        exit;
    }
    if ($stmt = mysqli_prepare($conn, 'SELECT id, name, email, api_key, password FROM accounts WHERE id = ?')) {
        mysqli_stmt_bind_param($stmt, 'i', $id);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $row = mysqli_fetch_assoc($res);
        mysqli_stmt_close($stmt);
        if ($row) {
            $creds = [
                'id'       => (int)$row['id'],
                'name'     => $row['name'],
                'email'    => $row['email'],
                'api_key'  => $row['api_key'],
                'password' => $row['password'] ?? null,
            ];
        } else {
            $err = 'Account not found';
        }
    } else {
        $err = 'Failed to prepare query';
    }
} elseif ($broker === 'binance' || $broker === 'mexc' || $broker === 'bybit') {
    // mexc_accounts / bybit_accounts are column-for-column twins of binance_accounts (api_key PK, secret_key).
    $acctTable = $broker === 'mexc' ? 'mexc_accounts' : ($broker === 'bybit' ? 'bybit_accounts' : 'binance_accounts');
    $api_key = isset($_GET['api_key']) ? trim((string)$_GET['api_key']) : '';
    if ($api_key === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => "api_key is required for $broker"]);
        exit;
    }
    if ($stmt = mysqli_prepare($conn, "SELECT api_key, secret_key, name FROM $acctTable WHERE api_key = ?")) {
        mysqli_stmt_bind_param($stmt, 's', $api_key);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $row = mysqli_fetch_assoc($res);
        mysqli_stmt_close($stmt);
        if ($row) {
            $creds = [
                'name'       => $row['name'],
                'api_key'    => $row['api_key'],
                'secret_key' => $row['secret_key'],
            ];
        } else {
            $err = ($broker === 'mexc' ? 'MEXC' : ($broker === 'bybit' ? 'Bybit' : 'Binance')) . ' account not found';
        }
    } else {
        $err = 'Failed to prepare query';
    }
} elseif ($broker === 'ig') {
    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if (!$id) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'id is required for ig']);
        exit;
    }
    if ($stmt = mysqli_prepare($conn, 'SELECT id, name, email, password, api_key, secret_key FROM ig_accounts WHERE id = ?')) {
        mysqli_stmt_bind_param($stmt, 'i', $id);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $row = mysqli_fetch_assoc($res);
        mysqli_stmt_close($stmt);
        if ($row) {
            $creds = [
                'id'         => (int)$row['id'],
                'name'       => $row['name'],
                'email'      => $row['email'],
                'password'   => $row['password'],
                'api_key'    => $row['api_key'],
                'secret_key' => $row['secret_key'],
            ];
        } else {
            $err = 'IG account not found';
        }
    } else {
        $err = 'Failed to prepare query';
    }
}

mysqli_close($conn);

if ($err) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => $err]);
    exit;
}

echo json_encode(['success' => true, 'broker' => $broker, 'credentials' => $creds]);
