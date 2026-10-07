# Takeover release on production — procedure

Script: `docs/runbooks/deploy-takeover-production.sh`. Check script:
`tests/Production/verify_takeover_production.php`. Recovery rule:
`docs/runbooks/product-promotion-recognition.md`, "Recovery".

**Nothing here is approved by being written down.** `release` refuses without
`TAKEOVER_RELEASE_APPROVED=<target commit>`; the synthetic check runs only with
`TAKEOVER_PRODUCTION_CHECK=approved`.

## 1. What is released

The target commit must descend from the reviewed candidate `5cbad19…`, and
every runtime path (`app`, `bootstrap`, `config`, `database`, `resources`,
`routes`, `public`, `artisan`, the dependency and build manifests) must be
identical both to that commit and to what staging is running. The script
checks this itself; anything else in the target is documentation, runbooks and
`tests/`. The built assets are the tarball staging received, pinned by SHA-256.

## 2. One window for three things

Retail (`jewelflows.com`, `www.jewelflows.com`), Dhiran
(`dhiran.jewelflows.com`) and the mobile API are one application, one database
and one php-fpm pool. `artisan down` answers 503 on all of them at once.

- **Sessions** are rows in the database and are not touched: owners and staff
  stay signed in. A request made during the window gets 503 with
  `Retry-After: 30`; the mobile app sees the same.
- **Queue worker** `jewelflow-production-ops-alerts` (queue `ops-alerts`) is
  stopped for the window and started again. Alerts raised meanwhile wait in the
  `jobs` table.
- **Scheduler** (`/etc/cron.d/jewelflow-scheduler`, every minute) is moved
  aside, any scheduled command already running is waited for (10 minutes at
  most, then the window is abandoned and everything put back), and the file is
  put back byte for byte. The scheduler does not catch up on what it missed.
  The ten- and fifteen-minute jobs (payment reconciliation, cache warm-up,
  alert evaluation) simply run at their next slot. The daily jobs (backup,
  interest accrual, reminders, expiry, forfeiture, pruning: 00:00 to 03:00 and
  06:00 India time) must not be skipped, so the script refuses to start
  between 23:40 and 03:10 and between 05:40 and 06:10 India time.
- **php8.2-fpm and nginx** are shared with staging and are not reloaded. Before
  leaving maintenance the script proves the new code is being served, through
  the maintenance bypass, on all three hosts.

## 3. What is left exactly as it is

The script compares before and after, and stops if any of these moved:
every `.env` line (the only change is two lines appended:
`DHIRAN_REGISTER_URL=https://dhiran.jewelflows.com/register` and
`ERP_REGISTER_URL=https://jewelflows.com/register`); every other configuration
value in effect (hashed per section); ownership and mode of `.env`, the config
cache and the assets; the untracked files in the tree; the scheduler file;
nginx's site file, including its five private-storage refusals (`/storage/kyc/`,
`signatures/`, `karigar-invoices/`, `purchases/`, `repairs/`), each probed for
403 on both products before, during and after; staging's commit, state and
configuration; every row of every existing table (count and content hash) and
the full schema text outside the fifteen relations the migration adds.

The explicit addresses are needed because production also answers on `www`:
derived from that host, the Dhiran address would be `dhiran.www.jewelflows.com`.

## 4. Steps

1. Put the bundle of the target commit and the assets tarball in
   `/root/takeover-production/incoming/` with a copy of the script.
2. `deploy-takeover-production.sh preflight <deployed> <target> <bundle> <assets> <sha256>`.
   It changes nothing in the application, its database, repository,
   configuration or services: the bundle is unpacked into a throwaway
   repository, a dump is taken, restored in isolation, compared and deleted.
3. In the approved window, the same arguments with `release`.
   Order: quiesce → fresh dump, restored in isolation and compared → checkout
   and autoload as `dev` → assets → the two `.env` lines and the config cache →
   the one migration → caches → proof of fresh code on three hosts →
   (synthetic check, if approved) → scheduler and worker back → `up` → smoke.
4. A gate that fails before the checkout puts everything back by itself. A gate
   that fails after it leaves production in maintenance and says where the
   evidence is. See 6.

## 5. The synthetic check inside the window (needs its own yes)

It creates three dedicated test shops (two Retail, one Dhiran), their default
roles and one owner each, and exercises only promotion metadata: the two
addresses, preferences, the recognition flow across both products,
suppression, isolation between shops, and invalidation on a password change.
No invoice, payment, loan, stock, customer, subscription or platform-admin row;
no existing shop, user or customer.

Everything runs in one transaction that is rolled back. **A rollback does not
undo everything.** Measured twice: on the disposable local database
(PostgreSQL 16), and on **PostgreSQL 14.24 in production mode** in an isolated
instance on the server (PHP 8.2.30, the `--no-dev` vendor directory, cached
configuration, routes and views, maintenance on, no network, nothing of
production visible), with committed synthetic shops and promotion rows already
present. 18 checks passed in both.

