<?php
/**
 * Admin: Affiliate ledger stats – fees sent (count + total amount).
 * GET /admin/GET/get_affiliate_ledger_stats.php
 * "Referrers with unreleased" is derived from overview (has_releasable) on the frontend.
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
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

require_once __DIR__ . '/../../conn.php';

if (!$conn) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit;
}

$totalSentCount = 0;
$totalSentAmount = 0.0;
$res = mysqli_query($conn, "SELECT COUNT(*) AS c, COALESCE(SUM(total_amount), 0) AS s FROM referrer_payouts");
if ($res && $row = mysqli_fetch_assoc($res)) {
    $totalSentCount = (int) ($row['c'] ?? 0);
    $totalSentAmount = (float) ($row['s'] ?? 0);
}

mysqli_close($conn);

echo json_encode([
    'success' => true,
    'total_sent_count' => $totalSentCount,
    'total_sent_amount' => round($totalSentAmount, 2),
]);
