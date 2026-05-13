# Invoice Generation – Test Cases

This document describes how **generate_invoices.php** behaves and gives concrete test cases for three billing months: **2025-09**, **2025-10**, **2025-11**. The first invoice (2025-09) is always a “profit” scenario; subsequent months (2025-10, 2025-11) are used to test different situations (balance above/below HWM, realized up/down, unrealized up/down).

---

## 1. How invoice logic works (recap)

### 1.1 Definitions

| Term | Meaning |
|------|--------|
| **Equity (balance)** | `adjusted_deposit_amount + unrealized_pnl + total_realized_pnl`. Current “balance” used for the charge gate. |
| **HWM (High Water Mark)** | Previous peak used for performance. Decreases only on withdrawal override (withdrawal in billing month + both realized and unrealized P&L positive). |
| **First invoice** | No previous billing period; HWM before = `initial_deposit` (or adjusted deposit if no initial). |
| **Subsequent invoice** | Has previous billing period; HWM before = previous period’s `hwm_after`; previous equity = previous period’s `equity_end`. |

### 1.2 Charge rule (current implementation)

- **Charge fees** when **current equity (balance) > previous HWM**.
- **Withdrawal override:** If **equity ≤ previous HWM** but there was a net withdrawal in the billing month (`capital_flow < 0`) and **both** realized and unrealized P&L are positive, charge fees and set `hwm_after = current equity` (HWM drops).
- If **equity ≤ previous HWM** and no withdrawal override: no fees, billing period created with `total_fee = 0`, `status = 'paid'`, HWM unchanged (only `last_billed_month` / `last_billed_at` updated).

### 1.3 When we do charge

- **First invoice:** `equity > hwm` (hwm = initial deposit). Fee on: `new_realized_profit = max(0, realized_pnl_month)`, `new_unrealized_profit = max(0, unrealized_pnl)`. `hwm_after = max(hwm, equity)`.
- **Subsequent invoice:** `equity > hwm_before`. Fee on: `new_realized_profit = max(0, realized_pnl_month)`, `new_unrealized_profit = max(0, unrealized_pnl)`. `hwm_after = performance_equity` (previous_equity + trading_profit for the month). HWM in `accounts` is updated only when we charge.

### 1.4 Fee formula

- `fee_realized = new_realized_profit × realized_rate` (e.g. 20%).
- `fee_unrealized = new_unrealized_profit × unrealized_rate` (e.g. 30%).
- `total_fee = fee_realized + fee_unrealized`.

### 1.5 Data used per month

- **Realized PnL (month):** Sum of `trades.pnl` for trades closed in that billing month only.
- **Unrealized PnL:** From `accounts.unrealized_pnl` at run time (current open positions).
- **Equity:** Uses current `adjusted_deposit_amount`, current `unrealized_pnl`, and **cumulative** `total_realized_pnl` (all trades), so “balance” is the current snapshot.

---

## 2. Test setup (used in all cases)

- **Billing months:** 2025-09, 2025-10, 2025-11 (three invoices).
- **Initial deposit:** 20,000 (no capital_transactions in these tests, so adjusted_deposit = 20,000).
- **Rates:** Realized 20%, Unrealized 30%.
- **First invoice (2025-09):** Always profit (equity > initial deposit) so there is a fee and HWM is set.

All amounts in the tables below are in the same currency (e.g. EUR/USD). “Balance” = equity as defined above.

---

## 3. Test Case 1 – All three months profitable (balance always above HWM)

**Goal:** First invoice profit; next two months also above their previous HWM so we charge every time.

| Month   | Balance (equity) | HWM before | Realized (month) | Unrealized | Charge? | HWM after | Fee (20% / 30%) | Status   |
|---------|------------------|------------|------------------|------------|--------|-----------|------------------|----------|
| 2025-09 | 20,500           | 20,000     | 400              | 100        | Yes    | 20,500    | 80 + 30 = 110    | pending  |
| 2025-10 | 21,000           | 20,500     | 350              | 150        | Yes    | 21,000    | 70 + 45 = 115    | pending  |
| 2025-11 | 21,400           | 21,000     | 250              | 150        | Yes    | 21,400    | 50 + 45 = 95     | pending  |

- 2025-09: First invoice; equity 20,500 > 20,000 → charge; HWM moves to 20,500.
- 2025-10: Equity 21,000 > 20,500 → charge; HWM moves to 21,000.
- 2025-11: Equity 21,400 > 21,000 → charge; HWM moves to 21,400.

---

## 4. Test Case 2 – Second month balance below HWM (no fee, then fee again)

**Goal:** First profit; second month balance drops below previous HWM (no fee, status paid); third month balance above HWM again (fee).

