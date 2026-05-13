<?php
/**
 * GET billing periods for user by uni_id.
 * Context: user_credentials.uni_id → accounts.uni_id → accounts.id = billing_periods.account_id
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

$billing_periods = [];

// 1. Get account ids for this user (accounts.uni_id = user's uni_id)
$stmt_accounts = $conn->prepare('SELECT id FROM accounts WHERE uni_id = ?');
$stmt_accounts->bind_param('s', $uni_id);
$stmt_accounts->execute();
$res_accounts = $stmt_accounts->get_result();
$account_ids = [];
while ($row = $res_accounts->fetch_assoc()) {
  $account_ids[] = (int) $row['id'];
}
$stmt_accounts->close();

if (count($account_ids) === 0) {
  echo json_encode(['success' => true, 'billing_periods' => []]);
  exit;
}

// 2. Build placeholders for IN clause
$placeholders = implode(',', array_fill(0, count($account_ids), '?'));
$sql = "SELECT id, user_id, account_id, month_year, equity_start, equity_end, realized_pnl, unrealized_pnl, deposit_amount, adjusted_equity, hwm_before, hwm_after, new_realized_profit, new_unrealized_profit, fee_realized, fee_unrealized, total_fee, status, capital_flow, created_at, updated_at FROM billing_periods WHERE account_id IN ($placeholders) ORDER BY month_year DESC";

$stmt = $conn->prepare($sql);
$stmt->bind_param(str_repeat('i', count($account_ids)), ...$account_ids);
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
  $billing_periods[] = [
    'id' => (int) $row['id'],
    'user_id' => (string) ($row['user_id'] ?? ''),
    'account_id' => (int) $row['account_id'],
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
