<?php
/**
 * Get account data for a user by uni_id (PHP version - no Flask dependency)
 * Returns balance, equity, PnL, etc. for real-time frontend updates.
 * 
 * CACHED: Results cached for 3 seconds to reduce database load
 * 
 * GET: ?uni_id=xxx
 * Returns: {"success": true, "accounts": [...], "totals": {...}, "timestamp": "..."}
 */

// Turn off error display to prevent HTML output
ini_set('display_errors', 0);
error_reporting(E_ALL);

// Start output buffering
ob_start();

// Return JSON on any uncaught exception so 500 responses have a body (helps debugging)
set_exception_handler(function (Throwable $e) {
    if (ob_get_length() !== false) {
        @ob_clean();
    }
    if (!headers_sent()) {
        header('Content-Type: application/json');
        header('Access-Control-Allow-Origin: *');
        http_response_code(500);
    }
    echo json_encode([
        'success' => false,
        'error' => 'Server error: ' . $e->getMessage(),
        'file' => basename($e->getFile()),
        'line' => $e->getLine(),
    ]);
    exit;
});

// CORS headers - MUST be set before any output
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Max-Age: 86400'); // Cache preflight for 24 hours

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    if (ob_get_length() !== false) {
        @ob_clean();
    }
    http_response_code(200);
    exit;
}

require_once '../conn.php';

// Safe buffer clear (ob_clean on empty buffer can throw in PHP 8+)
$safe_ob_clean = function () {
    if (ob_get_length() !== false) {
        @ob_clean();
    }
};

// Check connection before any mysqli_* calls (avoid fatal error when $conn is false)
if (!$conn) {
    $safe_ob_clean();
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Database connection failed'
    ]);
    exit;
}

$uni_id = isset($_GET['uni_id']) ? mysqli_real_escape_string($conn, trim($_GET['uni_id'])) : null;

if (!$uni_id) {
    $safe_ob_clean();
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Missing required parameter: uni_id'
    ]);
    exit;
}

// In-memory cache (for single-server setup)
// Cache TTL: 3 seconds (balances update every 30s, so 3s cache is safe)
static $cache = [];
static $cache_time = [];
$cache_ttl = 3; // seconds

$cache_key = "account_data_{$uni_id}";

// Check cache
if (isset($cache[$cache_key]) && isset($cache_time[$cache_key])) {
    $cache_age = time() - $cache_time[$cache_key];
    if ($cache_age < $cache_ttl) {
        // Return cached result
        $safe_ob_clean();
        echo json_encode($cache[$cache_key]);
        mysqli_close($conn);
        exit;
    }
}

// Get and clear any deleted-broker notices (for toast: "API might be invalid, password may be wrong")
$deleted_notices = [];
$tbl_check = mysqli_query($conn, "SHOW TABLES LIKE 'deleted_broker_notices'");
if ($tbl_check && mysqli_num_rows($tbl_check) > 0) {
    $esc_uni = mysqli_real_escape_string($conn, $uni_id);
    $notice_res = mysqli_query($conn, "SELECT account_name, reason FROM deleted_broker_notices WHERE uni_id = '$esc_uni' ORDER BY created_at ASC");
    if ($notice_res) {
        while ($nr = mysqli_fetch_assoc($notice_res)) {
            $deleted_notices[] = [
                'accountName' => $nr['account_name'],
                'reason' => $nr['reason'] ?? 'invalid_api',
                'message' => 'API might be invalid or password may be wrong. Account was removed.',
            ];
        }
        mysqli_free_result($notice_res);
    }
    mysqli_query($conn, "DELETE FROM deleted_broker_notices WHERE uni_id = '$esc_uni'");
}

// Discover which columns exist in accounts (handles minimal schema from connect_broker vs full schema)
$describe = mysqli_query($conn, "DESCRIBE accounts");
if (!$describe) {
    $safe_ob_clean();
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => mysqli_error($conn),
        'hint' => 'Table accounts may not exist. Create it or run migrations.'
    ]);
    exit;
}
$allowed_cols = ['id', 'uni_id', 'name', 'email', 'balance', 'deposit_amount', 'initial_deposit', 'unrealized_pnl', 'equity', 'currency_type'];
$existing_cols = [];
while ($dr = mysqli_fetch_assoc($describe)) {
    $col = $dr['Field'];
    if (in_array($col, $allowed_cols, true)) {
        $existing_cols[] = $col;
    }
}
mysqli_free_result($describe);