| Month   | Balance (equity) | HWM before | Realized (month) | Unrealized | Charge? | HWM after | Total fee | Status  |
|---------|------------------|------------|------------------|------------|--------|-----------|-----------|--------|
| 2025-09 | 20,500           | 20,000     | 400              | 100        | Yes    | 20,500    | 110       | pending |
| 2025-10 | 20,200           | 20,500     | -200             | -100       | No     | 20,500    | 0         | paid   |
| 2025-11 | 20,700           | 20,500     | 350              | 150        | Yes    | 20,700    | 70 + 45   | pending |

- 2025-10: Equity 20,200 < 20,500 → do not charge; period created with fee 0, status paid; HWM stays 20,500.
- 2025-11: Equity 20,700 > 20,500 → charge; HWM moves to 20,700.

---

## 5. Test Case 3 – Third month drawdown (balance below HWM)

**Goal:** First two months profit; third month balance below second month’s HWM → no fee, status paid.

| Month   | Balance (equity) | HWM before | Realized (month) | Unrealized | Charge? | HWM after | Total fee | Status  |
|---------|------------------|------------|------------------|------------|--------|-----------|-----------|--------|
| 2025-09 | 20,500           | 20,000     | 400              | 100        | Yes    | 20,500    | 110       | pending |
| 2025-10 | 21,000           | 20,500     | 350              | 150        | Yes    | 21,000    | 115       | pending |
| 2025-11 | 20,600           | 21,000     | -300             | -100       | No     | 21,000    | 0         | paid   |

- 2025-11: Equity 20,600 < 21,000 → no fee; HWM remains 21,000; status paid.

---

## 6. Test Case 4 – Realized up, unrealized down (and vice versa)

**Goal:** Mix of realized and unrealized (one positive, one negative) so that balance vs HWM drives the decision.

### 6a – Month with realized profit, unrealized loss; balance still above HWM

| Month   | Balance (equity) | HWM before | Realized (month) | Unrealized | Charge? | New realized profit | New unrealized profit | Fee        |
|---------|------------------|------------|------------------|------------|--------|----------------------|------------------------|------------|
| 2025-09 | 20,500           | 20,000     | 400              | 100        | Yes    | 400                  | 100                    | 80 + 30    |
| 2025-10 | 20,450           | 20,500     | 200              | -250       | No     | -                    | -                      | 0          |

- 2025-10: Equity 20,450 < 20,500 → no charge (balance below HWM even though realized is +200).

### 6b – Month with realized loss, unrealized profit; balance above HWM

| Month   | Balance (equity) | HWM before | Realized (month) | Unrealized | Charge? | New realized profit | New unrealized profit | Fee        |
|---------|------------------|------------|------------------|------------|--------|----------------------|------------------------|------------|
| 2025-09 | 20,500           | 20,000     | 400              | 100        | Yes    | 400                  | 100                    | 80 + 30    |
| 2025-10 | 20,600           | 20,500     | -100             | 200        | Yes    | 0                    | 200                    | 0 + 60     |

- 2025-10: Equity 20,600 > 20,500 → charge; only unrealized contributes to fee (new_realized_profit = max(0, -100) = 0).

### 6c – Both realized and unrealized negative; balance below HWM

| Month   | Balance (equity) | HWM before | Realized (month) | Unrealized | Charge? | Fee |
|---------|------------------|------------|------------------|------------|--------|-----|
| 2025-09 | 20,500           | 20,000     | 400              | 100        | Yes    | 110 |
| 2025-10 | 20,100           | 20,500     | -200             | -200       | No     | 0   |

- 2025-10: Equity 20,100 < 20,500 → no fee; status paid.

---

## 7. Test Case 5 – Balance exactly at HWM (no fee)

**Goal:** Equity equals previous HWM → no fee (equity not strictly greater than HWM).

| Month   | Balance (equity) | HWM before | Realized (month) | Unrealized | Charge? | HWM after | Total fee | Status |
|---------|------------------|------------|------------------|------------|--------|-----------|-----------|--------|
| 2025-09 | 20,500           | 20,000     | 400              | 100        | Yes    | 20,500    | 110       | pending |
| 2025-10 | 20,500           | 20,500     | 0                | 0          | No     | 20,500    | 0         | paid   |
| 2025-11 | 20,600           | 20,500     | 50               | 50         | Yes    | 20,600    | 10 + 15   | pending |

- 2025-10: Equity 20,500 is not > 20,500 → no charge; status paid.

---

## 8. Test Case 6 – First invoice no profit (balance ≤ initial deposit)

**Goal:** First period has equity ≤ initial deposit → no fee, status paid, HWM stays at initial.

| Month   | Balance (equity) | HWM before | Realized (month) | Unrealized | Charge? | HWM after | Total fee | Status |
|---------|------------------|------------|------------------|------------|--------|-----------|-----------|--------|
| 2025-09 | 19,800           | 20,000     | -150             | -50        | No     | 20,000    | 0         | paid   |
| 2025-10 | 20,200           | 20,000     | 300              | 100        | Yes    | 20,200    | 60 + 30   | pending |
| 2025-11 | 20,500           | 20,200     | 200              | 100        | Yes    | 20,500    | 40 + 30   | pending |

