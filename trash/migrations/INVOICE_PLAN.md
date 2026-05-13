# Sinegual Billing System — Performance Fee Engine (Final Architecture)

This document describes the complete performance-fee billing system for Sinegual,
including High Water Mark logic, multi-account aggregation, deposit/withdrawal
adjustments, and monthly invoice generation.

---

# 1. SYSTEM RULES (BASED ON CLIENT ANSWERS)

### ✔ 1. HWM is **per broker account**
Each Capital.com account keeps its own HWM.

### ✔ 2. We compute using **Total Equity**, split into:
- **20% fee on Realized PNL**
- **30% fee on Unrealized PNL**

### ✔ 3. Timezone: **Europe** (use broker’s timezone)

### ✔ 4. Capital.com deposits/withdrawals are **automatically tracked**

### ✔ 5. Deposits **increase** the HWM baseline  
### ✔ 6. Withdrawals **decrease** the HWM baseline

### ✔ 7. One invoice per user per month  
But invoice contains **breakdown per account**, including which accounts have 0 fees.

### ✔ 8. Losing months → **no fee**, HWM remains unchanged

### ✔ 9. New account added mid-month →  
It **still receives its own HWM**, and appears in breakdown.

### ✔ 10. Inactive accounts (0 trades) → still displayed

### ✔ 11. Unpaid invoices block trading after **7-day grace period**

### ✔ 12. Invoices remain visible forever (history page)

### ✔ 13. Invoice notifications → **Email + Dashboard**

---

# 2. REQUIRED DATABASE TABLES

## 2.1 Modify `accounts` table

Add:

```sql
ALTER TABLE accounts
ADD COLUMN hwm_equity DECIMAL(12,2) DEFAULT 0,
ADD COLUMN hwm_realized DECIMAL(12,2) DEFAULT 0,
ADD COLUMN hwm_unrealized DECIMAL(12,2) DEFAULT 0;
```

These store per-account HWM values.

---

## 2.2 New Table: `capital_transactions`

Tracks deposits & withdrawals from Capital.com.

```sql
CREATE TABLE capital_transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    account_id INT NOT NULL,
    type ENUM('deposit','withdrawal') NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    balance_after DECIMAL(12,2),
    created_at DATETIME NOT NULL,
    FOREIGN KEY (account_id) REFERENCES accounts(id)
);
```

---

## 2.3 New Table: `billing_periods`

Stores month-by-month performance for each account.

```sql
CREATE TABLE billing_periods (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id VARCHAR(255) NOT NULL,
    account_id INT NOT NULL,
    period_month CHAR(7) NOT NULL, -- YYYY-MM
    start_equity DECIMAL(12,2),
    end_equity DECIMAL(12,2),
    realized_pnl DECIMAL(12,2),
    unrealized_pnl DECIMAL(12,2),
    hwm_before_equity DECIMAL(12,2),
    hwm_after_equity DECIMAL(12,2),
    new_realized_profit DECIMAL(12,2),
    new_unrealized_profit DECIMAL(12,2),
    fee_realized DECIMAL(12,2),
    fee_unrealized DECIMAL(12,2),
    total_fee DECIMAL(12,2),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (account_id) REFERENCES accounts(id)
);
```

---

## 2.4 New Table: `invoices`

One invoice per month per user (NOT per account).

```sql
CREATE TABLE invoices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id VARCHAR(255) NOT NULL,
    period_month CHAR(7) NOT NULL,
    total_fee DECIMAL(12,2) NOT NULL,
    status ENUM('pending','paid','overdue') DEFAULT 'pending',
    generated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    due_date DATETIME NOT NULL,
    paid_at DATETIME NULL
);
```

---

## 2.5 New Table: `invoice_breakdown`

Shows account-by-account performance inside one invoice.

```sql
CREATE TABLE invoice_breakdown (
    id INT AUTO_INCREMENT PRIMARY KEY,
    invoice_id INT NOT NULL,
    account_id INT NOT NULL,
    realized_profit DECIMAL(12,2),
    unrealized_profit DECIMAL(12,2),
    fee_realized DECIMAL(12,2),
    fee_unrealized DECIMAL(12,2),
    total_fee DECIMAL(12,2),
    FOREIGN KEY (invoice_id) REFERENCES invoices(id),
    FOREIGN KEY (account_id) REFERENCES accounts(id)
);
```

---

# 3. HWM CALCULATION LOGIC

At the moment of monthly billing:

Let:

- `E = end of month equity (from Capital.com)`
- `H = previous hwm_equity`
- `D = total deposits this month`
- `W = total withdrawals this month`

### 3.1 Adjust Equity for deposits/withdrawals

```
adjusted_equity = E - (D - W)
```

### 3.2 New profit above HWM

```
delta_equity = adjusted_equity - H
```

If delta_equity ≤ 0 →  
**no fee**, HWM unchanged.

---

# 4. REALIZED / UNREALIZED SPLIT

Capital.com returns:

```
realized_pnl
unrealized_pnl
```

### New realized profit above HWM:

```
new_realized = realized_pnl – hwm_realized
```

### New unrealized profit above HWM:

```
new_unrealized = unrealized_pnl – hwm_unrealized
```

---

# 5. FEE CALCULATION

```
fee_realized   = new_realized * 0.20
fee_unrealized = new_unrealized * 0.30
total_fee_account = fee_realized + fee_unrealized
```

---

# 6. MONTHLY BILLING ALGORITHM (RUNS ON 1ST OF EVERY MONTH)

### Step 1 — Loop through all users  
### Step 2 — For each user, loop through all their accounts  
### Step 3 — Fetch:
- end-of-month equity  
- realized PNL  
- unrealized PNL  
- deposits  
- withdrawals  
- HWM values  

### Step 4 — Compute new profits  
### Step 5 — Compute fees  
### Step 6 — Create billing_period record  
### Step 7 — Sum all fees across accounts  
### Step 8 — Create invoice  
### Step 9 — Create invoice_breakdown rows per account  
### Step 10 — Update the HWM for each account (only if higher)

---

# 7. GRACE PERIOD LOGIC

On invoice creation:

```
due_date = generated_at + INTERVAL 7 DAY
```

If today > due_date AND invoice not paid:

```
status = 'overdue'
block_trading = true
```

React dashboard will show:

```
⚠ Your invoice is overdue. Trading is disabled.
```

---

# 8. UI / API EXPECTED OUTPUT (FOR REACT)

### `/api/invoices/{user_id}/{month}` returns:

```json
{
  "period": "2025-11",
  "status": "pending",
  "total_fee": 188.80,
  "due_date": "2025-12-08T00:00:00",
  "accounts": [
    {
      "account_id": 1,
      "realized_profit": 734.00,
      "unrealized_profit": 210.00,
      "fee_realized": 146.80,
      "fee_unrealized": 63.00,
      "total_fee": 209.80
    }
  ]
}
```

---

# 9. CAPITAL.COM SYNC REQUIREMENTS

A cron script must run every 5–15 minutes to:

- Pull balance, equity, realized pnl, unrealized pnl  
- Pull deposit/withdrawal history  
- Insert updates into:
  - `capital_transactions`
  - account balance columns
  - recalculated unrealized/realized PNL

---

# 10. BLOCKING TRADING ON OVERDUE INVOICE (PHP API GATEKEEPER)

Every trade execution request must check:

```
if user has overdue invoices → deny operation
```

---

# END — This architecture is now FINAL and production-ready.
