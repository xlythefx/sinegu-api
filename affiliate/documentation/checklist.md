# Affiliate implementation checklist

## Already done

- ✓ Invite codes exist. Users can create and share a referral code.
- ✓ On registration, if a referral code is used, the new user is linked to the referrer in referral tracking.
- ✓ New users get default fee percentages in user_credentials: realized 20, unrealized 6. These are the trading fee rates used for their invoices (HWM logic).

## Affiliate earning percentage (15% to 30%)

The affiliate program percentage is the one that can go from 15% up to 30%. That is the share of performance fees the affiliate earns from their directs (and from the single secondary level when applicable). It is not the same as the trading fee percentages (realized 20, unrealized 6) stored per user for invoice calculation.

- Base affiliate earning: 20%.
- Bonuses (education, community support, special) can add up to +10%.
- Penalties (inactivity, immediate) can subtract up to −5%.
- So the active affiliate percentage stays between 15% and 30%.

## Still to build (high level)

- ✗ Store and compute the affiliate earning percentage per user (base + bonuses − penalties).
- ✗ Only apply bonuses when the user has at least one direct.
- ✗ Track education completions, verified support, and special criteria for bonuses.
- ✗ Apply inactivity penalty when monthly tasks are not done.
- ✗ Apply immediate penalty for drama or ethics violations.
- ✗ Enforce caps (e.g. max 2 new members per month, max +0.5% growth per month from community support).
- ✗ Calculate and pay commission: affiliate percentage of directs fees (and secondary level) using existing HWM/invoice data.

---

## How to think about implementing the not-done items

### Order and dependencies

You cannot increase percentage from courses if courses do not exist. Same idea for everything: build the thing that produces the data first, then the rule that uses it.

**1. Data layer before rules**

- To apply bonuses when user has at least one direct: you already have referral_tracking, so you can derive has_directs (e.g. count rows where referrer_uni_id = user). No new feature needed, just use that in the formula.
- To add education bonus: you need courses (or a list of course IDs) and a way to record user X completed course Y. Only after that can you add +0.5% per course.
- To add community support bonus: you need a way to record support actions (who helped whom, verified by receiver) and possibly a monthly cap. Then you can add +0.5% per month.
- To add special bonus: you need clear criteria (e.g. all monthly tasks done, no penalties). That means monthly tasks and penalty tracking must exist first.
- To apply inactivity penalty: you need a definition of monthly tasks (e.g. 1 quiz OR 1 verified support OR 1 update) and a way to record completion. Then a monthly job can check: if nothing done, add 1 to penalty (max 5).

**2. Store the result, then compute it**

- Add a place to store affiliate percentage per user (e.g. a column or small table). Start with base 20% for everyone who has a referral code or is in referral_tracking.
- Then implement the formula: base + education_bonus + community_bonus + special_bonus − inactivity_penalty − immediate_penalty, with the rule that bonuses count only if has_directs. Run this when inputs change (course completed, support verified, penalty applied, etc.) and write the result into that store.

**3. Caps and limits before growth**

- Before you add +0.5% per month for community support, implement the cap: e.g. monthly_growth_counter and do not add more than 0.5% per month.
- Before you allow unlimited referrals, enforce max 2 new members per month: when inserting into referral_tracking, check how many this referrer already has this month and reject if >= 2.

**4. Commission last**

- Calculating and paying commission needs: (a) affiliate percentage per user, (b) who is whose direct, (c) fee data per direct (you have billing_periods / invoices). So do commission only after affiliate percentage is stored and computed. Then for each paid invoice, find the referrer, take their percentage of the fee, and record or pay it. Secondary level (20% of Elias earnings from sub-affiliate directs) needs a clear definition of Elias earnings and one more lookup (referrer of referrer).

### Architecture in one sentence

Define and record the raw events (course completed, support verified, referral added, task done, penalty applied), then compute affiliate percentage from those, then use that percentage and fee data to compute commission. Do not try to compute percentage before the events that feed it exist.
