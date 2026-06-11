<?php
/**
 * Admin: Apply all pending NNN_*.sql migrations in order. Halts on first failure.
 * POST /admin/POST/run_pending_migrations.php
 */

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
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

set_time_limit(300);

require_once __DIR__ . '/../../conn.php';
require_once __DIR__ . '/../../migrations/migrate.php';

if (!$conn) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit;
}

try {
    ensure_table($conn);
    $all = list_files();
    $applied = applied_set($conn);

    $appliedFiles = [];
    $failedFile = null;
    $errorMsg = null;

    foreach ($all as $f) {
        if (isset($applied[$f])) continue;
        [$ok, $msg] = run_file($conn, $f);
        if ($ok) {
            record($conn, $f);
            $appliedFiles[] = $f;
        } else {
            $failedFile = $f;
            $errorMsg = $msg;
            break;
        }
    }

    echo json_encode([
        'success' => $failedFile === null,
        'applied_count' => count($appliedFiles),
        'applied_files' => $appliedFiles,
        'failed_file' => $failedFile,
        'error' => $errorMsg,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'applied_count' => count($appliedFiles ?? []),
        'applied_files' => $appliedFiles ?? [],
        'failed_file' => null,
        'error' => $e->getMessage(),
    ]);
}
