<?php
/**
 * Incremental sync of IG.com open positions by deal_id.
 * Upsert rows in ig_positions; remove closed positions not in current snapshot.
 *
 * POST JSON:
 * {
 *   "account_id": 1,
 *   "positions": [
 *     {"ticker": "EURUSD", "epic": "CS.D.EURUSD.CFD.IP", "deal_id": "...", "direction": "BUY",
 *      "size": 1, "level": 1.09, "unrealized_pnl": -12.3, "currency": "USD"}
 *   ]
 * }
 *
 * Returns JSON: {"success": true, "upserted": N, "deleted_orphans": M}
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

if (!$input || !isset($input['account_id']) || !isset($input['api_key']) || !array_key_exists('positions', $input)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing required fields: account_id, api_key and positions (array)']);
    exit;
}

if (!is_array($input['positions'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'positions must be a JSON array']);
    exit;
}

require_once __DIR__ . '/../conn.php';

if (!$conn) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit;
}

$account_id = intval($input['account_id']);
$payload_api_key = mysqli_real_escape_string($conn, trim((string) $input['api_key']));
$positions = $input['positions'];

mysqli_begin_transaction($conn);

try {
    if (count($positions) === 0) {
        $sql = "DELETE FROM ig_positions WHERE account_id = {$account_id}";
        if (!mysqli_query($conn, $sql)) {
            throw new Exception(mysqli_error($conn));
        }
        $deleted = mysqli_affected_rows($conn);
        mysqli_commit($conn);
        echo json_encode([
            'success' => true,
            'upserted' => 0,
            'deleted_orphans' => $deleted,
            'message' => "Cleared all IG positions for account_id {$account_id}",
        ]);
        mysqli_close($conn);
        exit;
    }

    $dealIdsInPayload = [];
    foreach ($positions as $row) {
        if (!is_array($row)) continue;
        $d = isset($row['deal_id']) ? trim((string) $row['deal_id']) : '';
        if ($d !== '') {
            $dealIdsInPayload[$d] = true;
        }
    }
    $dealIdsList = array_keys($dealIdsInPayload);

    if (count($dealIdsList) > 0) {
        $escaped = [];
        foreach ($dealIdsList as $id) {
            $escaped[] = "'" . mysqli_real_escape_string($conn, $id) . "'";
        }
        $inList = implode(',', $escaped);
        $sql = "DELETE FROM ig_positions WHERE account_id = {$account_id} AND deal_id IS NOT NULL AND deal_id != '' AND deal_id NOT IN ({$inList})";
        if (!mysqli_query($conn, $sql)) {
            throw new Exception(mysqli_error($conn));
        }
    } else {
        $sql = "DELETE FROM ig_positions WHERE account_id = {$account_id} AND deal_id IS NOT NULL AND deal_id != ''";
        if (!mysqli_query($conn, $sql)) {
            throw new Exception(mysqli_error($conn));
        }
    }

    $upserted = 0;

    foreach ($positions as $row) {
        if (!is_array($row)) continue;

        $ticker = isset($row['ticker']) ? strtoupper(trim((string) $row['ticker'])) : '';
        $epic = isset($row['epic']) ? trim((string) $row['epic']) : '';
        if ($ticker === '' && $epic === '') continue;

        $deal_id = isset($row['deal_id']) ? trim((string) $row['deal_id']) : '';
        $direction = isset($row['direction']) ? strtoupper(trim((string) $row['direction'])) : null;
        $size = isset($row['size']) ? floatval($row['size']) : null;
        $level = isset($row['level']) ? floatval($row['level']) : null;
        $stop_level = isset($row['stop_level']) ? floatval($row['stop_level']) : null;
        $limit_level = isset($row['limit_level']) ? floatval($row['limit_level']) : null;
        $unrealized_pnl = isset($row['unrealized_pnl']) ? floatval($row['unrealized_pnl']) : 0.0;
        $currency = isset($row['currency']) ? mysqli_real_escape_string($conn, $row['currency']) : null;
        $strategy = isset($row['strategy']) ? mysqli_real_escape_string($conn, $row['strategy']) : null;
        $api_key = isset($row['api_key']) ? trim((string) $row['api_key']) : '';
        if ($api_key === '') {
            $api_key = $payload_api_key;
        }

        $ticker_esc = mysqli_real_escape_string($conn, $ticker);
        $epic_esc = mysqli_real_escape_string($conn, $epic);
        $deal_esc = $deal_id !== '' ? "'" . mysqli_real_escape_string($conn, $deal_id) . "'" : 'NULL';
        $dir_sql = $direction ? "'" . mysqli_real_escape_string($conn, $direction) . "'" : 'NULL';
        $size_sql = $size !== null ? $size : 'NULL';
        $level_sql = $level !== null ? $level : 'NULL';
        $stop_sql = $stop_level !== null ? $stop_level : 'NULL';
        $limit_sql = $limit_level !== null ? $limit_level : 'NULL';
        $cur_sql = $currency ? "'{$currency}'" : 'NULL';
        $strat_sql = $strategy ? "'{$strategy}'" : 'NULL';
        $api_key_sql = "'" . mysqli_real_escape_string($conn, $api_key) . "'";

        if ($deal_id !== '') {
            // NOTE: ticker is intentionally NOT updated here. The webhook flow stores the
            // TradingView ticker (e.g. "GOLD") at insert; IG's instrumentName (e.g. "Spot Gold")
            // would otherwise overwrite it and break exit lookups by ticker.
            $sql = "UPDATE ig_positions SET epic = '{$epic_esc}', direction = {$dir_sql},
                    size = {$size_sql}, level = {$level_sql}, stop_level = {$stop_sql}, limit_level = {$limit_sql},
                    unrealized_pnl = {$unrealized_pnl}, currency = {$cur_sql}, api_key = {$api_key_sql}, updated_at = NOW()
                    WHERE account_id = {$account_id} AND deal_id = " . "'" . mysqli_real_escape_string($conn, $deal_id) . "'";
            if (!mysqli_query($conn, $sql)) throw new Exception(mysqli_error($conn));

            if (mysqli_affected_rows($conn) === 0) {
                $sql = "INSERT INTO ig_positions (account_id, api_key, epic, ticker, deal_id, direction, size, level, stop_level, limit_level, unrealized_pnl, currency, strategy, updated_at)
                        VALUES ({$account_id}, {$api_key_sql}, '{$epic_esc}', '{$ticker_esc}', {$deal_esc}, {$dir_sql}, {$size_sql}, {$level_sql}, {$stop_sql}, {$limit_sql}, {$unrealized_pnl}, {$cur_sql}, {$strat_sql}, NOW())";
                if (!mysqli_query($conn, $sql)) throw new Exception(mysqli_error($conn));
            }
        } else {
            $sql = "DELETE FROM ig_positions WHERE account_id = {$account_id} AND ticker = '{$ticker_esc}' AND (deal_id IS NULL OR deal_id = '')";
            if (!mysqli_query($conn, $sql)) throw new Exception(mysqli_error($conn));
            $sql = "INSERT INTO ig_positions (account_id, api_key, epic, ticker, deal_id, direction, size, level, stop_level, limit_level, unrealized_pnl, currency, strategy, updated_at)
                    VALUES ({$account_id}, {$api_key_sql}, '{$epic_esc}', '{$ticker_esc}', NULL, {$dir_sql}, {$size_sql}, {$level_sql}, {$stop_sql}, {$limit_sql}, {$unrealized_pnl}, {$cur_sql}, {$strat_sql}, NOW())";
            if (!mysqli_query($conn, $sql)) throw new Exception(mysqli_error($conn));
        }
        $upserted++;
    }

    mysqli_commit($conn);

    echo json_encode([
        'success' => true,
        'upserted' => $upserted,
        'message' => 'IG positions synced successfully',
    ]);
} catch (Exception $e) {
    mysqli_rollback($conn);
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

mysqli_close($conn);
