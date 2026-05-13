<?php

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Method not allowed',
    ]);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

if (!is_array($input) || empty($input['uni_id'])) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'uni_id is required',
    ]);
    exit;
}

$uni_id = trim($input['uni_id']);

require_once __DIR__ . '/../conn.php';

// Initialize response structure
$response = [
    'success' => true,
    'totals' => [
        'total_pnl' => 0.0,
        'trade_count' => 0,
        'closed_positions' => 0,
        'active_positions' => 0,
        'last_trade_at' => null,
    ],
    'breakdown' => [
        'daily' => [
            'pnl' => 0.0,
            'trades' => 0,
            'from' => null,
            'to' => null,
        ],
        'weekly' => [
            'pnl' => 0.0,
            'trades' => 0,
            'from' => null,
            'to' => null,
        ],
        'monthly' => [
            'pnl' => 0.0,
            'trades' => 0,
            'from' => null,
            'to' => null,
        ],
    ],
    'daily_series' => [],
];

// Get all account IDs for this user
$accountIds = [];
$accountSql = 'SELECT id, name, email FROM accounts WHERE uni_id = ? AND (deleted_at IS NULL)';
if ($stmt = mysqli_prepare($conn, $accountSql)) {
    mysqli_stmt_bind_param($stmt, 's', $uni_id);
    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Database query failed: ' . mysqli_error($conn),
        ]);
        mysqli_close($conn);
        exit;
    }
    $result = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($result)) {
        $accountIds[] = (int)$row['id'];
    }
    mysqli_stmt_close($stmt);
} else {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to prepare database query: ' . mysqli_error($conn),
    ]);
    mysqli_close($conn);
    exit;
}

if (empty($accountIds)) {
    // Return empty stats if no accounts - this is a valid response, not an error
    $response['success'] = true;
    $response['message'] = 'No broker accounts found. Please connect a Capital.com account to see trading statistics.';
    echo json_encode($response);
    mysqli_close($conn);
    exit;
}

$placeholders = implode(',', array_fill(0, count($accountIds), '?'));
$types = str_repeat('i', count($accountIds));

// Get totals across all user accounts
$totalSql = "SELECT 
        COALESCE(SUM(pnl), 0) AS total_pnl,
        COALESCE(SUM(closed_positions), 0) AS closed_positions,
        COUNT(*) AS trade_count,
        MAX(created_at) AS last_trade_at
    FROM trades
    WHERE account_id IN ($placeholders)";

if ($stmt = mysqli_prepare($conn, $totalSql)) {
    mysqli_stmt_bind_param($stmt, $types, ...$accountIds);
    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Database query failed: ' . mysqli_error($conn),
        ]);
        mysqli_close($conn);
        exit;
    }
    mysqli_stmt_bind_result($stmt, $totalPnl, $closedPositions, $tradeCount, $lastTradeAt);
    if (mysqli_stmt_fetch($stmt)) {
        $response['totals']['total_pnl'] = (float) $totalPnl;
        $response['totals']['closed_positions'] = (int) $closedPositions;
        $response['totals']['trade_count'] = (int) $tradeCount;
        $response['totals']['last_trade_at'] = $lastTradeAt;
    }
    mysqli_stmt_close($stmt);
} else {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to prepare database query: ' . mysqli_error($conn),
    ]);
    mysqli_close($conn);
    exit;
}

// Get active positions
$activeSql = "SELECT COUNT(*) AS active_positions FROM positions WHERE account_id IN ($placeholders)";
if ($stmt = mysqli_prepare($conn, $activeSql)) {
    mysqli_stmt_bind_param($stmt, $types, ...$accountIds);
    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Database query failed: ' . mysqli_error($conn),
        ]);
        mysqli_close($conn);
        exit;
    }
    mysqli_stmt_bind_result($stmt, $activePositions);
    if (mysqli_stmt_fetch($stmt)) {
        $response['totals']['active_positions'] = (int) $activePositions;
    }
    mysqli_stmt_close($stmt);
} else {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to prepare database query: ' . mysqli_error($conn),
    ]);
    mysqli_close($conn);
    exit;
}

// Get period breakdowns
$periods = [
    'daily' => '-1 day',
    'weekly' => '-7 day',
    'monthly' => '-30 day',
];

