<?php
/**
 * Admin Assistant — server-side OpenAI chat proxy with tool-calling.
 *
 * The browser never sees the OpenAI key: the React admin chat page POSTs the
 * conversation here, this endpoint runs the OpenAI tool-calling loop, executes
 * whitelisted READ-ONLY tools against prod/staging, and returns the final reply.
 *
 * Tools (all read-only):
 *   - get_stats(env)                       -> admin/GET/stats_summary.php
 *   - tail_service_log(env, service, lines)-> admin/GET/service_logs.php
 *   - get_recent_registrations(env, limit) -> admin/GET/get_recent_registrations.php
 *
 * Environments:  prod  = 167.86.109.159 = https://api.sinegualfamily.com
 *                staging = 156.67.30.81 = https://api.andrea-orcelinvest.com
 *
 * Config (.env, gitignored):
 *   OPENAI_API_KEY        (required)
 *   CHATBOT_MODEL         (optional, default gpt-4o)
 *   CHATBOT_ADMIN_TOKEN   (optional; forwarded to the read-only tool endpoints)
 *   CHATBOT_ADMIN_EMAILS  (optional, comma-separated; extends the default admin allowlist)
 *
 * Auth: the POST body must include adminEmail in the allowlist (the admin UI is
 *       already email-guarded; this mirrors that server-side to limit key abuse).
 */

ob_start();

// The OpenAI round-trips + tool sub-requests can take longer than the default
// web max_execution_time (often 30s), which would 502 mid-call. Give it room.
@set_time_limit(120);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    ob_end_clean();
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ob_end_clean();
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

require_once __DIR__ . '/../../env.php';

// WAMP's curl often has no CA bundle configured, so outbound HTTPS (OpenAI, and
// our own Cloudflare-fronted tool calls) fails cert verification. Ship one with
// the repo and point curl at it explicitly so it works on every VPS.
define('CHATBOT_CA_BUNDLE', dirname(__DIR__, 2) . '/includes/certs/cacert.pem');

function respond($code, $payload) {
    ob_end_clean();
    http_response_code($code);
    echo json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

$apiKey = env('OPENAI_API_KEY');
if (!$apiKey) {
    respond(500, ['success' => false, 'message' => 'OPENAI_API_KEY is not configured on this server (.env).']);
}

$model = env('CHATBOT_MODEL', 'gpt-4o');
$toolToken = env('CHATBOT_ADMIN_TOKEN', '');

// --- Parse body ---
$raw = file_get_contents('php://input');
$body = json_decode($raw, true);
if (!is_array($body)) {
    respond(400, ['success' => false, 'message' => 'Invalid JSON body']);
}

// --- Admin email allowlist (mirrors AdminRouteGuard) ---
$defaultAdmins = ['eliascaffi.ec@gmail.com', 'dimamarko2@gmail.com'];
$extra = array_filter(array_map('trim', explode(',', (string) env('CHATBOT_ADMIN_EMAILS', ''))));
$allowed = array_map('strtolower', array_merge($defaultAdmins, $extra));
$adminEmail = strtolower(trim((string) ($body['adminEmail'] ?? '')));
if ($adminEmail === '' || !in_array($adminEmail, $allowed, true)) {
    respond(403, ['success' => false, 'message' => 'Not authorized for the admin Assistant.']);
}

$clientMessages = isset($body['messages']) && is_array($body['messages']) ? $body['messages'] : [];

// Keep only role/content, valid roles, last 24 turns.
$history = [];
foreach ($clientMessages as $m) {
    if (!is_array($m)) continue;
    $role = $m['role'] ?? '';
    $content = $m['content'] ?? '';
    if (!in_array($role, ['user', 'assistant'], true)) continue;
    if (!is_string($content) || $content === '') continue;
    $history[] = ['role' => $role, 'content' => mb_substr($content, 0, 8000)];
}
$history = array_slice($history, -24);
if (empty($history)) {
    respond(400, ['success' => false, 'message' => 'No messages provided']);
}

// --- Environment base URLs ---
function env_base($env) {
    $env = strtolower((string) $env);
    if ($env === 'staging' || $env === '156') return 'https://api.andrea-orcelinvest.com';
    return 'https://api.sinegualfamily.com'; // prod / default
}

// --- HTTP GET helper for tool endpoints ---
function tool_http_get($url, $token) {
    $ch = curl_init($url);
    $headers = ['Accept: application/json'];
    if ($token !== '') {
        $headers[] = 'X-Admin-Token: ' . $token;
    }
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 25,
        CURLOPT_CONNECTTIMEOUT => 10,
    ];
    if (is_file(CHATBOT_CA_BUNDLE)) {
        $opts[CURLOPT_CAINFO] = CHATBOT_CA_BUNDLE;
    }
    curl_setopt_array($ch, $opts);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($resp === false) {
        return ['ok' => false, 'data' => 'Request failed: ' . $err];
    }
    return ['ok' => $code >= 200 && $code < 300, 'http' => $code, 'data' => $resp];
}

