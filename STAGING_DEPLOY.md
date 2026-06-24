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

## 2. Pre-deploy checks

- [ ] Target is **staging**, not production.
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
cd /var/www/jewelflow
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
npm run build                                   # builds the Vite asset bundle
```

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
# command=php /var/www/jewelflow/artisan queue:work --sleep=3 --tries=3 --max-time=3600
# user=www-data  autostart=true  autorestart=true  numprocs=1
```

Monitor `failed_jobs`: `php artisan queue:failed`, retry with `queue:retry all`.

## 12. Cron scheduler

One crontab line drives all 17 scheduled jobs (daily backup, `scan:cleanup`,
`schemes:process-maturity`, `cache:warm-shops`, `reporting:sweep-expired-exports`,
`mobile:prune-idempotency-keys`, `mobile:prune-uploads`, loyalty/subscription
checks, etc.):

```cron
* * * * * cd /var/www/jewelflow && sudo -u www-data php artisan schedule:run >> /dev/null 2>&1
```

## 13. Permissions / ownership

```bash
sudo chown -R www-data:www-data /var/www/jewelflow/storage /var/www/jewelflow/bootstrap/cache
sudo find /var/www/jewelflow/storage -type d -exec chmod 775 {} \;
sudo find /var/www/jewelflow/storage -type f -exec chmod 664 {} \;
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

- **Deploy failed (assets/app):** `git checkout <previous-tag>` → `composer install --no-dev -o` → `npm ci && npm run build` → re-cache (Section 8) → `queue:restart`.
- **Migration failed:** restore the Section-4 backup, then `migrate:rollback` the last batch if partially applied. Never edit ledger rows by hand.
- **Deploy ok but UI broken:** `php artisan optimize:clear`, rebuild assets; if still broken, revert the branch to the last known-good commit and redeploy.

---

## 16. Known non-blocking test failures

Full suite (`php artisan test`, testing DB): **1420 passed, 6 skipped, 11 failed**.
The 11 are pre-existing and unrelated to ERP demo flows — do **not** treat as deploy blockers:

- 6 × DhiranOnboardingTest (Dhiran is a separate product, out of scope here).
- 2 × BusinessIdentifierArchitecture (invoice-number format test expectation; a `#id` cosmetic on the dashboard).
- 2 × ProfileTest (account-deletion route returns 405).
- 1 × ServicesBuyNowTest (subscription callback).

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
