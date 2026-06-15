# Runbook — Subscription Fix + Multi-Product Deployment (Production)

**Covers:** deploying the Phase 1 subscription fix (`41f51c2`) and Phase 2 multi-product catalog (`7f1ad92`) to production, plus the `addCallback` hardening. Run **as `www-data`, never as root** (root-owned cache/log files cause 500s).

For the trial-term data repair detail, this runbook references the companion: **[subscription-trial-term-repair.md](subscription-trial-term-repair.md)**.

> This box is `APP_ENV=production` with a single `jewelflow` database and no separate staging environment. Treat every command as production-affecting. Do nothing destructive without a verified backup.

---

## 0. Pre-flight

```bash
cd /var/www/jewelflow
git log --oneline -5                  # confirm the deploy commit is what you expect
runuser -u www-data -- /usr/bin/php artisan --version
```

Confirm `.env` has the live Razorpay keys (`services.razorpay.key_id/key_secret/webhook_secret`). Confirm maintenance-window timing with the owner — migrations are fast (small tables) but the window protects the repair step.

---

## 1. Backup

```bash
# Full DB backup (replace <db> with DB_DATABASE from .env)
pg_dump <db> > /var/backups/jewelflow_predeploy_$(date +%F_%H%M).sql

# Targeted backup of the tables this deploy touches (fast restore path)
pg_dump -t plans -t shop_subscriptions -t subscription_events \
        -t shop_editions -t platform_products <db> \
        > /var/backups/jewelflow_subs_predeploy_$(date +%F_%H%M).sql
```

**Verify the backup is restorable** (non-empty, parses):
```bash
ls -lh /var/backups/jewelflow_predeploy_*.sql
tail -5 /var/backups/jewelflow_predeploy_*.sql   # should end with a clean dump footer, not a truncation
```
Record both backup paths in the change log.

---

## 2. Migration

This branch adds 6 migrations. They are ordered by timestamp and **must run in this order** (the platform_product_id FK depends on platform_products existing first):

| Order | Migration | Effect |
|---|---|---|
| 1 | `2026_08_13_010000_add_hero_style_to_catalog_website_settings` | additive column (catalog) |
| 2 | `2026_08_14_010000_strengthen_audit_hash_chain_coverage` | CREATE OR REPLACE audit hash trigger (additive) |
| 3 | `2026_08_15_010000_make_subscription_admin_id_nullable` | `shop_subscriptions.updated_by_admin_id` nullable + `actor_type` column |
| 4 | `2026_08_16_010000_create_platform_products_table` | new `platform_products` table + seeds 6 products |
| 5 | `2026_08_16_010100_add_platform_product_id_to_plans` | `plans.platform_product_id` FK + backfill from code prefix |
| 6 | `2026_08_16_010200_add_source_and_subscription_to_shop_editions` | `shop_editions.source` (backfill→admin_grant) + `product_subscription_id` FK + widen CHECK |

```bash
# Dry-run first — shows the SQL without executing
runuser -u www-data -- /usr/bin/php artisan migrate --pretend

# Execute
runuser -u www-data -- /usr/bin/php artisan migrate --force

# Confirm all 6 ran
runuser -u www-data -- /usr/bin/php artisan migrate:status | grep -E '2026_08_1[3-6]'
```

**Expected output:** each of the 6 shows `Ran`. Migration 4 also runs `PlatformProductSeeder` (via its own seed call) — confirm in §4 query 4 that 6 products exist.

**Rollback (migration only):**
```bash
# Rolls back the latest batch (the 6 above if they share a batch)
runuser -u www-data -- /usr/bin/php artisan migrate:rollback --step=1 --force
```
If rollback misbehaves, restore from the targeted backup:
```bash
psql <db> < /var/backups/jewelflow_subs_predeploy_<timestamp>.sql
```

**Clear caches AS www-data (never as root):**
```bash
runuser -u www-data -- /usr/bin/php artisan config:clear
runuser -u www-data -- /usr/bin/php artisan route:clear
runuser -u www-data -- /usr/bin/php artisan view:clear
# Re-warm only as www-data if your deploy normally caches; otherwise leave cleared.
```

---

## 3. Subscription repair

Follow **[subscription-trial-term-repair.md](subscription-trial-term-repair.md)** in full. In brief:

```bash
# Dry-run — review the affected list, writes nothing
runuser -u www-data -- /usr/bin/php artisan subscription:repair-trial-terms

# Commit — transactional, writes a subscription.repaired audit event per row
runuser -u www-data -- /usr/bin/php artisan subscription:repair-trial-terms --commit

# Re-run — must report "No affected subscriptions found"
runuser -u www-data -- /usr/bin/php artisan subscription:repair-trial-terms
```

