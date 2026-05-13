<?php
/**
 * Log API calls to api_logs table for admin monitoring.
 * Usage: require_once __DIR__ . '/../includes/api_log.php';
 *        api_log($conn, 'users/login', 'User login', 'login', true);
 *
 * @param mysqli|false $conn Database connection
 * @param string $api_name e.g. 'users/login', 'flask/delete_account'
 * @param string $description Human-readable description
 * @param string $action login|create|update|delete|fetch|etc
 * @param bool $success Whether the call succeeded
 */
function api_log($conn, $api_name, $description, $action, $success) {
    if (!$conn || !is_object($conn)) {
        return;
    }
    $api_name = substr((string) $api_name, 0, 128);
    $description = substr((string) $description, 0, 255);
    $action = substr((string) $action, 0, 64) ?: 'unknown';
    $success_val = $success ? 1 : 0;
    $stmt = mysqli_prepare($conn, 'INSERT INTO api_logs (api_name, description, action, success) VALUES (?, ?, ?, ?)');
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 'sssi', $api_name, $description, $action, $success_val);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    }
}