- 2025-09: Equity 19,800 < 20,000 → no fee; HWM remains 20,000; status paid.
- 2025-10: Equity 20,200 > 20,000 → charge; HWM moves to 20,200.

---

## 9. Test Case 7 – Two consecutive months below HWM

**Goal:** Second and third month both below HWM → no fee in either; HWM unchanged after first invoice.

| Month   | Balance (equity) | HWM before | Realized (month) | Unrealized | Charge? | HWM after | Total fee | Status |
|---------|------------------|------------|------------------|------------|--------|-----------|-----------|--------|
| 2025-09 | 20,500           | 20,000     | 400              | 100        | Yes    | 20,500    | 110       | pending |
| 2025-10 | 20,300           | 20,500     | -100             | -100       | No     | 20,500    | 0         | paid   |
| 2025-11 | 20,400           | 20,500     | 50               | 50         | No     | 20,500    | 0         | paid   |

- 2025-10 and 2025-11: Equity never > 20,500 → no fee; HWM stays 20,500; both periods paid.

---

## 10. Test Case 8 – Withdrawal to avoid fees (both profits positive)

**Goal:** User withdraws funds so balance drops below HWM, but both realized and unrealized P&L are positive. Withdrawal override applies: charge fees and set HWM = current balance.

| Month   | Balance (equity) | HWM before | Realized (month) | Unrealized | Capital flow | Charge? | HWM after | Total fee | Status  |
|---------|------------------|------------|------------------|------------|--------------|---------|-----------|-----------|---------|
| 2026-01 | 19,350           | 20,200     | 200              | 50         | -1,000       | Yes     | 19,350    | 40 + 3    | pending |

- Equity 19,350 < HWM 20,200 (balance below HWM due to withdrawal).
- Withdrawal detected: capital_flow = -1,000 (net withdrawal in billing month).
- Both realized (+200) and unrealized (+50) positive → **withdrawal override** applies.
- Charge fees (20% on 200 = 40, 6% on 50 = 3); HWM drops to 19,350 (current equity).

**Data setup:** Requires `capital_transactions` with WITHDRAWAL record(s) in the billing month summing to -1,000 (or equivalent).

---

## 11. Summary table (quick reference)

| Case | 2025-09      | 2025-10      | 2025-11      | What is tested                          |
|------|--------------|--------------|--------------|-----------------------------------------|
| 1    | Fee, HWM up  | Fee, HWM up  | Fee, HWM up  | All months above HWM                    |
| 2    | Fee, HWM up  | No fee, paid | Fee, HWM up  | Below HWM then above again              |
| 3    | Fee, HWM up  | Fee, HWM up  | No fee, paid | Drawdown in third month                 |
| 4a   | Fee          | No fee       | -            | Realized +, unrealized −; balance < HWM |
| 4b   | Fee          | Fee (unreal.)| -            | Realized −, unrealized +; balance > HWM |
| 4c   | Fee          | No fee       | -            | Both −; balance < HWM                   |
| 5    | Fee          | No fee (at HWM) | Fee       | Equity = HWM → no fee                   |
| 6    | No fee, paid | Fee, HWM up  | Fee, HWM up  | First month equity ≤ initial            |
| 7    | Fee, HWM up  | No fee, paid | No fee, paid | Two months in a row below HWM            |
| 8    | -            | -            | Fee, HWM down| Withdrawal + both profits +; charge and lower HWM |

---

## 12. How to run tests against generate_invoices.php

1. **Data setup:** For each test case, set up (or mock) for the account:
   - `accounts.initial_deposit`, `accounts.deposit_amount`, `accounts.unrealized_pnl`
   - `accounts.hwm_equity`, `accounts.hwm_realized`, `accounts.hwm_unrealized`, `accounts.last_billed_month`
   - `trades` with `date_closed` in the correct billing month and `pnl` for realized PnL
   - `capital_transactions` if testing deposits/withdrawals (required for Test Case 8)
2. **Run:** POST to `generate_invoices.php` with the appropriate `currentMonth` (or equivalent) so that the script runs for 2025-09, then 2025-10, then 2025-11 (or run once per month in sequence).
3. **Assert:** For each month, check `billing_periods`: `total_fee`, `status`, `hwm_before`, `hwm_after`, and that `accounts.hwm_equity` and `last_billed_month` match the expected HWM and billing month.

These test cases cover the generate-invoices logic: **charge only when balance > previous HWM**, with a **withdrawal override** when capital_flow < 0 and both realized and unrealized P&L are positive (Test Case 8).
