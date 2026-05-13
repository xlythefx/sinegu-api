<?php
/**
 * GET binance_invoices for user by uni_id.
 * Mirrors get_billing_periods.php shape so the frontend HWM card can reuse the BillingPeriod type.
 * Backend: binance_invoices.user_id = user_credentials.uni_id (api_key joins binance_accounts).
 */

header('Content-Type: application/json; charset=utf-8');
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

$uni_id = isset($_GET['uni_id']) ? trim($_GET['uni_id']) : '';
if ($uni_id === '') {
  http_response_code(400);
  echo json_encode(['success' => false, 'message' => 'uni_id required']);
  exit;
}

require_once __DIR__ . '/../../conn.php';

if (!$conn) {
  http_response_code(500);
  echo json_encode(['success' => false, 'message' => 'Database connection failed']);
  exit;
}

$sql = "SELECT id, user_id, api_key, month_year, equity_start, equity_end, realized_pnl, unrealized_pnl, deposit_amount, adjusted_equity, hwm_before, hwm_after, new_realized_profit, new_unrealized_profit, fee_realized, fee_unrealized, total_fee, status, capital_flow, created_at, updated_at FROM binance_invoices WHERE user_id = ? ORDER BY month_year DESC";

$stmt = $conn->prepare($sql);
$stmt->bind_param('s', $uni_id);
$stmt->execute();
$result = $stmt->get_result();

$billing_periods = [];
while ($row = $result->fetch_assoc()) {
  $billing_periods[] = [
    'id' => (int) $row['id'],
    'user_id' => (string) ($row['user_id'] ?? ''),
    'account_id' => 0,
    'api_key' => (string) ($row['api_key'] ?? ''),
    'month_year' => (string) ($row['month_year'] ?? ''),
    'equity_start' => (float) ($row['equity_start'] ?? 0),
    'equity_end' => (float) ($row['equity_end'] ?? 0),
    'realized_pnl' => (float) ($row['realized_pnl'] ?? 0),
    'unrealized_pnl' => (float) ($row['unrealized_pnl'] ?? 0),
    'deposit_amount' => (float) ($row['deposit_amount'] ?? 0),
    'adjusted_equity' => (float) ($row['adjusted_equity'] ?? 0),
    'hwm_before' => (float) ($row['hwm_before'] ?? 0),
    'hwm_after' => (float) ($row['hwm_after'] ?? 0),
    'new_realized_profit' => (float) ($row['new_realized_profit'] ?? 0),
    'new_unrealized_profit' => (float) ($row['new_unrealized_profit'] ?? 0),
    'fee_realized' => (float) ($row['fee_realized'] ?? 0),
    'fee_unrealized' => (float) ($row['fee_unrealized'] ?? 0),
    'total_fee' => (float) ($row['total_fee'] ?? 0),
    'status' => (string) ($row['status'] ?? ''),
    'capital_flow' => isset($row['capital_flow']) ? (float) $row['capital_flow'] : null,
    'created_at' => (string) ($row['created_at'] ?? ''),
    'updated_at' => (string) ($row['updated_at'] ?? ''),
  ];
}

$stmt->close();

echo json_encode(['success' => true, 'billing_periods' => $billing_periods]);
