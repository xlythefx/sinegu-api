<?php
/**
 * Log a Capital.com API event (e.g. rate limit) into capital_api_logs and
 * optionally email a fixed list of admins.
 *
 * POST JSON:
 * {
 *   "secret": "...",                   // required, must match $CAPITAL_API_LOG_SECRET
 *   "event_type": "rate_limited",      // required (rate_limited | rate_limited_dropped | ...)
 *   "account_id": 1,                   // optional
 *   "account_name": "master",          // optional
 *   "ticker": "BTCUSD",                // optional
 *   "action": "BUY",                   // optional
 *   "http_status": 429,                // optional
 *   "source": "webhook_queue",         // optional
 *   "message": "...",                  // optional
 *   "send_email": true                 // optional, default true
 * }
 *
 * Returns JSON: { success, log_id, email_sent } or { success: false, error }.
 *
 * Insert into capital_api_logs is append-only and never blocked by email failure.
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
if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid JSON body']);
    exit;
}

// Secret check (shared with src/config.py CAPITAL_API_LOG_SECRET).
$configPath = __DIR__ . '/config_bot_listener.php';
if (!file_exists($configPath)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Config not found']);
    exit;
}
require_once $configPath;
$expectedSecret = isset($CAPITAL_API_LOG_SECRET) ? $CAPITAL_API_LOG_SECRET : '';
$secret = isset($input['secret']) ? (string) $input['secret'] : '';
if ($expectedSecret === '' || $secret !== $expectedSecret) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$event_type = isset($input['event_type']) ? trim((string) $input['event_type']) : '';
if ($event_type === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing event_type']);
    exit;
}

require_once __DIR__ . '/../conn.php';
if (!$conn) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit;
}

$account_id   = isset($input['account_id']) && $input['account_id'] !== '' ? (int) $input['account_id'] : null;
$account_name = isset($input['account_name']) ? substr(trim((string) $input['account_name']), 0, 128) : null;
$ticker       = isset($input['ticker']) ? substr(strtoupper(trim((string) $input['ticker'])), 0, 64) : null;
$action       = isset($input['action']) ? substr(strtoupper(trim((string) $input['action'])), 0, 32) : null;
$http_status  = isset($input['http_status']) && $input['http_status'] !== '' ? (int) $input['http_status'] : null;
$source       = isset($input['source']) ? substr(trim((string) $input['source']), 0, 64) : null;
$message      = isset($input['message']) ? (string) $input['message'] : null;
$send_email   = array_key_exists('send_email', $input) ? (bool) $input['send_email'] : true;
$sourceNorm   = ($source !== null) ? trim((string) $source) : '';
// Suppress email for API-credential event types unless the caller is the TradingView webhook
// (trading_handler) — prevents background scripts from spamming if they POST the same event_type.
$authEventTypes = ['invalid_api_key', 'login_failed'];
if (in_array($event_type, $authEventTypes, true) && $sourceNorm !== 'webhook_queue') {
    $send_email = false;
}

// Enrich with user info (name, email, demo flag) from accounts + user_credentials tables.
$user_name  = null;
$user_email = null;
$is_demo    = null;  // null = unknown, 0 = live, 1 = demo

if ($account_id !== null) {
    $uq = mysqli_prepare(
        $conn,
        'SELECT a.demo, uc.name, uc.email
           FROM accounts a
           LEFT JOIN user_credentials uc ON uc.uni_id = a.uni_id
          WHERE a.id = ?
          LIMIT 1'
    );
    if ($uq) {
        mysqli_stmt_bind_param($uq, 'i', $account_id);
        if (mysqli_stmt_execute($uq)) {
            $urow = mysqli_fetch_assoc(mysqli_stmt_get_result($uq));
            if ($urow) {
                $is_demo    = (int) $urow['demo'];
                $user_name  = $urow['name'] !== null ? trim((string) $urow['name']) : null;
                $user_email = $urow['email'] !== null ? trim((string) $urow['email']) : null;
            }
        }
        mysqli_stmt_close($uq);
    }
}

$sql = 'INSERT INTO capital_api_logs
        (account_id, account_name, ticker, action, event_type, http_status, source, message, email_sent)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0)';
$stmt = mysqli_prepare($conn, $sql);
if (!$stmt) {
    if (file_exists(__DIR__ . '/../includes/api_log.php')) {
        require_once __DIR__ . '/../includes/api_log.php';
        api_log($conn, 'flask/log_capital_api_event', 'prepare failed: ' . mysqli_error($conn), 'create', false);
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => mysqli_error($conn)]);
    exit;
}
mysqli_stmt_bind_param(
    $stmt,
    'issssiss',
    $account_id,
    $account_name,
    $ticker,
    $action,
    $event_type,
    $http_status,
    $source,
    $message
);
$ok = mysqli_stmt_execute($stmt);
$log_id = $ok ? mysqli_insert_id($conn) : 0;
mysqli_stmt_close($stmt);

if (!$ok) {
    if (file_exists(__DIR__ . '/../includes/api_log.php')) {
        require_once __DIR__ . '/../includes/api_log.php';
        api_log($conn, 'flask/log_capital_api_event', 'insert failed: ' . mysqli_error($conn), 'create', false);
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => mysqli_error($conn)]);
    exit;
}

$email_sent_count = 0;
if ($send_email) {
    $recipients = [
        ['email' => 'dimamarko2@gmail.com',          'name' => 'Dima'],
        ['email' => 'christian@feature-digital.com', 'name' => 'Christian'],
    ];

    $smtpPath = __DIR__ . '/../smtp.php';
    if (file_exists($smtpPath)) {
        require_once $smtpPath;
    }

    if (function_exists('sendEmail')) {
        // Subjects: use plain ASCII (avoid mojibake in clients). No ticker in credential alert subjects.
        $subName  = ($user_name !== null && $user_name !== '') ? $user_name : (
            ($account_name !== null && $account_name !== '') ? $account_name : 'unknown'
        );
        $subEmail = ($user_email !== null && $user_email !== '') ? $user_email : 'unknown';
        if ($event_type === 'invalid_api_key') {
            $subject = sprintf(
                '[Capital.com] Invalid API Key for %s: %s',
                $subName,
                $subEmail
            );
        } elseif ($event_type === 'login_failed') {
            $subject = sprintf(
                '[Capital.com] Login failed for %s: %s',
                $subName,
                $subEmail
            );
        } else {
            $subjectAcc = $account_name !== null && $account_name !== '' ? $account_name : 'unknown';
            $subject = sprintf('[Capital.com] %s - %s', $event_type, $subjectAcc);
        }

        // Account type badge
        if ($is_demo === 1) {
            $accountTypeBadge = '<span style="display:inline-block;padding:2px 8px;background:#fef3c7;color:#92400e;border-radius:4px;font-size:12px;font-weight:600;">DEMO</span>';
            $accountTypeStr   = 'DEMO';
        } elseif ($is_demo === 0) {
            $accountTypeBadge = '<span style="display:inline-block;padding:2px 8px;background:#dcfce7;color:#166534;border-radius:4px;font-size:12px;font-weight:600;">LIVE</span>';
            $accountTypeStr   = 'LIVE';
        } else {
            $accountTypeBadge = '<span style="display:inline-block;padding:2px 8px;background:#f3f4f6;color:#6b7280;border-radius:4px;font-size:12px;">unknown</span>';
            $accountTypeStr   = '-';
        }

        $createdAt = date('Y-m-d H:i:s');
        $rows = [
            ['Event',        htmlspecialchars($event_type, ENT_QUOTES, 'UTF-8')],
            ['Account ID',   $account_id !== null ? (string) $account_id : '-'],
            ['Account',      htmlspecialchars($account_name ?? '-', ENT_QUOTES, 'UTF-8')],
            ['Account Type', $accountTypeBadge],
            ['User Name',    htmlspecialchars($user_name ?? '-', ENT_QUOTES, 'UTF-8')],
            ['User Email',   $user_email !== null
                ? '<a href="mailto:' . htmlspecialchars($user_email, ENT_QUOTES, 'UTF-8') . '" style="color:#2563eb;">'
                    . htmlspecialchars($user_email, ENT_QUOTES, 'UTF-8') . '</a>'
                : '-'],
            ['Ticker',       htmlspecialchars($ticker ?? '-', ENT_QUOTES, 'UTF-8')],
            ['Action',       htmlspecialchars($action ?? '-', ENT_QUOTES, 'UTF-8')],
            ['HTTP Status',  $http_status !== null ? (string) $http_status : '-'],
            ['Source',       htmlspecialchars($source ?? '-', ENT_QUOTES, 'UTF-8')],
            ['Time (UTC)',   $createdAt],
            ['Log ID',       (string) $log_id],
        ];
        $rowsHtml = '';
        foreach ($rows as $i => $r) {
            $bg = ($i % 2 === 0) ? '#ffffff' : '#f9fafb';
            $rowsHtml .= '<tr style="background:' . $bg . ';">'
                . '<td style="padding:8px 16px;font-weight:600;color:#374151;white-space:nowrap;border-bottom:1px solid #f3f4f6;">' . $r[0] . '</td>'
                . '<td style="padding:8px 16px;color:#111827;border-bottom:1px solid #f3f4f6;">' . $r[1] . '</td>'
                . '</tr>';
        }
        $msgEsc = htmlspecialchars($message ?? '', ENT_QUOTES, 'UTF-8');
        if ($msgEsc === '') { $msgEsc = '<em>(no message)</em>'; }

        $body = '<!DOCTYPE html><html><body style="font-family:-apple-system,Segoe UI,Roboto,sans-serif;background:#f9fafb;padding:24px;">'
            . '<div style="max-width:620px;margin:0 auto;background:#fff;border:1px solid #e5e7eb;border-radius:8px;overflow:hidden;">'
            . '<div style="padding:20px 24px;background:#fef2f2;border-bottom:1px solid #fee2e2;">'
            . '<h2 style="margin:0 0 4px 0;color:#b91c1c;font-size:18px;">Capital.com API event: '
                . htmlspecialchars($event_type, ENT_QUOTES, 'UTF-8') . '</h2>'
            . '<p style="margin:0;color:#6b7280;font-size:13px;">'
                . htmlspecialchars($account_name ?? 'unknown', ENT_QUOTES, 'UTF-8') . ' &middot; '
                . htmlspecialchars($ticker ?? '-', ENT_QUOTES, 'UTF-8') . ' &middot; ' . $accountTypeStr
            . '</p>'
            . '</div>'
            . '<table style="width:100%;border-collapse:collapse;font-size:14px;">' . $rowsHtml . '</table>'
            . '<div style="padding:16px 24px;border-top:1px solid #e5e7eb;color:#374151;font-size:14px;line-height:1.5;">'
            . '<strong>Message:</strong><br>' . nl2br($msgEsc)
            . '</div>'
            . '<div style="padding:12px 24px;background:#f3f4f6;color:#6b7280;font-size:12px;">SineguAlerts &middot; capital_api_logs</div>'
            . '</div></body></html>';

        foreach ($recipients as $rcpt) {
            try {
                if (sendEmail($rcpt['email'], $rcpt['name'], $subject, $body)) {
                    $email_sent_count++;
                } else {
                    error_log('log_capital_api_event: sendEmail returned false for ' . $rcpt['email']);
                }
            } catch (Throwable $e) {
                error_log('log_capital_api_event: sendEmail threw for ' . $rcpt['email'] . ': ' . $e->getMessage());
            }
        }
    } else {
        error_log('log_capital_api_event: sendEmail() not available — emails skipped (log_id=' . $log_id . ')');
    }

    if ($email_sent_count > 0) {
        $upd = mysqli_prepare($conn, 'UPDATE capital_api_logs SET email_sent = 1 WHERE id = ?');
        if ($upd) {
            mysqli_stmt_bind_param($upd, 'i', $log_id);
            mysqli_stmt_execute($upd);
            mysqli_stmt_close($upd);
        }
    }
}

if (file_exists(__DIR__ . '/../includes/api_log.php')) {
    require_once __DIR__ . '/../includes/api_log.php';
    api_log(
        $conn,
        'flask/log_capital_api_event',
        sprintf('Logged %s for account=%s ticker=%s emails=%d', $event_type, $account_name ?? '-', $ticker ?? '-', $email_sent_count),
        'create',
        true
    );
}

echo json_encode([
    'success' => true,
    'log_id' => $log_id,
    'email_sent_count' => $email_sent_count,
]);

mysqli_close($conn);
