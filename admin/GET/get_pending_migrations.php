<?php
/**
 * Admin: Report migration status — which NNN_*.sql files are applied vs pending.
 * GET /admin/GET/get_pending_migrations.php
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

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

    $pending = [];
    $appliedList = [];
    foreach ($all as $f) {
        if (isset($applied[$f])) {
            $appliedList[] = $f;
        } else {
            $pending[] = $f;
        }
    }

    echo json_encode([
        'success' => true,
        'total' => count($all),
        'applied_count' => count($appliedList),
        'pending_count' => count($pending),
        'pending' => $pending,
        'applied' => $appliedList,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
