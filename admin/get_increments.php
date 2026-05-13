<?php
/**
 * Get Increments Data
 *
 * Returns per-user increments per ticker with max values from assets table.
 * Each row includes `user_display_name` and `user_email` from `user_credentials`
 * (LEFT join on accounts.uni_id = user_credentials.uni_id).
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../conn.php';

if (!$conn) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Database connection failed'
    ]);
    exit();
}

try {
    // Get all positions grouped by account_id and ticker
    $positionsQuery = "SELECT 
                        p.account_id,
                        p.ticker,
                        COUNT(*) as increment_count,
                        a.name as account_name,
                        a.uni_id as user_id
                      FROM positions p
                      INNER JOIN accounts a ON p.account_id = a.id
                      GROUP BY p.account_id, p.ticker, a.name, a.uni_id
                      ORDER BY a.name, p.ticker";
    
    $positionsResult = mysqli_query($conn, $positionsQuery);
    
    if (!$positionsResult) {
        throw new Exception("Failed to fetch positions: " . mysqli_error($conn));
    }
    
    // Get max_increments from assets table
    $assetsQuery = "SELECT ticker, max_increments FROM assets WHERE enabled = 1";
    $assetsResult = mysqli_query($conn, $assetsQuery);
    
    if (!$assetsResult) {
        throw new Exception("Failed to fetch assets: " . mysqli_error($conn));
    }
    
    // Build max_increments lookup
    $maxIncrements = [];
    while ($row = mysqli_fetch_assoc($assetsResult)) {
        $maxIncrements[$row['ticker']] = (int)$row['max_increments'];
    }
    
    // Build increments data structure
    $incrementsData = [];
    
    while ($row = mysqli_fetch_assoc($positionsResult)) {
        $accountId = (int)$row['account_id'];
        $userId = $row['user_id'];
        $accountName = $row['account_name'];
        $ticker = $row['ticker'];
        $currentIncrements = (int)$row['increment_count'];
        $maxIncrementsForTicker = $maxIncrements[$ticker] ?? 10; // Default to 10 if not found
        
        // Group by user_id (uni_id)
        if (!isset($incrementsData[$userId])) {
            $incrementsData[$userId] = [
                'user_id' => $userId,
                'account_name' => $accountName,
                'account_id' => $accountId,
                'tickers' => []
            ];
        }
        
        $incrementsData[$userId]['tickers'][$ticker] = [
            'ticker' => $ticker,
            'current' => $currentIncrements,
            'max' => $maxIncrementsForTicker,
            'percentage' => $maxIncrementsForTicker > 0 
                ? round(($currentIncrements / $maxIncrementsForTicker) * 100, 1) 
                : 0
        ];
    }
    
    // Also include accounts that have no positions but exist in accounts table
    $allAccountsQuery = "SELECT id, uni_id, name FROM accounts WHERE enabled = 1";
    $allAccountsResult = mysqli_query($conn, $allAccountsQuery);
    
    if ($allAccountsResult) {
        while ($account = mysqli_fetch_assoc($allAccountsResult)) {
            $userId = $account['uni_id'];
            if (!isset($incrementsData[$userId])) {
                $incrementsData[$userId] = [
                    'user_id' => $userId,
                    'account_name' => $account['name'],
                    'account_id' => (int)$account['id'],
                    'tickers' => []
                ];
            }
        }
    }
    
    // Enrich each row with portal user name + email from user_credentials (keyed by uni_id)
    $credMap = [];
    $credRes = mysqli_query($conn, 'SELECT uni_id, name, email FROM user_credentials');
    if ($credRes) {
        while ($cr = mysqli_fetch_assoc($credRes)) {
            $credMap[$cr['uni_id']] = [
                'name' => $cr['name'],
                'email' => $cr['email'],
            ];
        }
        mysqli_free_result($credRes);
    }
    foreach ($incrementsData as $userId => &$entry) {
        if (isset($credMap[$userId])) {
            $entry['user_display_name'] = $credMap[$userId]['name'];
            $entry['user_email'] = $credMap[$userId]['email'];
        } else {
            $entry['user_display_name'] = null;
            $entry['user_email'] = null;
        }
    }
    unset($entry);

    // ── Broker activity & account age enrichment ─────────────────────────────
    // Used by the frontend to suppress new/inactive users from the discrepancy list.

    // (a) Capital.com accounts: earliest created_at per uni_id
    $capitalAgeMap = [];
    $capitalAgeRes = mysqli_query($conn,
        "SELECT uni_id, MIN(created_at) AS earliest_at
         FROM accounts
         WHERE enabled = 1
         GROUP BY uni_id"
    );
    if ($capitalAgeRes) {
        while ($r = mysqli_fetch_assoc($capitalAgeRes)) {
            $capitalAgeMap[$r['uni_id']] = $r['earliest_at'];
        }
        mysqli_free_result($capitalAgeRes);
    }

    // (b) Binance accounts: presence + earliest created_at per uni_id
    $binanceAcctMap = [];
    $binanceAcctRes = mysqli_query($conn,
        "SELECT uni_id, MIN(created_at) AS earliest_at
         FROM binance_accounts
         WHERE (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')
         GROUP BY uni_id"
    );
    if ($binanceAcctRes) {
        while ($r = mysqli_fetch_assoc($binanceAcctRes)) {
            $binanceAcctMap[$r['uni_id']] = $r['earliest_at'];
        }
        mysqli_free_result($binanceAcctRes);
    }

    // (c) Capital closed positions count per uni_id (from trades)
    $capitalClosedMap = [];
    $capitalClosedRes = mysqli_query($conn,
        "SELECT a.uni_id, COUNT(*) AS cnt
         FROM trades t
         JOIN accounts a ON a.id = t.account_id
         WHERE a.enabled = 1
           AND (t.date_closed IS NOT NULL OR COALESCE(t.closed_positions, 0) = 1)
         GROUP BY a.uni_id"
    );
    if ($capitalClosedRes) {
        while ($r = mysqli_fetch_assoc($capitalClosedRes)) {
            $capitalClosedMap[$r['uni_id']] = (int)$r['cnt'];
        }
        mysqli_free_result($capitalClosedRes);
    }

    // (d) Binance closed positions count per uni_id (from binance_pastpositions)
    $binancePosMap = [];
    $binancePosRes = mysqli_query($conn,
        "SELECT uni_id, COUNT(*) AS cnt
         FROM binance_pastpositions
         GROUP BY uni_id"
    );
    if ($binancePosRes) {
        while ($r = mysqli_fetch_assoc($binancePosRes)) {
            $binancePosMap[$r['uni_id']] = (int)$r['cnt'];
        }
        mysqli_free_result($binancePosRes);
    }

    // (e) IG accounts: presence + earliest created_at per uni_id
    $igAcctMap = [];
    $igAcctRes = mysqli_query($conn,
        "SELECT uni_id, MIN(created_at) AS earliest_at
         FROM ig_accounts
         WHERE (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')
         GROUP BY uni_id"
    );
    if ($igAcctRes) {
        while ($r = mysqli_fetch_assoc($igAcctRes)) {
            $igAcctMap[$r['uni_id']] = $r['earliest_at'];
        }
        mysqli_free_result($igAcctRes);
    }

    // (f) IG closed positions count per uni_id (joined through ig_accounts)
    $igPosMap = [];
    $igPosRes = mysqli_query($conn,
        "SELECT ia.uni_id, COUNT(*) AS cnt
         FROM ig_past_positions ipp
         JOIN ig_accounts ia ON ia.id = ipp.account_id
         WHERE (ia.deleted_at IS NULL OR ia.deleted_at = '0000-00-00 00:00:00')
         GROUP BY ia.uni_id"
    );
    if ($igPosRes) {
        while ($r = mysqli_fetch_assoc($igPosRes)) {
            $igPosMap[$r['uni_id']] = (int)$r['cnt'];
        }
        mysqli_free_result($igPosRes);
    }

    // Merge broker activity fields into each user entry
    foreach ($incrementsData as $userId => &$entry) {
        $hasCapital = isset($capitalAgeMap[$userId]);
        $hasBinance = isset($binanceAcctMap[$userId]);
        $hasIg      = isset($igAcctMap[$userId]);

        // Capital closed positions count (from trades table)
        $capitalPosCount = $capitalClosedMap[$userId] ?? 0;

        // Earliest created_at across all brokers this user has accounts in
        $candidates = [];
        if ($hasCapital && $capitalAgeMap[$userId]) $candidates[] = $capitalAgeMap[$userId];
        if ($hasBinance && $binanceAcctMap[$userId]) $candidates[] = $binanceAcctMap[$userId];
        if ($hasIg      && $igAcctMap[$userId])      $candidates[] = $igAcctMap[$userId];
        $earliestAt = count($candidates) > 0 ? min($candidates) : null;

        $entry['has_capital_accounts']       = $hasCapital;
        $entry['capital_positions_count']    = $capitalPosCount;
        $entry['has_binance_accounts']       = $hasBinance;
        $entry['binance_positions_count']    = $binancePosMap[$userId] ?? 0;
        $entry['has_ig_accounts']            = $hasIg;
        $entry['ig_positions_count']         = $igPosMap[$userId] ?? 0;
        $entry['earliest_account_created_at'] = $earliestAt;
    }
    unset($entry);
    
    // Convert to array for JSON encoding
    $result = array_values($incrementsData);
    
    echo json_encode([
        'success' => true,
        'data' => $result,
        'total_users' => count($result)
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

mysqli_close($conn);
?>

