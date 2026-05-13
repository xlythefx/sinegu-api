# Frontend connections: UserInvoicePayment (checkout) and AdminFlaskControl (auto-charge)

## Summary

**Both flows are correctly wired.** UserInvoicePayment calls `create-checkout-session.php` with the right payload; AdminFlaskControl calls `auto-charge-invoices.php`. No frontend bugs were found that would cause single-use PaymentMethods.

---

## 1. UserInvoicePayment (user pays)

**File:** `C:\Users\Xlythe\sinequal-dash-fusion-main\src\pages\user\UserInvoicePayment.tsx`

**Flow:**
1. **Load invoices:** `getUserInvoices(uniId)` from `@/hooks/api` → `GET ${API_BASE_URL}/users/get_invoices.php?uni_id=...`
2. **Backend** `users/get_invoices.php` returns `billing_period_id` ( = `billing_periods.id`).
3. **Frontend** maps `bp.billing_period_id || bp.invoice_id` to `BillingPeriod.id` (line ~240).
4. **On Pay:** `POST ${API_BASE_URL}/stripe/create-checkout-session.php` with body:
   - `billing_period_id: billingPeriod.id` (correct – from get_invoices)
   - `amount: totalFee` (from `billingPeriod.total_fee` / `bp.amount`)
   - `currency: billingPeriod.currency_type.toLowerCase()`
5. **Expects:** `data.sessionUrl` and redirects with `window.location.href = data.sessionUrl`.

**API_BASE_URL:** From `@/hooks/api` → `http://localhost/sinegu-api` (same as admin).

**Verdict:** Correct. Checkout session is created with the right `billing_period_id`; backend is responsible for using a Stripe Customer.

---

## 2. AdminFlaskControl (auto-charge button)

**File:** `C:\Users\Xlythe\sinequal-dash-fusion-main\src\pages\admin\AdminFlaskControl.tsx`

**Flow:**
1. **Import:** `autoChargeInvoices` from `@/hooks/admin/adminFlaskControl-api`.
2. **On Charge Invoices:** `handleAutoCharge()` calls `autoChargeInvoices(payload)` with optional `{ dry_run, limit, skip_demo }`.
3. **API** `adminFlaskControl-api.ts`: `POST ${API_BASE_URL}/stripe/auto-charge-invoices.php` with JSON body.
4. **Response:** `AutoChargeInvoicesResponse` with `results` (total_processed, charges_attempted, succeeded, failed, errors, details). Modal shows errors and details.

**API_BASE_URL:** From `@/hooks/api` (same as UserInvoicePayment) → `http://localhost/sinegu-api`.

**Verdict:** Correct. Auto-charge hits the right PHP endpoint.

---

## 3. API base

**File:** `C:\Users\Xlythe\sinequal-dash-fusion-main\src\hooks\api.ts`

- `export const API_BASE_URL = "http://localhost/sinegu-api";`
- UserInvoicePayment and adminFlaskControl-api both use this (adminFlaskControl-api imports from `../api`). So user checkout and admin auto-charge hit the **same backend**.

---

## 4. Why “done checkout many times” can still show single-use PM

1. **Backend must be the fixed one**  
   Ensure `c:\wamp64\www\sinegu-api\stripe\create-checkout-session.php` is the version that:
   - Requires a Stripe Customer before creating the session.
   - Does **not** fall back to `customer_email` when `$stripeCustomerId` is null.
   - Returns an error if Customer creation fails instead of creating a session without a customer.

2. **Existing row in DB**  
   The current `stripe_payment_methods` row (e.g. id 7, `pm_1SuZ0y...`) may still be the **old** PaymentMethod created before the fix (or from a checkout that hit an old/cached PHP). Stripe will not allow that PM to be reused. Only a **new** PM created by a checkout that uses the fixed script (with Customer) will work for auto-charge.

3. **Webhook overwrites**  
   Webhook deletes all existing PMs for the user and inserts the new one from `checkout.session.completed`. So after a **successful** checkout with the fixed backend, you should see one new row with a new `stripe_payment_method_id` and the Customer from that session. If you still see the same `pm_1SuZ0y...` after paying again, that suggests either:
   - The new checkout never completed (user closed before paying), or
   - The webhook didn’t run / failed, or
   - The backend that served that checkout was still the old one (e.g. different server or cached file).

**Recommendation:** After the next “Pay” from UserInvoicePayment, confirm in the DB that:
- There is a **new** `stripe_payment_method_id` (different from `pm_1SuZ0y...`).
- `stripe_customer_id` is set (e.g. `cus_...`).
- Then run auto-charge again; it should use that new PM.

No frontend code changes are required for these connections.

---

## 5. The three errors at the end of `stripe listen`

The messages:

`ERROR websocket.Client.writePump: Error when writing ping message: websocket: close sent`

are from the **Stripe CLI’s own WebSocket** (used to talk to Stripe), not from your `webhook.php`. They usually mean the CLI’s connection to Stripe dropped or was closed (network blip, long idle, etc.). Your logs show `[200] POST` for the events, so the webhook **did** receive and respond to the events. You can ignore these or restart `stripe listen` if you want a clean connection.

---

## 6. How to check if the payment method (PM) changed after “Pay”

Your webhook, on `checkout.session.completed`, keeps **one payment method per user**:
- **Same PM (same card / same `stripe_payment_method_id`):** updates the existing row in place (customer id, card details, `is_default`/`is_active`, `updated_at`). It does **not** delete.
- **New PM (different `stripe_payment_method_id`):** deletes all rows for that user, then inserts one new row. So after a “new card” payment you see one row with the new PM.

**Steps:**

1. **Before paying**  
   In `stripe_payment_methods`, for your test user (`uni_id`), note:
   - `id`
   - `stripe_payment_method_id` (e.g. `pm_1SuZ0y...`)
   - `stripe_customer_id` (e.g. `cus_...`)
   - `created_at`

2. **Pay an invoice**  
   User Invoice page → choose a pending invoice → Pay → complete Stripe Checkout (card, success).

3. **After the webhook runs**  
   Check `stripe_payment_methods` again for that `uni_id`:
   - You should have **one row** (either updated in place or replaced).
   - **Same card:** `stripe_payment_method_id` and `id` stay the same; `updated_at` changes.
   - **New card:** old row(s) deleted, one new row inserted; `stripe_payment_method_id` and usually `id` change.

**What is good vs bad**

- **Good:** After a new “Pay” and successful checkout, **`stripe_payment_method_id` is different** from what you had before.  
  That means Stripe created a **new** PaymentMethod for this checkout. With the fixed checkout (session created with a Customer), that new PM is attached to your Customer and **can be used for auto-charge**.

- **Bad (for your case):** After paying again, **`stripe_payment_method_id` is still the same** as before.  
  Then either:
  - The webhook didn’t run for `checkout.session.completed` (so the old row was never replaced), or
  - The same Stripe session/PM was reused in a way you didn’t expect.  
  If it never changes no matter how many times you pay, the new payment method is not being saved and auto-charge will keep using the old (possibly single-use) PM.

**Optional:** Check `log/invoice-debug.txt` (if your webhook writes there). After a payment you should see lines for `checkout.session.completed`, “Saving payment method”, “Deleted existing…”, “SUCCESS: Payment method saved…” and the new `stripe_payment_method_id` / `stripe_customer_id` being logged.
