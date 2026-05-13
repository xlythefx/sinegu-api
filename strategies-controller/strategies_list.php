<?php
/**
 * Admin: list global strategy flags (same shape as assets_list).
 */

ob_start();

error_reporting(E_ALL);
ini_set('display_errors', 0);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if (!function_exists('respond_json')) {
    function respond_json(int $statusCode, array $payload): void
    {
        if (ob_get_length()) {
            ob_clean();
        }
        http_response_code($statusCode);
        echo json_encode($payload);
        ob_end_flush();
        exit;
    }
}

set_error_handler(static function ($severity, $message, $file, $line) {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

register_shutdown_function(static function () {
    $fatal = error_get_last();
    if (!$fatal) {
        return;
    }

    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
    if (!in_array($fatal['type'], $fatalTypes, true)) {
        return;
    }

    if (headers_sent()) {
        return;
    }

    if (ob_get_length()) {
        ob_clean();
    }

    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Fatal server error while listing strategies.',
        'error_code' => 'STRATEGIES_LIST_FATAL',
        'details' => $fatal['message'],
    ]);
});

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    respond_json(204, []);
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    respond_json(405, ['success' => false, 'message' => 'Method not allowed']);
}

require_once __DIR__ . '/../conn.php';

if (!$conn) {
    respond_json(500, [
        'success' => false,
        'message' => 'Database connection failed',
        'error_code' => 'DB_CONNECTION_FAILED',
    ]);
}

try {
    $requiredColumns = ['strategy_key', 'enabled', 'created_at', 'updated_at'];
    $columnsResult = mysqli_query($conn, 'SHOW COLUMNS FROM `strategies`');

    if (!$columnsResult) {
        $schemaErr = mysqli_error($conn);
        mysqli_close($conn);
        respond_json(500, [
            'success' => false,
            'message' => 'Failed to inspect strategies schema',
            'error_code' => 'STRATEGIES_SCHEMA_LOOKUP_FAILED',
            'details' => $schemaErr,
        ]);
    }

    $existingColumns = [];
    while ($col = mysqli_fetch_assoc($columnsResult)) {
        if (isset($col['Field'])) {
            $existingColumns[] = $col['Field'];
        }
    }
    mysqli_free_result($columnsResult);

    $missingColumns = array_values(array_diff($requiredColumns, $existingColumns));
    if (!empty($missingColumns)) {
        mysqli_close($conn);
        respond_json(500, [
            'success' => false,
            'message' => 'Strategies table schema mismatch. Required columns are missing.',
            'error_code' => 'STRATEGIES_SCHEMA_MISMATCH',
            'missing_columns' => $missingColumns,
        ]);
    }

    $sql = 'SELECT strategy_key, enabled, created_at, updated_at FROM strategies ORDER BY strategy_key ASC';
    $result = mysqli_query($conn, $sql);

    if (!$result) {
        $err = mysqli_error($conn);
        mysqli_close($conn);
        respond_json(500, [
            'success' => false,
            'message' => 'Failed to fetch strategies',
            'error_code' => 'STRATEGIES_QUERY_FAILED',
            'details' => $err,
        ]);
    }

    $strategies = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $row['enabled'] = isset($row['enabled']) ? (int) $row['enabled'] : 0;
        $strategies[] = $row;
    }

    mysqli_free_result($result);
    mysqli_close($conn);

    respond_json(200, ['success' => true, 'strategies' => $strategies]);
} catch (Throwable $e) {
    if (isset($conn) && $conn) {
        mysqli_close($conn);
    }
    respond_json(500, [
        'success' => false,
        'message' => 'Unexpected server error while listing strategies',
        'error_code' => 'STRATEGIES_LIST_UNEXPECTED',
        'details' => $e->getMessage(),
    ]);
}
