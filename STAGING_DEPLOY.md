# JewelFlow — Staging / Pilot Deployment Runbook

> Practical deploy guide for the JewelFlow ERP on a Linux VPS (PHP 8.2 /
> Laravel 12 / PostgreSQL / php-fpm running as `www-data` / Nginx).
> **Staging / pilot only.** Never run the production-unsafe steps against a live
> shop database. No secrets are stored in this file.

---

## 1. Purpose

Bring up a JewelFlow instance for a client pilot/demo on a staging server:
correct environment, assets built, schema migrated, caches warm, queue +
scheduler running, and a clean demo shop to log into.

---

## ⚠ Before anything — pick the target

Every command in this runbook uses `$APP_DIR`. Set it once per shell, and let the
guard refuse to continue if you are pointed at the live install.

```bash
export APP_DIR=/var/www/jewelflow-staging       # staging. Production is /var/www/jewelflow
cd "$APP_DIR" || { echo "no such install: $APP_DIR"; exit 1; }

# Hard stop: never run this runbook against a production .env.
grep -qE '^APP_ENV=(production|prod)$' "$APP_DIR/.env" \
  && { echo "REFUSING — $APP_DIR is PRODUCTION. This runbook is staging-only."; exit 1; }
echo "target OK: $APP_DIR ($(grep '^APP_ENV=' "$APP_DIR/.env"))"
```

> This section exists because the runbook used to hardcode `/var/www/jewelflow`
> in every command — the **production** directory — while telling the reader it
> was staging-only. Copy-pasting the guide deployed to the live shop.

---

## 2. Pre-deploy checks

- [ ] The target guard above ran and printed `target OK` with a non-production `APP_ENV`.
- [ ] Working tree clean; deploying a known commit (`git rev-parse HEAD`).
- [ ] A database backup exists (Section 4) before any `migrate`.
- [ ] `.env` prepared with the values in Section 3 (no local-dev defaults).
- [ ] Disk space + PHP extensions (pdo_pgsql, mbstring, gd/imagick, zip, intl).

---

## 3. Required env values

`.env.example` ships **local-dev defaults** — override every one of these on staging:

| Key | Local default | Staging requirement |
|---|---|---|
| `APP_ENV` | `local` | `staging` |
| `APP_DEBUG` | `true` | **`false`** (true leaks stack traces) |
| `APP_KEY` | empty | run `php artisan key:generate` |
| `APP_URL` | – | real staging URL (cookies / signed export links depend on it) |
| `DB_CONNECTION/HOST/...` | – | staging PostgreSQL |
| `SESSION_DRIVER` | `file` | `file` ok single-server; `redis` preferred |
| `CACHE_STORE` | `file` | `file` ok; `redis` preferred |
| `QUEUE_CONNECTION` | `sync` | `sync` ok for a small pilot; `redis`/`database` + worker if exports/backups should not block requests |
| `FILESYSTEM_DISK` | `local` | `local` (private) — keep KYC/exports off the public disk |
| `MAIL_*` | – | real SMTP (or `log` for demo) |
| `REDIS_*` | – | set if using redis for cache/queue/session |

Timezone is `Asia/Kolkata` (config/app.php). GST default 3% per shop.

---

## 4. Backup step (before anything that writes schema/data)

```bash
# App uses spatie/laravel-backup (backup:run is scheduled daily).
sudo -u www-data php artisan backup:run        # DB + files archive to the backup disk
sudo -u www-data php artisan backup:list        # confirm the archive exists
```

Keep the archive off-server (download or sync to object storage) before migrating.

---

## 5. Code pull / build

```bash
cd "$APP_DIR"
git fetch --all
git checkout <release-commit-or-tag>            # immutable history; deploy a pinned ref
```

## 6. Composer install

```bash
composer install --no-dev --optimize-autoloader
```

## 7. NPM build

```bash
npm ci
npm run build:verify                            # builds the bundle, then fails if it is stale
```

> `build:verify` is `vite build && php artisan assets:verify-fresh`. The verify
> step compares the oldest compiled asset in `public/build` against the newest
> file in `resources/{css,js,views}` and the build configs, and exits non-zero if
> a source file is newer — the case where Vite silently produced nothing and the
> old bundle got shipped over new Blade. Plain `npm run build` cannot fail that
> way, which is why every "deploy ok but UI broken" ticket started here.

