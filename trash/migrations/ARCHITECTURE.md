# Invoice System Architecture

## Overview

The new invoice system is designed to properly track monthly performance fees with clear period boundaries, trade associations, and payment tracking.

## Key Concepts

### Invoice Period
- Each invoice covers a specific month (e.g., November 2025)
- Invoices are generated on the 1st of the **next** month
- Example: December 1st invoice covers November performance

### High Water Mark (HWM)
- The highest account balance at the time of the last invoice payment
- Used as reference point for calculating performance gains
- First invoice uses deposit amount as reference
- Subsequent invoices use previous invoice's `period_end_balance` as HWM

### Performance Gain Calculation
1. **First Invoice**: `performance_gain = profitloss` (from account)
2. **Subsequent Invoices**: `performance_gain = period_end_balance - hwm_reference`

### Fee Calculation
- `fee_amount = performance_gain * fee_rate` (default 20%)
- Only charged if `performance_gain > 0`

## Database Schema

### `invoices` Table
Main invoice records with all period and financial data.

**Key Fields:**
- `invoice_month`: The month this invoice covers (YYYY-MM-01)
- `invoice_date`: When invoice was generated (1st of next month)
- `due_date`: Payment due date (invoice_date + 7 days)
- `status`: pending, paid, overdue, cancelled
- `period_start_balance`: Account balance at start of period
- `period_end_balance`: Account balance at end of period (becomes new HWM)
- `hwm_reference`: Previous HWM used for calculation
- `performance_gain`: Gain during period
- `total_pnl`: Sum of PNL from trades in this period
- `fee_amount`: Calculated fee (performance_gain * fee_rate)

### `invoice_trades` Table
Junction table linking trades to invoices for accurate period-based calculations.

**Purpose:**
- Links specific trades to invoice periods
- Allows accurate PNL calculation per invoice period
- Enables trade-level reporting per invoice

### `invoice_payments` Table
Tracks payment transactions for invoices.

**Purpose:**
- Records payment method and gateway transaction IDs
- Tracks payment status (pending, completed, failed, refunded)
- Stores payment timestamps
- Supports multiple payment attempts

## Invoice Generation Flow

1. **On 1st of Month** (e.g., December 1st):
   - System generates invoice for previous month (November)
   - Calculates period boundaries:
     - `invoice_month`: 2025-11-01
     - `invoice_date`: 2025-12-01
     - `due_date`: 2025-12-08
   
2. **Calculate Performance**:
   - Get account balance at end of November
   - Get HWM from last paid invoice (or deposit for first)
   - Calculate: `performance_gain = end_balance - hwm`
   - Calculate: `fee_amount = performance_gain * 0.20`
   
3. **Link Trades**:
   - Find all trades in November (2025-11-01 to 2025-11-30)
   - Create `invoice_trades` records linking trades to invoice
   - Calculate `total_pnl` from linked trades

4. **Set Status**:
   - If `fee_amount > 0`: status = 'pending'
   - If `fee_amount = 0`: status = 'cancelled' (no fee)

## Payment Flow

1. **User Initiates Payment**:
   - Frontend calls payment gateway API
   - Payment gateway processes transaction
   - Webhook callback received

2. **Mark Invoice as Paid**:
   - Create `invoice_payments` record
   - Update `invoices.status` to 'paid'
   - Update `invoices.paid_date`
   - Update account HWM to `period_end_balance`

3. **Update Account**:
   - The `period_end_balance` becomes the new HWM
   - Next invoice will use this as `hwm_reference`

## Query Examples

### Get Unpaid Invoices for User
```sql
SELECT i.* 
FROM invoices i
INNER JOIN accounts a ON a.id = i.account_id
WHERE a.uni_id = 'user-unique-id'
AND i.status = 'pending'
AND i.due_date >= CURDATE()
ORDER BY i.due_date ASC;
```

### Get Invoice with Trade Details
```sql
SELECT 
  i.*,
  COUNT(it.trade_id) AS trade_count,
  COALESCE(SUM(t.pnl), 0) AS total_pnl
FROM invoices i
LEFT JOIN invoice_trades it ON it.invoice_id = i.invoice_id
LEFT JOIN trades t ON t.id = it.trade_id
WHERE i.invoice_id = ?
GROUP BY i.invoice_id;
```

### Calculate Performance for Period
```sql
SELECT 
  i.invoice_id,
  i.period_end_balance - COALESCE(i.hwm_reference, a.deposit_amount) AS performance_gain
FROM invoices i
INNER JOIN accounts a ON a.id = i.account_id
WHERE i.invoice_id = ?;
```

## Migration from Old System

The old `invoice` table had:
- `user_id` (actually account_id)
- `hwm` (high water mark)
- `pnl` (profit/loss)
- `last_date_invoice` (invoice date)

The migration script transforms this to the new structure by:
1. Mapping `user_id` → `account_id`
2. Using `last_date_invoice` to determine `invoice_month`
3. Calculating `period_end_balance` from `hwm`
4. Using `pnl` as initial `performance_gain`
5. Linking historical trades to invoices based on dates