| | |
|---|---|
| Rolled back | Every row written (all 148 tables compared by row count and content hash before and after, from inside the script and again from outside it: identical). The row lock on the shop-code counter and the advisory owner locks, held until the rollback. |
| **Stays** | Sequence values: `shops` +3, `users` +3, `roles` +9, `role_permission` +384, `shop_editions` +3, `product_promotion_preferences` +10, `product_promotion_exposures` +2. The next real shop and user get ids that skip these. Shop codes do not skip (their counter is a table row). Sequences are **not** reset. Dead row versions and WAL of the rolled-back writes, until vacuum. |
| **Files** | Four files under `storage/framework/views`: two templates the framework generates for components and their compiled forms, which the view cache does not hold and php-fpm writes identically the first time such a page is served. A log line, if anything is logged (none was). The script lists every file that changed under `storage` and `bootstrap/cache`. |
| Not the check's own | Every boot of the application, by any artisan command or page view, refreshes its five-minute cache of the platform's mail settings in the cache directory. The check boots the application once, like each artisan command of the release does. |
| Prevented | Queue pushes, notifications, mail and HTTP calls made through the framework are captured in memory and counted (0). Sessions, the cache and the rate limiter are in memory. |
| Checked | One database connection only; the same transaction id from first write to rollback (nothing committed underneath); only the ten expected tables written. |
| Not exercised | Anything deferred until after a commit. Anything a browser does. |

The production-mode rehearsal found three defects that staging and the local
rehearsal could not, all corrected before this version: the script could not
boot in production mode; such a crash exited 0, which the release script would
have recorded as a pass; and the rate limiter, built during boot, kept its
throttle counters in the cache directory. A crash now exits 3, and the release
script also requires the final PASSED line, no FAIL line and eighteen PASS lines.

The script prints these measurements for the run itself, refuses to pass if a
table outside its list was written, and the release script fingerprints every
table again from outside the PHP process afterwards.

## 6. Recovery

Decided by a count of the four new tables, taken in maintenance.

- **A gate fails before the checkout.** The script has already put production
  back. Nothing to recover.
- **A gate fails after the checkout; the four tables are missing or empty**
  (the site has not left maintenance, so they cannot hold anything).
  Preferred: fix what the gate found and finish forward with `resume`.
  Otherwise return to the baseline, as `dev`, from the release's evidence
  directory: check out the recorded commit, `composer install --no-dev`,
  unpack `build.before.tar.gz` over `public/build`, copy `env.before` back over
  `.env` (same owner and mode), then as `www-data` `config:cache`,
  `route:cache`, `view:clear`, `view:cache`; confirm through the bypass that
  the earlier landing page is served; put the scheduler file back, start the
  worker, `up`. Empty tables are left in place; they are never dropped.
- **Any row exists in the four tables** (production has been up). Forward
  repair only. The earlier release must not run over recorded preferences or
  consent; no compatibility rollback exists.
- **Data is damaged and the site never left maintenance.** Full restore of the
  window's dump, last resort, with the owner present: `restore-check` the dump
  first; dump the damaged state too; restore into a new database, fingerprint
  it against the restore check, and only then swap it in.

Rehearsed: the staging form of `resume` (7 October), the isolated restore (on
the staging dump and, in preflight, on a production dump). **Not rehearsed:**
the return to baseline and a full restore on production.

## 7. Backups

- The window's dump stays **on the server**, in the release's evidence
  directory under `/root/takeover-production/` (root only, mode 700), next to
  the pre-release `.env`, configuration hashes and assets. Nothing is copied
  off the server. The nightly archive must also be present; the script checks.
- **Isolated restore** (`restore-check <dump>`): the dump is restored into its
  own PostgreSQL on a memory filesystem, under a throwaway user, in a unit with
  no network, with `/var/www`, `/root`, the live data directory and its sockets
  hidden. No application code runs in it, so no job, mail or notification can
  leave it. It prints a fingerprint of every table.
- If the application itself ever has to be started over a restored copy, that
  is a separate, approved exercise: never the production tree or its `.env`;
  a network-less unit; mail to the `array` mailer; no worker and no scheduler;
  payment and messaging keys blank.
- An off-server copy is not needed for this release. If one is wanted, it
  should be named first: destination, who can read it, encryption at rest and
  in transit, and when it is deleted.

## 8. Browser checks after `up`

Not covered by any script here.

- Guest, by the agent: the three hosts at phone and desktop widths — landing
  page, both Dhiran links, log-in and register pages, no console error.
- Signed in, by the owner (the agent does not sign in to production): one
  Retail owner and one Dhiran owner in separate browsers. Stock → Categories,
  Tag Printing, Reorder Alerts and back; Invoices → Returns / Exchange and
  Open POS; a category save, a duplicate name, a delete; where the message
  sits on a phone; Settings → Product preferences in each product; logging out
  of one product leaves the other signed in.
