<?php
/**
 * Admin: Clear Binance invoices.
 *
 * POST /admin/POST/clear_binance_invoices.php
 *
 * Deletes every row in binance_invoices. Binance is self-contained: unlike
 * Capital.com (which also keeps hwm_* columns on `accounts`), the Binance
 * high-water mark lives ONLY in binance_invoices.hwm_after, so removing the
 * rows is a complete HWM reset – there is nothing else to clear.
 *
 * Body (JSON):
 *   preview    bool    – report what would be deleted, change nothing (default false)
 *   confirm    string  – must equal "DELETE ALL BINANCE INVOICES" to actually delete
 *   reset_ids  bool    – also reset AUTO_INCREMENT so numbering restarts at INV-001
 *                        (default false: Stripe/Coinsbuy metadata references invoice
 *                        ids, and reusing them would confuse payment reconciliation)
 *
 * WARNING: paid invoices carry their payment state in `status` on the row itself –
 * there is no separate payments table. Deleting a paid invoice destroys the only
 * record that it was paid. The response reports the paid count so the caller can
 * warn before proceeding.
 *
 * Capital.com (billing_periods) and IG (ig_invoices) are never touched.
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

require_once __DIR__ . '/../../conn.php';

if (!$conn) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit;
}

const CONFIRM_PHRASE = 'DELETE ALL BINANCE INVOICES';

$raw   = file_get_contents('php://input');
$input = ($raw !== false && $raw !== '') ? json_decode($raw, true) : [];
if (!is_array($input)) {
    $input = [];
}

$isPreview = !empty($input['preview']);
$resetIds  = !empty($input['reset_ids']);
$confirm   = isset($input['confirm']) ? trim((string)$input['confirm']) : '';

// Preview only counts rows and is harmless, so it stays open; anything that
// deletes must present the admin password.
if (!$isPreview) {
    require_once __DIR__ . '/../../includes/admin_action_auth.php';
    requireAdminActionPassword($input);
}

/**
 * Snapshot of what is in binance_invoices right now.
 */
function binanceInvoiceSnapshot($conn) {
    $snapshot = [
        'total'             => 0,
        'by_status'         => [],
        'paid_count'        => 0,
        'total_fees'        => 0.0,
        'accounts_affected' => 0,
        'months'            => [],
    ];

    $res = mysqli_query($conn, "SELECT status, COUNT(*) AS c, COALESCE(SUM(total_fee), 0) AS fees
                                FROM binance_invoices GROUP BY status");
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            $status = $row['status'] ?? 'unknown';
            $count  = (int)$row['c'];
            $snapshot['by_status'][$status] = $count;
            $snapshot['total']      += $count;
            $snapshot['total_fees'] += (float)$row['fees'];
            if ($status === 'paid') {
                $snapshot['paid_count'] += $count;
            }
        }
        mysqli_free_result($res);
    }

    $accRes = mysqli_query($conn, "SELECT COUNT(DISTINCT api_key) AS c FROM binance_invoices");
    if ($accRes) {
        $accRow = mysqli_fetch_assoc($accRes);
        $snapshot['accounts_affected'] = (int)($accRow['c'] ?? 0);
        mysqli_free_result($accRes);
    }

    $mRes = mysqli_query($conn, "SELECT DISTINCT month_year FROM binance_invoices ORDER BY month_year");
    if ($mRes) {
        while ($row = mysqli_fetch_assoc($mRes)) {
            $snapshot['months'][] = $row['month_year'];
        }
        mysqli_free_result($mRes);
    }

    $snapshot['total_fees'] = round($snapshot['total_fees'], 2);
    return $snapshot;
}

try {
    $snapshot = binanceInvoiceSnapshot($conn);

    // Preview: report only, touch nothing.
    if ($isPreview) {
        echo json_encode(array_merge([
            'success' => true,
            'preview' => true,
            'deleted' => 0,
        ], $snapshot));
        mysqli_close($conn);
        exit;
    }

    // Destructive from here – require the exact confirmation phrase.
    if ($confirm !== CONFIRM_PHRASE) {
        http_response_code(400);
        echo json_encode(array_merge([
            'success' => false,
            'error'   => 'Confirmation required. Send {"confirm":"' . CONFIRM_PHRASE . '"} to proceed.',
            'preview' => true,
            'deleted' => 0,
        ], $snapshot));
        mysqli_close($conn);
        exit;
    }

    mysqli_begin_transaction($conn);

    if (!mysqli_query($conn, "DELETE FROM binance_invoices")) {
        throw new Exception('Failed to clear binance_invoices: ' . mysqli_error($conn));
    }
    $deleted = mysqli_affected_rows($conn);

    mysqli_commit($conn);

    // AUTO_INCREMENT is DDL – it cannot roll back, so it runs only after commit.
    $idsReset = false;
    if ($resetIds) {
        $idsReset = (bool)mysqli_query($conn, "ALTER TABLE binance_invoices AUTO_INCREMENT = 1");
    }

    echo json_encode(array_merge([
        'success'   => true,
        'preview'   => false,
        'deleted'   => $deleted,
        'ids_reset' => $idsReset,
        'message'   => "Cleared $deleted Binance invoice(s). High-water marks reset – "
                     . "the next run rebuilds them from each account's net deposits.",
    ], $snapshot));

} catch (Exception $e) {
    mysqli_rollback($conn);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => $e->getMessage(),
        'deleted' => 0,
    ]);
} finally {
    if (isset($conn) && $conn) {
        mysqli_close($conn);
    }
}