// Require at least id, uni_id for the frontend
if (!in_array('id', $existing_cols, true) || !in_array('uni_id', $existing_cols, true)) {
    $safe_ob_clean();
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Table accounts must have at least id and uni_id columns.',
        'hint' => 'Run migrations or create the accounts table with required columns.'
    ]);
    exit;
}

$select_list = implode(', ', $existing_cols);
$sql = "SELECT {$select_list} FROM accounts WHERE uni_id = '{$uni_id}'";
$result = mysqli_query($conn, $sql);

if (!$result) {
    $safe_ob_clean();
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => mysqli_error($conn),
        'hint' => 'Check table accounts and uni_id.'
    ]);
    exit;
}

$user_accounts = [];
while ($row = mysqli_fetch_assoc($result)) {
    $accountId = (int) $row['id'];
    
    // Calculate realized_pnl from trades table (SUM of pnl column)
    $realizedPnlSql = "SELECT COALESCE(SUM(pnl), 0) AS total_pnl FROM trades WHERE account_id = {$accountId}";
    $realizedPnlResult = mysqli_query($conn, $realizedPnlSql);
    $realizedPnl = 0.0;
    if ($realizedPnlResult) {
        $realizedPnlRow = mysqli_fetch_assoc($realizedPnlResult);
        $realizedPnl = isset($realizedPnlRow['total_pnl']) ? (float) $realizedPnlRow['total_pnl'] : 0.0;
        mysqli_free_result($realizedPnlResult);
    }
    
    $user_accounts[] = [
        'id' => $accountId,
        'uni_id' => $row['uni_id'] ?? '',
        'name' => $row['name'] ?? '',
        'email' => $row['email'] ?? '',
        'balance' => floatval($row['balance'] ?? 0),
        'deposit_amount' => floatval($row['deposit_amount'] ?? 0),
        'initial_deposit' => floatval($row['initial_deposit'] ?? $row['deposit_amount'] ?? 0),
        'unrealized_pnl' => floatval($row['unrealized_pnl'] ?? 0),
        'realized_pnl' => $realizedPnl,  // Always calculated from trades table
        'equity' => floatval($row['equity'] ?? 0),
        'currency_type' => $row['currency_type'] ?? 'USD'
    ];
}

if (empty($user_accounts)) {
    $response = [
        'success' => true,
        'accounts' => [],
        'deleted_notices' => $deleted_notices,
        'totals' => [
            'balance' => 0.0,
            'deposit_amount' => 0.0,
            'initial_deposit' => 0.0,
            'unrealized_pnl' => 0.0,
            'realized_pnl' => 0.0,
            'equity' => 0.0,
            'currency_type' => 'USD'
        ],
        'timestamp' => date('Y-m-d\TH:i:s\Z')
    ];
    
    // Cache empty result too
    $cache[$cache_key] = $response;
    $cache_time[$cache_key] = time();
    
    // Clear output buffer before sending JSON
    $safe_ob_clean();
    
    echo json_encode($response);
    mysqli_close($conn);
    exit;
}

// Calculate totals across all user accounts
$total_balance = 0.0;
$total_deposit_amount = 0.0;
$total_initial_deposit = 0.0;
$total_unrealized_pnl = 0.0;
$total_realized_pnl = 0.0;
$total_equity = 0.0;
$currency_type = 'USD';

foreach ($user_accounts as $account) {
    $total_balance += $account['balance'];
    $total_deposit_amount += $account['deposit_amount'];
    $total_initial_deposit += $account['initial_deposit'];
    $total_unrealized_pnl += $account['unrealized_pnl'];
    $total_realized_pnl += $account['realized_pnl'];
    $total_equity += $account['equity'];
    if ($currency_type === 'USD' && !empty($account['currency_type'])) {
        $currency_type = $account['currency_type'];
    }
}

$response = [
    'success' => true,
    'accounts' => $user_accounts,
    'deleted_notices' => $deleted_notices,
    'totals' => [
        'balance' => $total_balance,
        'deposit_amount' => $total_deposit_amount,
        'initial_deposit' => $total_initial_deposit,
        'unrealized_pnl' => $total_unrealized_pnl,
        'realized_pnl' => $total_realized_pnl,
        'equity' => $total_equity,
        'currency_type' => $currency_type
    ],
    'timestamp' => date('Y-m-d\TH:i:s\Z')
];

// Cache the result
$cache[$cache_key] = $response;
$cache_time[$cache_key] = time();

// Clear output buffer before sending JSON
$safe_ob_clean();

echo json_encode($response);

mysqli_close($conn);
exit;
?>