## 8. Laravel cache commands — **run as `www-data`**

> php-fpm runs as `www-data`. Caches written by `root` are root-owned and cause
> `Permission denied` 500s. Always `sudo -u www-data`.

```bash
sudo -u www-data php artisan config:cache
sudo -u www-data php artisan route:cache
sudo -u www-data php artisan view:cache
```

To clear instead (safe, no ownership trap): `php artisan optimize:clear`.

## 9. Storage link

```bash
sudo -u www-data php artisan storage:link       # public disk → public/storage
```

## 10. Migration — ⚠ approval required

```bash
# Review first, never blind-run on a populated DB:
sudo -u www-data php artisan migrate --pretend   # dry-run, prints SQL
# After backup (Section 4) + human approval:
sudo -u www-data php artisan migrate --force
```

> **Never** run `migrate:fresh`, `db:wipe`, `DROP`, `TRUNCATE`, or
> `DELETE FROM <core table>` against any shared/production DB. The schema has
> constitutionally-protected DB triggers and append-only ledgers — back up first,
> migrate forward only.

## 11. Queue worker

```bash
# Only needed if QUEUE_CONNECTION != sync.
sudo -u www-data php artisan queue:restart       # graceful reload after deploy
# Supervisor program (example): one worker is plenty for a pilot
# command=php /var/www/jewelflow-staging/artisan queue:work --sleep=3 --tries=3 --max-time=3600
# user=www-data  autostart=true  autorestart=true  numprocs=1
# Supervisor/systemd files cannot expand $APP_DIR — write the absolute staging
# path. A committed systemd unit already exists: deploy/staging/*.service
```

Monitor `failed_jobs`: `php artisan queue:failed`, retry with `queue:retry all`.

## 12. Cron scheduler

One crontab line drives all 17 scheduled jobs (daily backup, `scan:cleanup`,
`schemes:process-maturity`, `cache:warm-shops`, `reporting:sweep-expired-exports`,
`mobile:prune-idempotency-keys`, `mobile:prune-uploads`, loyalty/subscription
checks, etc.):

```cron
* * * * * cd /var/www/jewelflow-staging && sudo -u www-data php artisan schedule:run >> /dev/null 2>&1
```

> crontab does not expand `$APP_DIR` — the absolute staging path is deliberate.
> Double-check it before installing; this line runs every minute forever.

## 13. Permissions / ownership

```bash
sudo chown -R www-data:www-data "$APP_DIR/storage" "$APP_DIR/bootstrap/cache"
sudo find "$APP_DIR/storage" -type d -exec chmod 775 {} \;
sudo find "$APP_DIR/storage" -type f -exec chmod 664 {} \;
```

## 14. Demo data (pilot shop)

```bash
# System data first (idempotent): permissions, roles, plans, products.
sudo -u www-data php artisan db:seed --class=PermissionSeeder
sudo -u www-data php artisan db:seed --class=RolesAndPermissionsSeeder
# Curated demo shop (safe, idempotent, refuses production unless PILOT_DEMO_ALLOW_PROD=true):
sudo -u www-data php artisan db:seed --class=PilotDemoSeeder
```

**Demo logins** (mobile / password):
`9000000111 / password` (owner), `9000000112 / password` (manager),
`9000000113 / password` (cashier). Change before any non-demo use.

The seeder creates: demo shop, 3 users + roles, retailer edition, payment
methods, today's gold/silver rates, vendor, karigar, a vault metal lot, ~12
jewellery items (Rings deliberately below the reorder threshold → live reorder
alert), customers, cash-book entries, and pending + ready repairs (a *delivered*
repair needs a linked invoice — mark one delivered live via the Bill flow).

### Live demo script (accounting flows — create through the UI)

These are intentionally **not** seeded (they must go through the service layer +
accounting triggers). Walk them live on the demo shop — this is also the demo:

1. POS sale → invoice (Sales Counter): sell a couple of the demo items.
2. Record a payment / split tender → see Cash Book update.
3. Process a return on one invoice → credit note + refund.
4. Buyback / old-gold purchase → new metal lot + cash out.
5. Karigar job order: issue metal → receive items.
6. Scheme enroll + an EMI/installment payment.
7. Stock purchase inward from the demo vendor.
8. Reports/Dashboard then reflect all of the above.

---

## 15. Rollback

