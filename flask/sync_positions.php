<?php
/**
 * Incremental sync: upsert open positions by deal_id, update PnL/price, remove closed legs.
 *
 * POST JSON:
 * {
 *   "account_id": 1,
 *   "positions": [
 *     {"ticker": "EURUSD", "deal_id": "006011e7-0001-54c4-0000-000080560043", "price": 1.09, "unrealized_pnl": -12.3},
 *     {"ticker": "GBPUSD", "deal_id": "", "price": 1.25, "unrealized_pnl": 0}
 *   ]
 * }
 *
 * - Empty positions[]: DELETE all rows for account_id (explicit "no open positions").
 * - Non-empty: DELETE rows with deal_id NOT IN current payload (closed on broker).
 *   Rows with deal_id: UPDATE by (account_id, deal_id) or INSERT.
 *   Rows without deal_id: per ticker, DELETE (account_id,ticker) where deal_id IS NULL, then INSERT each leg.
 * - DELETE stale NULL-deal rows for tickers not present anywhere in payload.
 *
 * Returns JSON: {"success": true, "upserted": N, "deleted_orphans": M, "message": "..."}
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed. Use POST.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

if (!$input || !isset($input['account_id']) || !array_key_exists('positions', $input)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Missing required fields: account_id and positions (array)',
    ]);
    exit;
}

if (!is_array($input['positions'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'positions must be a JSON array']);
    exit;
}

require_once '../conn.php';

if (!$conn) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit;
}

$account_id = intval($input['account_id']);
$positions = $input['positions'];

mysqli_begin_transaction($conn);

try {
    if (count($positions) === 0) {
        $sql = "DELETE FROM positions WHERE account_id = {$account_id}";
        if (!mysqli_query($conn, $sql)) {
            throw new Exception(mysqli_error($conn));
        }
        $deleted = mysqli_affected_rows($conn);
        mysqli_commit($conn);
        echo json_encode([
            'success' => true,
            'upserted' => 0,
            'deleted_orphans' => $deleted,
            'message' => "Cleared all positions for account_id {$account_id} (empty snapshot)",
        ]);
        mysqli_close($conn);
        exit;
    }

    $dealIdsInPayload = [];
    $allTickers = [];
    foreach ($positions as $row) {
        if (!is_array($row)) {
            continue;
        }
        $t = isset($row['ticker']) ? strtoupper(trim((string) $row['ticker'])) : '';
        if ($t !== '') {
            $allTickers[$t] = true;
        }
        $d = isset($row['deal_id']) ? trim((string) $row['deal_id']) : '';
        if ($d !== '') {
            $dealIdsInPayload[$d] = true;
        }
    }
    $dealIdsList = array_keys($dealIdsInPayload);
    $tickerList = array_keys($allTickers);

    // Remove broker-closed positions (had deal_id, not in current snapshot)
    if (count($dealIdsList) > 0) {
        $escaped = [];
        foreach ($dealIdsList as $id) {
            $escaped[] = "'" . mysqli_real_escape_string($conn, $id) . "'";
        }
        $inList = implode(',', $escaped);
        $sql = "DELETE FROM positions WHERE account_id = {$account_id} AND deal_id IS NOT NULL AND deal_id != '' AND deal_id NOT IN ({$inList})";
        if (!mysqli_query($conn, $sql)) {
            throw new Exception(mysqli_error($conn));
        }
    } else {
        // No deal ids in snapshot: drop all rows that still carry a deal_id (broker no longer reports them this way)
        $sql = "DELETE FROM positions WHERE account_id = {$account_id} AND deal_id IS NOT NULL AND deal_id != ''";
        if (!mysqli_query($conn, $sql)) {
            throw new Exception(mysqli_error($conn));
        }
    }

    // Stale NULL-deal_id rows for tickers not in this snapshot at all
    if (count($tickerList) > 0) {
        $escTickers = [];
        foreach ($tickerList as $t) {
            $escTickers[] = "'" . mysqli_real_escape_string($conn, $t) . "'";
        }
        $tIn = implode(',', $escTickers);
        $sql = "DELETE FROM positions WHERE account_id = {$account_id} AND (deal_id IS NULL OR deal_id = '') AND ticker NOT IN ({$tIn})";
        if (!mysqli_query($conn, $sql)) {
            throw new Exception(mysqli_error($conn));
        }
    }

    $withDeal = [];
    $withoutDealByTicker = [];
    $tickersWithDealId = [];
    foreach ($positions as $row) {
        if (!is_array($row)) {
            continue;
        }
        $ticker = isset($row['ticker']) ? strtoupper(trim((string) $row['ticker'])) : '';
        if ($ticker === '') {
            continue;
        }
        $deal_id = isset($row['deal_id']) ? trim((string) $row['deal_id']) : '';
        $price = isset($row['price']) ? floatval($row['price']) : 0.0;
        $unrealized_pnl = isset($row['unrealized_pnl']) ? floatval($row['unrealized_pnl']) : 0.0;

        if ($deal_id !== '') {
            $withDeal[] = ['ticker' => $ticker, 'deal_id' => $deal_id, 'price' => $price, 'unrealized_pnl' => $unrealized_pnl];
            $tickersWithDealId[$ticker] = true;
        } else {
            if (!isset($withoutDealByTicker[$ticker])) {
                $withoutDealByTicker[$ticker] = [];
            }
            $withoutDealByTicker[$ticker][] = ['price' => $price, 'unrealized_pnl' => $unrealized_pnl];
        }
    }

    $upserted = 0;

    foreach ($withDeal as $r) {
        $ticker_esc = mysqli_real_escape_string($conn, $r['ticker']);
        $deal_esc = mysqli_real_escape_string($conn, $r['deal_id']);
        $price = floatval($r['price']);
        $pnl = floatval($r['unrealized_pnl']);

        $sql = "UPDATE positions SET ticker = '{$ticker_esc}', price = {$price}, unrealized_pnl = {$pnl}, updated_at = NOW() WHERE account_id = {$account_id} AND deal_id = '{$deal_esc}'";
        if (!mysqli_query($conn, $sql)) {
            throw new Exception(mysqli_error($conn));
        }
        if (mysqli_affected_rows($conn) === 0) {
            $sql = "INSERT INTO positions (account_id, ticker, deal_id, increments, price, unrealized_pnl, updated_at) VALUES ({$account_id}, '{$ticker_esc}', '{$deal_esc}', 1, {$price}, {$pnl}, NOW())";
            if (!mysqli_query($conn, $sql)) {
                throw new Exception(mysqli_error($conn));
            }
        }
        $upserted++;
    }

    // Drop NULL-deal_id placeholder rows for tickers that now have real deal_id legs only (not tickers that also have explicit NULL-deal legs in this payload).
    $tickersClearNull = array_diff(array_keys($tickersWithDealId), array_keys($withoutDealByTicker));
    if (count($tickersClearNull) > 0) {
        $escT = [];
        foreach ($tickersClearNull as $t) {
            $escT[] = "'" . mysqli_real_escape_string($conn, $t) . "'";
        }
        $tIn2 = implode(',', $escT);
        $sql = "DELETE FROM positions WHERE account_id = {$account_id} AND (deal_id IS NULL OR deal_id = '') AND ticker IN ({$tIn2})";
        if (!mysqli_query($conn, $sql)) {
            throw new Exception(mysqli_error($conn));
        }
    }

    foreach ($withoutDealByTicker as $ticker => $legs) {
        $ticker_esc = mysqli_real_escape_string($conn, $ticker);
        // Same ticker may also have deal_id legs in this snapshot — only clear NULL rows.
        // If this ticker has no deal_id legs here, remove all rows for (account, ticker) then re-insert NULL legs.
        if (isset($tickersWithDealId[$ticker])) {
            $sql = "DELETE FROM positions WHERE account_id = {$account_id} AND ticker = '{$ticker_esc}' AND (deal_id IS NULL OR deal_id = '')";
        } else {
            $sql = "DELETE FROM positions WHERE account_id = {$account_id} AND ticker = '{$ticker_esc}'";
        }
        if (!mysqli_query($conn, $sql)) {
            throw new Exception(mysqli_error($conn));
        }
        foreach ($legs as $leg) {
            $price = floatval($leg['price']);
            $pnl = floatval($leg['unrealized_pnl']);
            $sql = "INSERT INTO positions (account_id, ticker, deal_id, increments, price, unrealized_pnl, updated_at) VALUES ({$account_id}, '{$ticker_esc}', NULL, 1, {$price}, {$pnl}, NOW())";
            if (!mysqli_query($conn, $sql)) {
                throw new Exception(mysqli_error($conn));
            }
            $upserted++;
        }
    }

    mysqli_commit($conn);

    if (file_exists(__DIR__ . '/../includes/api_log.php')) {
        require_once __DIR__ . '/../includes/api_log.php';
        api_log($conn, 'flask/sync_positions', 'Positions synced (upsert + reconcile)', 'update', true);
    }

    echo json_encode([
        'success' => true,
        'upserted' => $upserted,
        'message' => 'Positions synced successfully',
    ]);
} catch (Exception $e) {
    mysqli_rollback($conn);
    if (file_exists(__DIR__ . '/../includes/api_log.php')) {
        require_once __DIR__ . '/../includes/api_log.php';
        api_log($conn, 'flask/sync_positions', $e->getMessage(), 'update', false);
    }
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
    ]);
}

mysqli_close($conn);
