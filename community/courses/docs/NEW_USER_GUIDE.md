# New User Guide — Getting Started

A simple, step-by-step guide for new users. Written for courses and onboarding. No technical jargon—just what you see and what to do next.

---

## Landing on the site

When you first visit the platform, you see the main landing page. This is where everything begins. The page introduces the product and gives you two clear options: **Register** if you’re new, or **Login** if you already have an account.

Take your time here. Read the short description of what the platform does—tracking your trading, commissions, and affiliate network—so you know what you’re signing up for.

---

## Registering your account

Click **Register** (or the sign-up link). You’ll be taken to the registration page.

Fill in the details you’re asked for: usually your name, email, and a password. If someone sent you an invitation or referral link, use that link to register—that way you’re automatically linked to the person who referred you.

After you submit the form, your account is created. Depending on how the platform is set up, you might be taken straight to login or see a message asking you to check your email. Follow the instructions on the screen. Once your account is ready, you use the same email and password to **log in**.

---

## Logging in

Click **Login** on the landing or register page. Enter the email and password you used when you registered.

After you log in, you leave the “public” part of the site and enter your own **dashboard**. This is your private area where you’ll manage your trading account, see your stats, and later your affiliate network.

---

## Your first time on the dashboard — connect your API

The first time you open your dashboard after logging in, you’ll see a message or popup asking you to **connect your API** (or connect your broker / trading account).

This step is important. The platform needs to link to your trading account so it can show your balance, positions, and performance. Until you connect, the dashboard can’t display your real data.

**Generating your API key**

To connect, you need an API key from your Capital.com account. Here’s how to get it:

1. Log in to your Capital.com account in your browser.
2. Go to **Settings** → **API integrations**.
3. Click **Generate API key** and follow the instructions (you may be asked to confirm with two-factor authentication).
4. Once you have the API key, copy it and paste it into the platform where it asks for your broker credentials. That’s how the platform authenticates with your trading account.

After you paste your API key and confirm, the platform will link to your account. The dashboard will then start showing your balance, P&L, and other metrics.

---

## What happens next

After you’ve entered your API key, the system will connect to your trading account automatically. This usually takes **20–40 seconds**. Once it’s done, you’re connected and **trading can run automatically** through the platform.

You can leave the platform for now. Your statistics will keep building in the background. When you come back, you’ll see your balance, P&L, and activity updated.

---

## Understanding your Trading Dashboard

Once you’re connected and have been trading for a while, your **Trading Dashboard** becomes the main place you check your account. Here’s what each part means and how it fits together.

### Your balance

At the top of the dashboard you see your **balance**—the current value of your trading account (for example, in euros or dollars). This is the same number you’d see in your broker app: it’s your equity right now. You can hide or show it with the eye icon, and use the refresh icon to pull the latest value from your broker. The chart icon takes you to in-depth analytics if you want to dig deeper.

### Total profit & loss (unrealized + realized)

Just below the balance, the dashboard shows your **Total Profit & Loss**. This is the combined result of:

- **Unrealized Profit & Loss** — Profit or loss on positions you still have open. The number changes as prices move. When you close those trades, this amount moves into “realized.”
- **Realized Profit & Loss** — Profit or loss from trades you’ve already closed. This part is fixed once the trade is closed.

So **Total P&L = Unrealized + Realized**. You’ll see both the amount and a percentage (based on your balance). Green usually means profit; red means loss. This gives you one clear view of how your account is performing overall.

### Commission (this month)

Next to your P&L you’ll see **Commission (this month)**. This is the fee you owe the platform for the current month—typically a **percentage of your profit** (for example, 20% of your realized profit for that month). The exact percentage is set in your account. If you haven’t made profit this month, or if special conditions aren’t met (see below), this can show zero.

### Estimated Commissions (expandable)

If you click or tap **Estimated Commissions**, the panel expands and shows more detail:

- **Estimated Invoice Amount for [Month]** — For the current month it breaks down:
  - **Realized Commission** — e.g. “20% of €627.78” — the fee on profits from closed trades this month.
  - **Unrealized Commission** — e.g. “6% of €69.36” — the fee on your current open-position profit (if any).
  - **Total Commission** — What you’d owe for the month from those two. Sometimes you’ll see **“Balance is below HWM”** or **“Total P&L is negative”** here: in those cases the total commission is zero, because the platform only charges when your balance is above your High-Water Mark and you have positive profit.

- **All Time Commission** — The same idea but for your entire history: realized commission (e.g. 20% of all-time realized profit), unrealized commission, and total. Again, if balance is below HWM or total P&L is negative, the total is zero.

So in short: **commission is a percentage of your profit** (often around 20% on realized profit). You only pay when you’re in profit and above your High-Water Mark.

### Capital Flow Summary

Further down, **Capital Flow Summary** shows how money has moved in and out of your account:

- **Total Deposits** — Everything you’ve deposited (with the number of transactions).
- **Total Withdrawals** — Everything you’ve withdrawn.
- **Net Flow** — Deposits minus withdrawals. So you see at a glance whether more money has come in or gone out.
- **Capital** — Your starting point for the period: often your initial deposit plus net flow (or similar, depending on how the platform labels it). This helps you see how much of your current balance is “new” money vs. growth from trading.

Together, these numbers tell the story of how you’ve funded the account and how that relates to your balance and P&L.

### Your High-Water Mark (HWM)

On the right (or below on smaller screens) you’ll see **Your High-Water Mark**. There’s a small “?” or info icon that explains it in one line: **your HWM is the peak value your account has reached after fees, and the platform only charges on profits above this level—so you never pay twice on the same gain.**

You’ll typically see:

- **Previous mark** — The HWM from the last period.
- **Current high-water mark** — The peak for the current period. The text often says something like “This is your reference for the next period.”

When your **balance is below** this mark, your **Total Commission** in the Estimated Commissions section stays zero. Once your balance goes above the HWM and you have profit, commission is calculated on that profit (e.g. 20% of it). So the dashboard is designed to make it clear when you’re in a “fee-paying” zone and when you’re not.

---

## Putting it together

On the Trading Dashboard you can see, in one place:

- **Balance** — What your account is worth right now.
- **Total profit & loss** — Unrealized (open positions) + realized (closed trades).
- **Commission** — A percentage of your profit (e.g. 20% of realized profit for the month), only when your balance is above your High-Water Mark and you have positive P&L.
- **Capital flow** — How much you’ve deposited and withdrawn.
- **High-Water Mark** — The reference level above which profits are subject to commission.

*In the next part of the guide, we’ll walk through the other user pages (Affiliate Dashboard, Broker Accounts, Analytics, Billing & Invoices, etc.) once you’re comfortable with the main dashboard.*

---

*This guide focuses on the user journey from landing page to first dashboard use and understanding the Trading Dashboard. For detailed steps inside each screen, use the in-app hints or the course materials that accompany this guide.*