- **Deploy failed (assets/app):** `git checkout <previous-tag>` → `composer install --no-dev -o` → `npm ci && npm run build:verify` → re-cache (Section 8) → `queue:restart`.
- **Migration failed:** restore the Section-4 backup, then `migrate:rollback` the last batch if partially applied. Never edit ledger rows by hand.
- **Deploy ok but UI broken:** `php artisan optimize:clear`, rebuild assets; if still broken, revert the branch to the last known-good commit and redeploy.

---

## 16. Test suite — green is the bar

```bash
php artisan test        # or ./vendor/bin/phpunit for exact per-test output
```

**There is no allowlist of acceptable failures. A red suite blocks the deploy.**

Latest full run: **2297 tests, 2290 passed, 7 skipped, 0 failed** (11,809
assertions, ~3.5 min). The skips are environment guards (`skipIfNotPostgres`
and friends), not failures. `php artisan test` and `./vendor/bin/phpunit` agree
on all four numbers — if they ever stop agreeing, see below.

> A previous revision of this section claimed artisan "counts one fewer passing
> test than phpunit — an artisan-side counting quirk". That was wrong. Two
> datasets in HistoricalNormalizersTest had collapsed onto the same PHPUnit
> identity (a provider named its datasets after their own values, and PHP casts
> `'-500'` used as an array key to `int(-500)`), so the two runners rendered one
> collision differently and their totals split. Runners disagreeing on a count
> is a real signal — a genuinely dropped test looks identical from the summary
> line. Investigate it; do not write it off as a quirk, as this file did.

Run it as `./vendor/bin/phpunit --display-phpunit-deprecations` at least once per
release: PHPUnit 11 only *warns* about docblock metadata (`@dataProvider`) that
PHPUnit 12 removes outright, so those turn into hard errors on the next upgrade
while the suite still looks green today. Currently zero.

> This section used to list 11 "known non-blocking failures" — DhiranOnboardingTest,
> BusinessIdentifierArchitecture, ProfileTest, ServicesBuyNowTest — and told the
> operator to deploy over them. All 11 have since been fixed (verified: those four
> classes are 46/46 green), but the list stayed, so the runbook was training whoever
> ran it to wave off red tests. A standing "ignore these failures" list is a place for
> real regressions to hide: the next genuine break in one of those classes would have
> been read as expected. Do not re-add one. If a test legitimately cannot pass in an
> environment, mark it skipped in code with the reason, where the suite can see it —
> not in prose here, where it silently outlives the problem.

> The count above is a smoke signal for "did the suite actually run", not a target to
> match — it moves with every commit. The rule that does not move is: zero failures.

---

## 17. Post-deploy verification (smoke)

After deploy, log in as the demo owner and confirm each loads without error:

Login → Dashboard → Sales Counter → Customers → Jewellery Stock → Invoices →
Returns → Metal Vault → Karigars/Job Orders → Repairs → Schemes/EMI → Cash Book
→ Reports (incl. **Reorder Alerts** shows the seeded shortage, **Suspicious /
Unusual Activity** loads) → Public catalog → Mobile API auth (`/api/mobile/v1`
returns 401 without a token, 200 with one).

```bash
# Quick server-side check (guest should be redirected to /login, never 500):
curl -sk -o /dev/null -w "%{http_code}\n" https://<staging-host>/dashboard   # expect 302 → /login
```

---

## 18. Do not touch Dhiran

Dhiran (gold loans) is a separate product on the `dhiran.*` subdomain with its
own layout, nav, and scheduled jobs (`dhiran:accrue-interest`,
`dhiran:overdue-reminders`, `dhiran:forfeiture-check`). For an ERP pilot, do not
enable, seed, test, or modify Dhiran. PilotDemoSeeder creates no Dhiran data.

---

## 19. Production safety warnings

- `APP_DEBUG=false` in any shared environment — always.
- Back up (Section 4) before every `migrate`; migrate forward only.
- Banned on any populated DB: `migrate:fresh`, `db:wipe`, `DROP`, `TRUNCATE`, `DELETE FROM <core table>`.
- Run cache/storage/migrate as `www-data`, never `root` (ownership → 500s).
- Keep KYC and export files on the **private** disk; they are served through gated controllers, never public URLs.
- `PilotDemoSeeder` refuses to run on `APP_ENV=production` unless `PILOT_DEMO_ALLOW_PROD=true` — keep that unset in production.
