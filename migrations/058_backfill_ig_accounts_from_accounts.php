<?php
/**
 * Optional one-time helper:
 * Copy existing IG rows from accounts into ig_accounts while preserving id values.
 * Usage: visit http://localhost/sinegu-api/migrations/058_backfill_ig_accounts_from_accounts.php
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../conn.php';

if (!$conn) {
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit;
}

$checkSql = "SELECT COUNT(*) AS cnt FROM INFORMATION_SCHEMA.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ig_accounts'";
$checkRes = mysqli_query($conn, $checkSql);
$exists = $checkRes && ((int) mysqli_fetch_assoc($checkRes)['cnt'] > 0);
if (!$exists) {
    echo json_encode(['success' => false, 'error' => 'ig_accounts table does not exist. Run 058 migration first.']);
    mysqli_close($conn);
    exit;
}

$sql = "INSERT INTO ig_accounts
        (id, uni_id, api_key, secret_key, name, email, password, balance, initial_deposit, currency_type, broker_accountID, ig_cst, ig_x_security_token, ig_session_last_used_at, demo, enabled, created_at, updated_at, deleted_at)
        SELECT
          a.id,
          a.uni_id,
          a.api_key,
          NULL AS secret_key,
          a.name,
          a.email,
          a.password,
          a.balance,
          a.initial_deposit,
          a.currency_type,
          a.broker_accountID,
          a.ig_cst,
          a.ig_x_security_token,
          a.ig_session_last_used_at,
          a.demo,
          a.enabled,
          COALESCE(a.created_at, NOW()) AS created_at,
          COALESCE(a.updated_at, NOW()) AS updated_at,
          a.deleted_at
        FROM accounts a
        WHERE a.api_key IS NOT NULL
          AND NOT EXISTS (
            SELECT 1 FROM ig_accounts i WHERE i.id = a.id
          )";

$ok = mysqli_query($conn, $sql);
if (!$ok) {
    echo json_encode(['success' => false, 'error' => mysqli_error($conn)]);
    mysqli_close($conn);
    exit;
}

echo json_encode([
    'success' => true,
    'message' => 'Backfill completed',
    'rows_inserted' => mysqli_affected_rows($conn),
]);
mysqli_close($conn);