// --- Tool definitions (OpenAI function-calling format) ---
$tools = [
    [
        'type' => 'function',
        'function' => [
            'name' => 'get_stats',
            'description' => 'Get headline counts (users by status, broker accounts, open positions, recent sign-ups) for one environment.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'env' => ['type' => 'string', 'enum' => ['prod', 'staging'], 'description' => 'prod = 167 / api.sinegualfamily.com, staging = 156 / api.andrea-orcelinvest.com'],
                ],
                'required' => ['env'],
            ],
        ],
    ],
    [
        'type' => 'function',
        'function' => [
            'name' => 'tail_service_log',
            'description' => 'Read the last N lines of a Flask service log and its NSSM status on a VPS. Use to inspect/tail Binance, Capital.com, or IG bots. By default tails the most recently updated log; capital and ig have several module logs (the response lists available_logs), pass "file" to tail a specific one.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'env' => ['type' => 'string', 'enum' => ['prod', 'staging']],
                    'service' => ['type' => 'string', 'enum' => ['binance', 'capital', 'ig']],
                    'lines' => ['type' => 'integer', 'description' => 'How many lines to tail (10-500, default 120)'],
                    'file' => ['type' => 'string', 'description' => 'Optional: specific log basename to tail (e.g. fetch_balances.log). Omit to tail the most recent log.'],
                ],
                'required' => ['env', 'service'],
            ],
        ],
    ],
    [
        'type' => 'function',
        'function' => [
            'name' => 'get_recent_registrations',
            'description' => 'List the most recent user sign-ups (name, email, date, referral code) for an environment.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'env' => ['type' => 'string', 'enum' => ['prod', 'staging']],
                    'limit' => ['type' => 'integer', 'description' => '1-50, default 10'],
                ],
                'required' => ['env'],
            ],
        ],
    ],
];

// --- Execute a tool call; returns [stringForModel, traceEntry] ---
function run_tool($name, $args, $toolToken) {
    $env = $args['env'] ?? 'prod';
    $base = env_base($env);
    if ($name === 'get_stats') {
        $r = tool_http_get($base . '/admin/GET/stats_summary.php', $toolToken);
        return [$r['data'], ['tool' => $name, 'env' => $env, 'ok' => $r['ok']]];
    }
    if ($name === 'tail_service_log') {
        $service = $args['service'] ?? 'binance';
        $lines = isset($args['lines']) ? (int) $args['lines'] : 120;
        $url = $base . '/admin/GET/service_logs.php?service=' . urlencode($service) . '&lines=' . $lines;
        if (!empty($args['file'])) {
            $url .= '&file=' . urlencode($args['file']);
        }
        $r = tool_http_get($url, $toolToken);
        return [$r['data'], ['tool' => $name, 'env' => $env, 'service' => $service, 'ok' => $r['ok']]];
    }
    if ($name === 'get_recent_registrations') {
        $limit = isset($args['limit']) ? max(1, min(50, (int) $args['limit'])) : 10;
        $url = $base . '/admin/GET/get_recent_registrations.php?limit=' . $limit;
        $r = tool_http_get($url, $toolToken);
        return [$r['data'], ['tool' => $name, 'env' => $env, 'ok' => $r['ok']]];
    }
    return ['{"error":"unknown tool"}', ['tool' => $name, 'ok' => false]];
}