**Expected affected customers:** any paid subscription created **before** this deploy whose `ends_at` sits in a trial-length window (yearly ≤ start+31d, monthly ≤ start+8d). On the dev/working `jewelflow` DB this was Goldlux (#5) and Shivshakti Jewellers (#6), already repaired during development. A clean production DB will show its own list — review it before `--commit`. Post-fix, **no new** subscription can be created with a short term, so a non-empty list here means pre-deploy rows only.

---

## 4. Verification queries

Run these (psql or `tinker`) after migration + repair. Each has an expected result.

```sql
-- 1. Active subscriptions by status (sanity: no paid sub stuck in a trial window)
SELECT status, COUNT(*) FROM shop_subscriptions GROUP BY status ORDER BY status;

-- 2. Repaired subscriptions — every repair left an audit event
SELECT se.id, se.shop_subscription_id, se.before->>'ends_at' AS old_ends,
       se.after->>'ends_at' AS new_ends, se.reason
FROM subscription_events se
WHERE se.event_type = 'subscription.repaired'
ORDER BY se.id DESC;

-- 3. No paid subscription remains in a trial-length window (MUST be zero rows)
SELECT ss.id, ss.shop_id, ss.billing_cycle, ss.starts_at, ss.ends_at
FROM shop_subscriptions ss
WHERE ss.razorpay_payment_id IS NOT NULL AND ss.price_paid > 0
  AND ( (ss.billing_cycle = 'yearly'  AND ss.ends_at <= ss.starts_at + INTERVAL '31 days')
     OR (ss.billing_cycle = 'monthly' AND ss.ends_at <= ss.starts_at + INTERVAL '8 days') );

-- 4. Platform products seeded (expect 6: retail/dhiran/manufacturing active; crm/analytics/mobile_premium inactive)
SELECT code, is_active, sort_order FROM platform_products ORDER BY sort_order;

-- 5. Every plan linked to a product (expect zero NULLs)
SELECT id, code, platform_product_id FROM plans WHERE platform_product_id IS NULL;

-- 6. Edition assignments: every row has a source (expect zero NULLs); existing rows backfilled to admin_grant
SELECT source, COUNT(*) FROM shop_editions WHERE deactivated_at IS NULL GROUP BY source;

-- 7. Subscription→edition links: subscription-sourced editions point at a real subscription
SELECT se.shop_id, se.edition, se.source, se.product_subscription_id, ss.status
FROM shop_editions se
LEFT JOIN shop_subscriptions ss ON ss.id = se.product_subscription_id
WHERE se.source = 'subscription' AND se.deactivated_at IS NULL;

-- 8. Renewal chains: multiple subscription rows per shop are expected (new-row-per-term), history intact
SELECT shop_id, COUNT(*) AS subscription_rows,
       COUNT(*) FILTER (WHERE status IN ('active','trial','grace')) AS writable_rows
FROM shop_subscriptions GROUP BY shop_id HAVING COUNT(*) > 1;

-- 9. Refund handling: cancelled subs have a refund event; partial refunds did NOT cancel
SELECT se.event_type, COUNT(*) FROM subscription_events se
WHERE se.event_type IN ('subscription.refunded','subscription.partial_refund') GROUP BY se.event_type;

-- 10. No edition row violates the CHECK (defensive — should never return rows)
SELECT DISTINCT edition FROM shop_editions
WHERE edition NOT IN ('retailer','manufacturer','dhiran','crm','analytics','mobile_premium');
```

---

## 5. Smoke tests (manual, post-deploy)

Run on a **test-mode Razorpay key** where payment is involved, or against a non-customer pilot shop. Each maps to a frozen behavior; tick all 13.

| # | Test | Expected |
|---|---|---|
| 1 | New **yearly** purchase | Subscription `active`, `ends_at = start + 1 year` (NOT 7 days), edition granted, `actor_type=self_service` |
| 2 | New **monthly** purchase | Subscription `active`, `ends_at = start + 1 month`, edition granted |
| 3 | **Trial** flow (if a trial offer is wired) | Trial length applies ONLY via the explicit trial path, never on a paid purchase |
| 4 | **Renewal** | A NEW subscription row is created; old row untouched; edition stays active |
| 5 | **Grace-period renewal** | New term anchors from the original `ends_at` (customer keeps paid days), not from payment date |
| 6 | **Partial refund** (refund < price_paid) | Subscription stays active; `subscription.partial_refund` event logged; no access lost |
| 7 | **Full refund** (refund ≈ price_paid) | Subscription `cancelled`; edition revoked **unless** an admin_grant/seed also backs it |
| 8 | **Retail-only** subscription | retail edition only; ERP writable; Dhiran routes gated |
| 9 | **Dhiran-only** subscription (no retail) | dhiran edition only; Dhiran writable WITHOUT a retail sub; retail routes gated |
| 10 | **Retail + Dhiran** | two separate subscription rows; both editions active; both writable |
| 11 | **Edition grant/revoke** | Subscription activation grants the matching edition; full lapse revokes only subscription-sourced editions; admin grants survive a lapse |
| 12 | **Write gate** | suspended/read_only shop hard-blocked; a shop with no entitled edition blocked; a shop entitled to one product writable on that product even if another lapsed |
| 13 | **Subscription expiry** (scheduler) | `subscription:check-expiry` moves active→grace at `ends_at`, grace→expired/read_only at `grace_ends_at`; one product's lapse does not suspend a shop still entitled to another product |

**Self-serve add-product checkout** (the `/settings/services` "Add service" → Razorpay path) is the one flow with no automated HTTP test (it needs live Razorpay). Smoke-test it explicitly in test mode: start an add, complete payment, confirm a new product subscription + edition appear and the M1/L1 guards behave (disabled product mid-flow → rejected at callback; already-owned edition → no duplicate sub).

---

## 6. Post-deploy monitoring (first 48h)

- Watch logs for `Service-add callback:` warnings (order-user mismatch, product-no-longer-active, plan-mismatch) — these are the new M1/L1 guards firing; investigate any that appear.
- Re-run verification query #3 daily — it must stay at zero rows. A non-zero row post-deploy indicates a regression in the paid-term logic, not a repair candidate.
- Confirm the scheduler (`subscription:check-expiry`) is running on its cron and not erroring.

---

## 7. Rollback (full deploy)

1. Revert the application code to the prior release (the deploy mechanism's standard rollback).
2. Roll back the 6 migrations (§2 rollback) **or** restore from the §1 full backup.
3. The trial-term repair is forward-only; its `subscription.repaired` events hold `before` snapshots if any subscription must be manually reverted (see the repair runbook's Rollback section). Rolling back the deploy does **not** require un-repairing — repaired customers simply keep the full term they paid for.
