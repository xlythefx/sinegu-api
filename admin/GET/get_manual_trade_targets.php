<?php
/**
 * Admin: users/accounts eligible for manual trade targeting by broker.
 * GET /admin/GET/get_manual_trade_targets.php?broker=Capital.com|Binance|ig.com
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
require_once __DIR__ . '/../../includes/user_types.php';

if (!$conn) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit;
}

$broker = isset($_GET['broker']) ? trim((string) $_GET['broker']) : '';
$allowed = ['Capital.com', 'Binance', 'ig.com', 'MEXC'];
if ($broker !== '' && !in_array($broker, $allowed, true)) {
    mysqli_close($conn);
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid broker']);
    exit;
}

function target_rows_for(mysqli $conn, string $broker): array {
    if ($broker === 'Capital.com') {
        $table = 'accounts';
        $emailExpr = "NULLIF(MIN(a.email), '')";
    } elseif ($broker === 'Binance') {
        $table = 'binance_accounts';
        $emailExpr = "''";
    } elseif ($broker === 'MEXC') {
        $table = 'mexc_accounts';
        $emailExpr = "''";
    } else {
        $table = 'ig_accounts';
        $emailExpr = "NULLIF(MIN(a.email), '')";
    }

    $brokerEsc = mysqli_real_escape_string($conn, $broker);

    // Account type, so the Binance test feed can list testers only (and the main
    // feed leave them out). An aggregate keeps it out of GROUP BY; one row per uni_id.
    $typeExpr = user_credentials_has_type($conn)
        ? "COALESCE(MIN(uc.`type`), 'user')"
        : "'user'";
    $sql = "
        SELECT
            a.uni_id,
            '{$brokerEsc}' AS broker,
            COALESCE(NULLIF(uc.name, ''), NULLIF(MIN(a.name), ''), a.uni_id) AS display_name,
            COALESCE(NULLIF(uc.email, ''), {$emailExpr}) AS email,
            {$typeExpr} AS user_type,
            COUNT(*) AS account_count,
            SUM(CASE WHEN COALESCE(a.demo, 0) = 1 THEN 1 ELSE 0 END) AS demo_count,
            SUM(CASE WHEN COALESCE(a.demo, 0) = 1 THEN 0 ELSE 1 END) AS live_count
        FROM {$table} a
        LEFT JOIN user_credentials uc ON uc.uni_id = a.uni_id
        WHERE a.uni_id IS NOT NULL
          AND a.uni_id != ''
          AND COALESCE(a.enabled, 1) = 1
          AND (a.deleted_at IS NULL OR a.deleted_at = '0000-00-00 00:00:00')
        GROUP BY a.uni_id, uc.name, uc.email
        ORDER BY display_name ASC, email ASC
    ";

    $res = mysqli_query($conn, $sql);
    if (!$res) {
        throw new Exception(mysqli_error($conn));
    }

    $rows = [];
    while ($row = mysqli_fetch_assoc($res)) {
        $rows[] = [
            'uni_id' => $row['uni_id'],
            'display_name' => $row['display_name'],
            'email' => $row['email'],
            'broker' => $row['broker'],
            'type' => $row['user_type'],
            'account_count' => (int) $row['account_count'],
            'demo_count' => (int) $row['demo_count'],
            'live_count' => (int) $row['live_count'],
        ];
    }
    mysqli_free_result($res);
    return $rows;
}

try {
    $targets = [];
    if ($broker === '') {
        foreach ($allowed as $b) {
            $targets = array_merge($targets, target_rows_for($conn, $b));
        }
    } else {
        $targets = target_rows_for($conn, $broker);
    }

    mysqli_close($conn);
    echo json_encode(['success' => true, 'targets' => $targets]);
} catch (Exception $e) {
    mysqli_close($conn);
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
