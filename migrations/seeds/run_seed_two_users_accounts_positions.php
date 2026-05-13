<?php
/**
 * Seed two accounts (one per uni_id) and open positions for local/dev.
 *
 * Accounts (unique name — matches UNIQUE on accounts.name):
 *   - seed-capital-25293010  → uni_id 25293010-a2b7-4e1d-8e04-e2c3350c8118
 *   - seed-capital-fa9fb7c5  → uni_id fa9fb7c5-3920-47fc-8972-6466f721f4c2
 *
 * Positions use deal_id prefix 00000000-0000-4eed-* ; re-run deletes old seed rows for those accounts only.
 *
 * Requires: positions columns deal_id, side, strategy (migrations 048–050).
 *
 * Usage (from repo root):
 *   php migrations/seeds/run_seed_two_users_accounts_positions.php
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../../conn.php';

if (!$conn) {
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit;
}

$dealPrefix = '00000000-0000-4eed-%';

$positionsCols = static function ($conn) {
    $cols = [];
    $sql = "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'positions'";
    $res = mysqli_query($conn, $sql);
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            $cols[$row['COLUMN_NAME']] = true;
        }
        mysqli_free_result($res);
    }
    return $cols;
};

$pc = $positionsCols($conn);
foreach (['side', 'deal_id', 'strategy'] as $need) {
    if (empty($pc[$need])) {
        echo json_encode([
            'success' => false,
            'error' => "positions.$need missing — run migrations 048 (deal_id), 049 (side), 050 (strategy) first.",
        ]);
        mysqli_close($conn);
        exit;
    }
}

$seedAccounts = [
    [
        'uni_id' => '25293010-a2b7-4e1d-8e04-e2c3350c8118',
        'name' => 'seed-capital-25293010',
        'api_key' => 'Seed25293DemoApiKey01',
        'broker_accountID' => '301171542199988001',
        'email' => 'seed+25293@example.invalid',
        'password' => 'seed-not-used',
        'balance' => 16741.88,
        'unrealized_pnl' => -377.49,
        'equity' => 16741.88,
        'currency_type' => 'EUR',
        'deposit_amount' => 17119.37,
        'initial_deposit' => 1884.00,
    ],
    [
        'uni_id' => 'fa9fb7c5-3920-47fc-8972-6466f721f4c2',
        'name' => 'seed-capital-fa9fb7c5',
        'api_key' => 'SeedFa9fDemoApiKey02',
        'broker_accountID' => '301171542199988002',
        'email' => 'seed+fa9f@example.invalid',
        'password' => 'seed-not-used',
        'balance' => 10000.00,
        'unrealized_pnl' => 125.50,
        'equity' => 10125.50,
        'currency_type' => 'USD',
        'deposit_amount' => 10000.00,
        'initial_deposit' => 10000.00,
    ],
];

$ensureAccount = static function ($conn, array $a) {
    $nameEsc = mysqli_real_escape_string($conn, $a['name']);
    $res = mysqli_query($conn, "SELECT id FROM accounts WHERE name = '{$nameEsc}' LIMIT 1");
    if ($res && mysqli_num_rows($res) > 0) {
        $id = (int) mysqli_fetch_assoc($res)['id'];
        mysqli_free_result($res);
        return $id;
    }
    if ($res) {
        mysqli_free_result($res);
    }

    $stmt = mysqli_prepare(
        $conn,
        'INSERT INTO accounts (
            uni_id, name, api_key, broker_accountID, email, password,
            demo, enabled, balance, unrealized_pnl, equity,
            currency_type, deposit_amount, initial_deposit, deleted_at
        ) VALUES (?,?,?,?,?,?,1,1,?,?,?,?,?,?,NULL)'
    );
    if (!$stmt) {
        throw new RuntimeException(mysqli_error($conn) ?: 'prepare INSERT accounts failed');
    }
    mysqli_stmt_bind_param(
        $stmt,
        'ssssssdddsdd',
        $a['uni_id'],
        $a['name'],
        $a['api_key'],
        $a['broker_accountID'],
        $a['email'],
        $a['password'],
        $a['balance'],
        $a['unrealized_pnl'],
        $a['equity'],
        $a['currency_type'],
        $a['deposit_amount'],
        $a['initial_deposit']
    );
    if (!mysqli_stmt_execute($stmt)) {
        $err = mysqli_error($conn);
        mysqli_stmt_close($stmt);
        throw new RuntimeException($err ?: 'INSERT accounts failed');
    }
    $id = (int) mysqli_insert_id($conn);
    mysqli_stmt_close($stmt);
    return $id;
};

$accountIds = [];
try {
    foreach ($seedAccounts as $row) {
        $accountIds[] = $ensureAccount($conn, $row);
    }
} catch (RuntimeException $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    mysqli_close($conn);
    exit;
}

$id1 = $accountIds[0];
$id2 = $accountIds[1];

$prefixEsc = mysqli_real_escape_string($conn, $dealPrefix);
mysqli_query(
    $conn,
    "DELETE FROM positions WHERE account_id IN ({$id1},{$id2}) AND deal_id LIKE '{$prefixEsc}'"
);

$rows = [
    [$id1, 'CRWD', 'BUY', '00000000-0000-4eed-2529-000000000001', 445.50, 17.7000, 1, 'Momentum'],
    [$id1, 'BRKB', 'SELL', '00000000-0000-4eed-2529-000000000002', 478.21, -33.9400, 1, 'Swing'],
    [$id2, 'AAPL', 'BUY', '00000000-0000-4eed-fa9f-000000000001', 262.90, 40.2600, 1, 'Long-term'],
    [$id2, 'GOLD', 'SELL', '00000000-0000-4eed-fa9f-000000000002', 5050.26, -2.6200, 1, 'Hedge'],
];

$ins = mysqli_prepare(
    $conn,
    'INSERT INTO positions (account_id, ticker, side, deal_id, price, unrealized_pnl, increments, strategy) VALUES (?,?,?,?,?,?,?,?)'
);
if (!$ins) {
    echo json_encode(['success' => false, 'error' => mysqli_error($conn)]);
    mysqli_close($conn);
    exit;
}

foreach ($rows as $r) {
    $accountId = $r[0];
    $ticker = $r[1];
    $side = $r[2];
    $dealId = $r[3];
    $price = $r[4];
    $upl = $r[5];
    $inc = $r[6];
    $strat = $r[7];
    mysqli_stmt_bind_param(
        $ins,
        'isssddis',
        $accountId,
        $ticker,
        $side,
        $dealId,
        $price,
        $upl,
        $inc,
        $strat
    );
    if (!mysqli_stmt_execute($ins)) {
        $err = mysqli_error($conn);
        mysqli_stmt_close($ins);
        echo json_encode(['success' => false, 'error' => $err]);
        mysqli_close($conn);
        exit;
    }
}
mysqli_stmt_close($ins);

echo json_encode([
    'success' => true,
    'message' => 'Seeded accounts + positions',
    'accounts' => [
        ['name' => $seedAccounts[0]['name'], 'id' => $id1, 'uni_id' => $seedAccounts[0]['uni_id']],
        ['name' => $seedAccounts[1]['name'], 'id' => $id2, 'uni_id' => $seedAccounts[1]['uni_id']],
    ],
    'positions_inserted' => count($rows),
]);
mysqli_close($conn);