$periodSql = "SELECT COALESCE(SUM(pnl), 0) AS pnl, COUNT(*) AS trades
    FROM trades
    WHERE account_id IN ($placeholders) AND created_at >= ?";

foreach ($periods as $key => $modifier) {
    $start = date('Y-m-d H:i:s', strtotime($modifier));
    $now = date('Y-m-d H:i:s');

    if ($stmt = mysqli_prepare($conn, $periodSql)) {
        mysqli_stmt_bind_param($stmt, $types . 's', ...array_merge($accountIds, [$start]));
        if (!mysqli_stmt_execute($stmt)) {
            mysqli_stmt_close($stmt);
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'message' => 'Database query failed: ' . mysqli_error($conn),
            ]);
            mysqli_close($conn);
            exit;
        }
        mysqli_stmt_bind_result($stmt, $periodPnl, $periodTrades);
        if (mysqli_stmt_fetch($stmt)) {
            $response['breakdown'][$key]['pnl'] = (float) $periodPnl;
            $response['breakdown'][$key]['trades'] = (int) $periodTrades;
            $response['breakdown'][$key]['from'] = $start;
            $response['breakdown'][$key]['to'] = $now;
        }
        mysqli_stmt_close($stmt);
    } else {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Failed to prepare database query: ' . mysqli_error($conn),
        ]);
        mysqli_close($conn);
        exit;
    }
}

// Get daily series for calendar
$calendarDays = [];
$daysBack = 42;
$calendarStart = new DateTime('today');
$calendarStart->modify('-' . ($daysBack - 1) . ' day');
$calendarCursor = clone $calendarStart;

for ($i = 0; $i < $daysBack; $i++) {
    $calendarDays[$calendarCursor->format('Y-m-d')] = 0.0;
    $calendarCursor->modify('+1 day');
}

// Use date_closed for calendar day (match DB); fall back to created_at if date_closed is null
$calendarSql = "SELECT COALESCE(DATE(date_closed), DATE(created_at)) AS trade_date, COALESCE(SUM(pnl), 0) AS pnl
    FROM trades
    WHERE account_id IN ($placeholders) AND COALESCE(date_closed, created_at) >= ?
    GROUP BY COALESCE(DATE(date_closed), DATE(created_at))
    ORDER BY trade_date";

if ($stmt = mysqli_prepare($conn, $calendarSql)) {
    $calendarStartParam = $calendarStart->format('Y-m-d 00:00:00');
    mysqli_stmt_bind_param($stmt, $types . 's', ...array_merge($accountIds, [$calendarStartParam]));
    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Database query failed: ' . mysqli_error($conn),
        ]);
        mysqli_close($conn);
        exit;
    }
    mysqli_stmt_bind_result($stmt, $tradeDate, $pnlSum);
    while (mysqli_stmt_fetch($stmt)) {
        if ($tradeDate !== null) {
            $calendarDays[$tradeDate] = (float) $pnlSum;
        }
    }
    mysqli_stmt_close($stmt);
} else {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to prepare database query: ' . mysqli_error($conn),
    ]);
    mysqli_close($conn);
    exit;
}

$response['daily_series'] = array_map(
    static fn ($date, $pnl) => [
        'date' => $date,
        'pnl' => (float) $pnl,
    ],
    array_keys($calendarDays),
    $calendarDays
);

// Ensure response is always valid before sending
if (!isset($response['success'])) {
    $response['success'] = true;
}

// Final validation - ensure all required fields exist
if (!isset($response['totals'])) {
    $response['totals'] = [
        'total_pnl' => 0.0,
        'trade_count' => 0,
        'closed_positions' => 0,
        'active_positions' => 0,
        'last_trade_at' => null,
    ];
}

if (!isset($response['breakdown'])) {
    $response['breakdown'] = [
        'daily' => ['pnl' => 0.0, 'trades' => 0, 'from' => null, 'to' => null],
        'weekly' => ['pnl' => 0.0, 'trades' => 0, 'from' => null, 'to' => null],
        'monthly' => ['pnl' => 0.0, 'trades' => 0, 'from' => null, 'to' => null],
    ];
}

if (!isset($response['daily_series'])) {
    $response['daily_series'] = [];
}

mysqli_close($conn);

echo json_encode($response);

?>

