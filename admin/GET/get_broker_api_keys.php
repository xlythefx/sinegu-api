<?php
/**
 * Admin: every stored broker account for one broker, with the owning user.
 *
 * GET /admin/GET/get_broker_api_keys.php?broker=capital|binance|ig
 *
 * Returns:
 *   { success: true, broker, accounts: [ {
 *       uni_id, owner_name, owner_email,
 *       id, name, api_key, broker_accountID,
 *       demo, enabled, created_at, deleted_at,
 *       has_secret, has_password
 *   } ] }
 *   { success: false, message }
 *
 * `api_key` is returned in full — it already is by accounts_list.php, and the
 * Binance row is *identified* by it (binance_accounts has no `id`). The
 * `secret_key` / `password` values are never returned here; only 0/1 flags. The
 * real values come one account at a time from get_broker_credentials.php.
 *
 * Soft-deleted accounts are included on purpose so an admin can audit retired
 * keys — `deleted_at` is non-null on those and the caller badges them.
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

require_once __DIR__ . '/../../conn.php';

if (!$conn) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit;
}

$broker = isset($_GET['broker']) ? strtolower(trim((string) $_GET['broker'])) : '';
if (!in_array($broker, ['capital', 'binance', 'ig', 'mexc'], true)) {
    mysqli_close($conn);
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'broker must be capital, binance, ig, or mexc']);
    exit;
}

/*
 * The three account tables differ in ways that matter here:
 *   accounts         — `id` PK, email + plaintext password, NO secret_key
 *   binance_accounts — `api_key` IS the PK (no id), secret_key, no email/password
 *   ig_accounts      — `id` PK, email + password AND secret_key
 * So the select list is assembled per broker rather than shared.
 */
if ($broker === 'capital') {
    $table = 'accounts';
 *   mexc_accounts    — same shape as binance_accounts (column-for-column twin)
    $idExpr       = 'a.id';
    $emailExpr    = 'a.email';
    $brokerAcctEx = 'a.broker_accountID';
    $hasSecretEx  = '0';
    $hasPassEx    = "(a.password IS NOT NULL AND a.password <> '')";
} elseif ($broker === 'binance' || $broker === 'mexc') {
    $table = $broker === 'mexc' ? 'mexc_accounts' : 'binance_accounts';
    $idExpr       = 'NULL';
    $emailExpr    = "''";
    $brokerAcctEx = 'NULL';
    $hasSecretEx  = "(a.secret_key IS NOT NULL AND a.secret_key <> '')";
    $hasPassEx    = '0';
} else {
    $table = 'ig_accounts';
    $idExpr       = 'a.id';
    $emailExpr    = 'a.email';
    $brokerAcctEx = 'a.broker_accountID';
    $hasSecretEx  = "(a.secret_key IS NOT NULL AND a.secret_key <> '')";
    $hasPassEx    = "(a.password IS NOT NULL AND a.password <> '')";
}

/*
 * Active rows first, newest first within each group.
 *
 * The soft-delete state is read from `deleted_at IS NULL` alone — never compared
 * against the zero datetime literal, which MySQL 8 rejects outright in strict
 * mode (Incorrect DATETIME value). See the same note in
 * get_manual_trade_targets.php; a zero date cannot be stored in this column on
 * MySQL 8 anyway, so the comparison could never match a row.
 */
$sql = "
    SELECT
        a.uni_id,
        COALESCE(NULLIF(uc.name, ''), NULLIF(a.name, ''), a.uni_id) AS owner_name,
        COALESCE(NULLIF(uc.email, ''), NULLIF({$emailExpr}, ''), '') AS owner_email,
        {$idExpr}       AS account_id,
        a.name          AS account_name,
        a.api_key,
        {$emailExpr}    AS account_email,
        {$brokerAcctEx} AS broker_accountID,
        a.demo,
        a.enabled,
        a.created_at,
        a.deleted_at,
        {$hasSecretEx}  AS has_secret,
        {$hasPassEx}    AS has_password
    FROM {$table} a
    LEFT JOIN user_credentials uc ON uc.uni_id = a.uni_id
    ORDER BY (a.deleted_at IS NULL) DESC, a.created_at DESC
";

$result = mysqli_query($conn, $sql);
if (!$result) {
    $err = mysqli_error($conn);
    mysqli_close($conn);
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to fetch accounts: ' . $err]);
    exit;
}

$accounts = [];
while ($row = mysqli_fetch_assoc($result)) {
    $accounts[] = [
        'uni_id'           => $row['uni_id'],
        'owner_name'       => $row['owner_name'],
        'owner_email'      => $row['owner_email'],
        // Null for Binance, whose api_key is the primary key.
        'id'               => isset($row['account_id']) ? (int) $row['account_id'] : null,
        'name'             => $row['account_name'] ?? '',
        'api_key'          => $row['api_key'] ?? '',
        'email'            => $row['account_email'] ?? '',
        'broker_accountID' => $row['broker_accountID'] ?? null,
        'demo'             => (int) ($row['demo'] ?? 0),
        'enabled'          => (int) ($row['enabled'] ?? 1),
        'created_at'       => $row['created_at'] ?? null,
        'deleted_at'       => $row['deleted_at'] ?? null,
        'has_secret'       => (int) ($row['has_secret'] ?? 0),
        'has_password'     => (int) ($row['has_password'] ?? 0),
    ];
}
mysqli_free_result($result);
mysqli_close($conn);

echo json_encode([
    'success'  => true,
    'broker'   => $broker,
    'accounts' => $accounts,
]);
