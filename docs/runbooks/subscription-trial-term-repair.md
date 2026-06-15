# Runbook — Repair Trial-Term Subscriptions (Production)

**Purpose:** Fix paid subscriptions that were granted only a trial-length term (e.g. a yearly purchase that expired in 7 days) because of the pre-fix trial bug. Run this **after** the Phase 1 subscription fix (commit `41f51c2`) is deployed to production.

**Owner of this action:** platform operator / engineer with production DB access.
**Reversibility:** the repair is forward-only, but every change writes a `subscription_events` row of type `subscription.repaired` with full `before`/`after`, so any change can be reconstructed and, if ever needed, manually compensated. **There is no silent write.**

---

## Background — what the bug did

A paid purchase used to read `plans.trial_days` and collapse a yearly purchase into a 7-day window with status `trial`, then age into `grace` and toward expiry. The fix (commit `41f51c2`) makes the term a pure function of `billing_cycle` and never reads `trial_days` on a paid purchase. This runbook repairs subscriptions **created before** that fix.

Affected pattern: a row with `razorpay_payment_id IS NOT NULL` and `price_paid > 0` (a real payment) whose `ends_at` falls within a trial-length window of `starts_at`:
- yearly: `ends_at <= starts_at + 31 days`
- monthly: `ends_at <= starts_at + 8 days`

On the dev/working DB this was Goldlux and Shivshakti Jewellers (both `retailer_yearly`, ₹19,999, 7-day windows). Production may have more.

---

## Pre-flight checklist

Run **as the application user, never as root** (php-fpm is `www-data`; root-owned cache/log files cause 500s):

```bash
cd /var/www/jewelflow
```

1. **Confirm the fix is deployed.** The repaired terms depend on the corrected logic being present.
   ```bash
   runuser -u www-data -- /usr/bin/php artisan migrate:status | grep make_subscription_admin_id_nullable
   git log --oneline | grep 41f51c2   # the Phase 1 fix commit must be in the deployed history
   ```
2. **Confirm the command exists.**
   ```bash
   runuser -u www-data -- /usr/bin/php artisan list | grep subscription:repair-trial-terms
   ```
3. **Back up the table** (cheap insurance — the table is small):
   ```bash
   # Replace <db> with the production database name from .env (DB_DATABASE)
   pg_dump -t shop_subscriptions -t subscription_events <db> > /var/backups/subs_before_repair_$(date +%F_%H%M).sql
   ```
   Store the backup path; note it in the change log.

---

## Step 1 — Dry run (read-only, writes nothing)

```bash
runuser -u www-data -- /usr/bin/php artisan subscription:repair-trial-terms
```

This prints a table of every affected subscription with its **current** and **corrected** `ends_at` and status. It writes **nothing**.

**Review the output before proceeding.** Sanity checks:
- Every listed row should have `price_paid > 0` and a `razorpay_payment_id` (these are genuine paid purchases).
- The "corrected ends_at" should be `starts_at + 1 year` (yearly) or `+ 1 month` (monthly).
- If a row looks wrong (e.g. a genuine free trial, or a test row), **stop** and investigate before committing — do not blindly commit.

If the output is **"No affected subscriptions found"**, there is nothing to repair; stop here.

---

## Step 2 — Commit the repair (writes, audited, transactional)

Only after the dry run is reviewed and looks correct:

```bash
runuser -u www-data -- /usr/bin/php artisan subscription:repair-trial-terms --commit
```

What it does per affected subscription, **inside a DB transaction**:
- Recomputes `ends_at` from `billing_cycle` and `grace_ends_at` from `plan.grace_days`.
- Recomputes `status` against "now" (active if the corrected term is still in the future; otherwise the same grace/expired logic the scheduler uses).
- Writes a `subscription_events` row, `event_type = 'subscription.repaired'`, with full `before`/`after` and reason `"Yearly-trial bug correction (Phase 1 data repair)"`.
- If the shop had been wrongly suspended/read-only because of the short term and the corrected status is `active`, restores `shops.access_mode = 'active'` and `is_active = true`.

It prints `Repaired N of N affected subscription(s).`

---

## Step 3 — Verify

1. **Re-run the dry run — it must now find zero:**
   ```bash
   runuser -u www-data -- /usr/bin/php artisan subscription:repair-trial-terms
   # expect: "No affected subscriptions found. Nothing to repair."
   ```
2. **Spot-check the repaired rows and their audit events** (adjust the IDs to those listed in Step 1):
   ```bash
   runuser -u www-data -- /usr/bin/php artisan tinker --execute='
     foreach (App\Models\Platform\ShopSubscription::whereIn("id", [/* affected ids */])->get() as $s) {
       echo "Sub #{$s->id} shop {$s->shop_id}: {$s->status} ends_at={$s->ends_at->toDateString()} grace={$s->grace_ends_at->toDateString()}".PHP_EOL;
     }
     foreach (App\Models\Platform\SubscriptionEvent::where("event_type","subscription.repaired")->latest("id")->take(20)->get() as $e) {
       echo "event #{$e->id} sub {$e->shop_subscription_id}: ".($e->before["ends_at"] ?? "?")." -> ".($e->after["ends_at"] ?? "?")." | {$e->reason}".PHP_EOL;
     }
   '
   ```
   Confirm each repaired subscription is `active` (or correctly grace/expired if its genuine corrected term is already past) with a full-term `ends_at`, and that a matching `subscription.repaired` event exists.
3. **Confirm affected shops can transact** — pick one repaired shop and confirm it is not blocked (its `access_mode` is `active`).
4. **(Phase 2 only)** If Phase 2 (commit `7f1ad92`, edition wiring) is also deployed, confirm the repaired subscription's edition is still active for the shop:
   ```bash
   runuser -u www-data -- /usr/bin/php artisan tinker --execute='
     $shop = App\Models\Shop::find(/* a repaired shop id */);
     echo implode(",", $shop->editionList()).PHP_EOL;
   '
   ```

---

## Rollback

The command is forward-only by design (it does not auto-undo). If a repair must be reversed:
1. The `subscription.repaired` event holds the exact `before` values — use them to identify the pre-repair `ends_at`/`grace_ends_at`/`status`.
2. Restore from the `pg_dump` taken in pre-flight, **or** apply a manual compensating update referencing the `before` snapshot, and write a new `subscription_events` row documenting the reversal (never a silent UPDATE).
3. There is no scenario where rollback is needed for correctness — the repair only extends paid customers to the term they actually purchased. Rollback would only be to undo a mis-detected non-paid row, which the Step 1 review is designed to catch first.

---

## Notes

- The command is **idempotent**: once a subscription is repaired, the detection query no longer matches it, so re-running `--commit` is safe and a no-op.
- This box runs `APP_ENV=production` against the `jewelflow` database. The same `jewelflow` DB was already repaired during development for Goldlux (#5) and Shivshakti (#6); a fresh production deploy starting from a clean DB will need this runbook run once after the first real customers are affected.
- Schedule: run once after the Phase 1 fix deploys, then again only if monitoring surfaces a new short-term paid subscription (which should be impossible post-fix — if one appears, it indicates a regression, not a repair candidate).