// --- Call OpenAI chat/completions ---
function openai_chat($apiKey, $model, $messages, $tools) {
    $payload = [
        'model' => $model,
        'messages' => $messages,
        'tools' => $tools,
        'tool_choice' => 'auto',
        'temperature' => 0.2,
    ];
    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    $opts = [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ],
        CURLOPT_TIMEOUT => 55,
        CURLOPT_CONNECTTIMEOUT => 15,
    ];
    if (is_file(CHATBOT_CA_BUNDLE)) {
        $opts[CURLOPT_CAINFO] = CHATBOT_CA_BUNDLE;
    }
    curl_setopt_array($ch, $opts);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($resp === false) {
        return ['ok' => false, 'error' => 'OpenAI request failed: ' . $err];
    }
    $data = json_decode($resp, true);
    if ($code < 200 || $code >= 300) {
        $msg = isset($data['error']['message']) ? $data['error']['message'] : ('HTTP ' . $code);
        return ['ok' => false, 'error' => $msg];
    }
    return ['ok' => true, 'data' => $data];
}

$today = date('Y-m-d');
$systemPrompt =
    "You are the Sinegu admin Assistant, embedded in the admin dashboard. Today is {$today}.\n" .
    "You help the admin inspect the Sinegu trading platform across two environments:\n" .
    "- PROD: VPS 167.86.109.159, API https://api.sinegualfamily.com\n" .
    "- STAGING: VPS 156.67.30.81, API https://api.andrea-orcelinvest.com\n" .
    "Each VPS runs three Flask bots as Windows NSSM services: 'binance' (Binance), 'capital' (Capital.com), 'ig' (IG).\n" .
    "Use the tools to get real data — never invent numbers or log contents. Call get_stats for counts, " .
    "tail_service_log to read/tail a bot's log, get_recent_registrations for recent sign-ups. " .
    "If a question spans both environments (e.g. 'users on prod vs staging'), call the tool once per environment. " .
    "When showing logs, summarise notable lines (errors, trades, restarts) and keep raw excerpts short. " .
    "All tools are read-only; you cannot change anything. Be concise and use markdown.";

$messages = array_merge([['role' => 'system', 'content' => $systemPrompt]], $history);

$trace = [];
$maxRounds = 6;
$finalReply = null;

for ($round = 0; $round < $maxRounds; $round++) {
    $res = openai_chat($apiKey, $model, $messages, $tools);
    if (!$res['ok']) {
        // Return 200 with success:false so the message survives Cloudflare (which
        // replaces 5xx bodies with its own page) and reaches the UI.
        respond(200, ['success' => false, 'message' => $res['error']]);
    }
    $choice = $res['data']['choices'][0] ?? null;
    if (!$choice) {
        respond(200, ['success' => false, 'message' => 'Empty response from model']);
    }
    $msg = $choice['message'];
    $messages[] = $msg; // append assistant turn (may contain tool_calls)

    $toolCalls = $msg['tool_calls'] ?? null;
    if (empty($toolCalls)) {
        $finalReply = is_string($msg['content'] ?? null) ? $msg['content'] : '';
        break;
    }

    // Execute each requested tool and feed results back.
    foreach ($toolCalls as $tc) {
        $fname = $tc['function']['name'] ?? '';
        $fargs = json_decode($tc['function']['arguments'] ?? '{}', true);
        if (!is_array($fargs)) $fargs = [];
        list($result, $entry) = run_tool($fname, $fargs, $toolToken);
        $entry['args'] = $fargs;
        $trace[] = $entry;
        $messages[] = [
            'role' => 'tool',
            'tool_call_id' => $tc['id'],
            'content' => is_string($result) ? mb_substr($result, 0, 12000) : json_encode($result),
        ];
    }
}

if ($finalReply === null) {
    $finalReply = 'I gathered the data but could not finish composing a reply — please try rephrasing.';
}

respond(200, [
    'success' => true,
    'reply'   => $finalReply,
    'trace'   => $trace,
    'model'   => $model,
]);
