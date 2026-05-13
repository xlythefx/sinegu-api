<?php
/**
 * Minimal audit logging for account and auth events.
 * Logs to logs/audit.log (no passwords or tokens). Create logs/ dir if missing.
 *
 * Usage: require_once __DIR__ . '/../includes/audit_log.php'; audit_log('event_name', ['key' => 'value']);
 */

function audit_log($event, $details = []) {
    $logDir = dirname(__DIR__) . '/logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0755, true);
    }
    $path = $logDir . '/audit.log';
    $ts = date('Y-m-d H:i:s');
    $safe = [];
    foreach ($details as $k => $v) {
        if (in_array(strtolower((string)$k), ['password', 'secret', 'token', 'cst', 'x_security_token', 'api_key'], true)) {
            continue;
        }
        $safe[$k] = is_scalar($v) ? $v : json_encode($v);
    }
    $line = $ts . ' ' . $event . ' ' . json_encode($safe) . "\n";
    @file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
}
