# JewelFlows takeover — local candidate checkpoint

No push or deployment is authorized before user review. Production is out of scope.
The final measured
report and sanitized review ZIP identify its full SHA. Local report location:
`output/takeover/pre-staging-review.md` (ignored evidence, not application source).
Do not mistake the historical cloud handoffs or pre-commit test runs for that report.

## Provenance / scope

Actual local base: `1d01ba440dd43ba9df15d888b2fdf34a23b00049`.
Integration worktree: `/home/himanshu/Desktop/jewelflow-worktrees/jewelflows-takeover`,
branch `integration/jewelflows-takeover`. Claude's original `ui/navigation-batch-3`
worktree/branch and eleven uncommitted files remain separate and preserved.
All three packet feature patches were reconciled, not cherry-picked from reconstructed
cloud history: UI/navigation continuation, Retail/Dhiran landing, independent promotion
preferences/recognition. Their historical READMEs/manifests were inspected first.

UI fixes preserve category search/page/scroll state, rebuild category totals after
changes, handle Turbo teardown/revisit, and repair toast/modal/submission lifecycles.
Landing retains native accessible account choosers, Retail/Dhiran-only copy and existing
device illustrations. Promotion adds only four metadata tables, owner/password/consent
verification, permanent choices, one-shot exposure, cancellation and revocation.
No session sharing, entitlement grant, account merging, customer linkage or financial
schema change is introduced.

Local fixes include PostgreSQL boolean binding, dashboard savepoint recovery,
single-image password proof, sorted transaction-scoped owner locks, request-before-link
row order, expiry rechecks after waiting, stale-reader compare-and-set on request ID,
and atomic User/Role security saves (also quiet saves). Metadata routes preserve
active-owner authorization while allowing expired/read-only owners to manage consent;
business-route access gates remain unchanged. Reconfirmation displays current consent
time instead of original pair creation. The supported bulk Profile
deactivation path now uses that save path. Billing/access suspension timestamps are
not permanent shop retirement and do not revoke recognition. See the recognition
runbook for supported writers and manual-SQL limitations.

The static Open POS failure was reproduced with URL `/invoices` but old DOM `/other`.
The fixture now waits for completed Turbo restoration, asserts rendered path/focus,
and retains actual click/Enter assertions and its original six-second timeout.
The real Laravel harness also clicks above the button border and exercises Enter.

The revoke-404 review failure was a fixture identity error, not an authorization
defect: selecting the globally latest recognition timestamp returned another pair
when two pairs shared a timestamp. The helper now selects the exact request ID.
Both realms assert their actual pair bindings, authorized successful revocation,
foreign-owner 404 with unchanged data, repeated-revocation 404 and consumed consent.

Six empty-fixture constitutional skips now seed disposable records and assert the
specific database guard rejection. No guard or financial service was weakened.
Hookify repair is outside this repository, backed up separately; plugin configuration,
rules and trust remain unchanged. Its installed contract checks cover allow/deny,
Stop JSON and failure exits; no unrelated package was installed.

## Required immutable-commit gates

Use `APP_ENV=testing php8.2` and `Tests\TestDatabaseGuard`; actual target
must be loopback PostgreSQL `jewelflow_testing:5432`. Never run RefreshDatabase or
rollback against preview/staging/production. Serialize all database-writing gates.
Review strengthened the guard to refuse PostgreSQL connector destination aliases
(`connect_via_database` / `connect_via_port`), including URL query options. The
standalone harnesses enforce it after configuration loads, before provider boot,
and again afterwards. Browser API calls refuse automatic redirects; live HTTP
acceptance independently records its actual database/environment/worktree identity.
The browser's launch-level loopback-only proxy independently refuses every external
hop, including HTTP/HTTPS redirects, and is self-tested before fixture seeding.
The local HTTP router never passes PHP files to the unguarded built-in server.
The introduction race requires both workers to succeed and exactly one boolean
winner; its assertion-check mode rejects a synthetic 500 without opening a database.

- Focused PHPUnit and full regression, with JUnit/counts/skipped reasons.
- Original six multi-process races; original five forced interleavings plus approval/cancel.
- A real connection-loss probe, targeting only its own guarded testing worker's
  backend PID, checks rollback of an uncommitted link and success on a fresh connection.
- Populated promotion up/down/up rehearsal with non-promotion row/trigger hashes unchanged.
- Actual Laravel/Chrome workflows: two-host cookies, same contact/no inferred link,
  recognition, revoke/cancel, realm password reset/logout, tenant isolation, CSRF/rate
  limiting, real category/navigation/POS and phone workflows.
- Static navigation/landing/preferences (frontend-only evidence), toast lifecycle,
  PHP lint, Blade/route compilation, build and asset freshness.

Browser fixtures are not backend evidence. Explain physical device / Safari / Firefox
and any remaining PHPUnit skip explicitly. A source change creates a new candidate
SHA and invalidates relevant previous gate results.

## Read-only staging findings / proposed deployment

Reobserved staging: `/var/www/jewelflow-staging`, detached
`aa3a62a93630751058c38b31f2b49c604450ec68`, clean tracked tree, five pre-existing
untracked backup/lock files. PHP 8.2.30, cached APP_ENV=staging, debug=false,
actual read-only PDO target `jewelflow_staging`, loopback:5432. 383 migrations
applied; none of the four promotion tables exist. The cumulative scope includes
all sixteen existing local commits after that SHA, not merely the new packet delta.
Exactly one additional migration is expected: `2026_10_05_000001_create_product_promotion_tables`.

Retail staging HTTPS validates and returns 200. Desired Dhiran host must begin
`dhiran.` for existing realm routing: `dhiran.staging.jewelflows.com` currently has
no A/AAAA answer. Its DNS, staging-only vhost and TLS certificate are prerequisites,
not permission to use the production Dhiran host. Staging's cached ERP registration
URL is a production fallback and Dhiran target is null; after approval explicitly set
`ERP_REGISTER_URL=https://staging.jewelflows.com/register` and
`DHIRAN_REGISTER_URL=https://dhiran.staging.jewelflows.com/register`, then rebuild and
inspect the staging config. Do not follow the current production URL.

Existing deployment scripts cannot run unchanged: forward script refuses asset/schema
changes; security script has an obsolete baseline/migration list; both reload shared
FPM. Reuse their fail-closed identity/backup/permissions/cache/log gates in a reviewed
staging-only procedure. Staging queue unit is `jewelflow-staging-ops-alerts.service`.
FPM timestamp validation is On with two-second revalidation and no pool override,
so do not reload the shared service: wait beyond revalidation, then prove fresh
staging code/assets. Restart only the staging queue unit. If that fails, stop;
do not fall back to a shared FPM restart.

Exact proposed sequence, only after approval and DNS/TLS prerequisites:

1. Reverify full baseline SHA, clean tracked tree, security floor ancestry, effective
   staging URLs/database/no read-write split, writable paths and www-data readability.
   Transfer the reviewed candidate as a Git bundle or approved branch; verify exact SHA.
2. Enter staging maintenance; stop only the staging queue unit and allow in-flight
   staging requests to drain. Record log offsets, business row counts/hashes and all
   migration names. Preserve existing untracked files.
3. Make protected, fresh `pg_dump -Fc -d jewelflow_staging`; SHA-256 it, inspect its
   table-data TOC and read the whole archive with `pg_restore -f /dev/null`. Back up
   staging .env/config/public-build and the original code SHA. The older August 16
   staging dump reads end-to-end (134 table-data entries), but is not this release's
   rollback backup. No fresh backup or restore has yet been executed.
4. Use candidate migration file ONLY, before enabling new code; inspect four empty
   metadata tables and unchanged existing triggers/business hashes. Checkout the
   exact candidate without overwriting untracked files. Install locked dependencies
   with production flags; build assets and run asset-freshness verification.
5. As www-data (already able to read staging .env), rebuild config/routes/Blade and
   optimized classmap. Check ownership/readability. No root-owned runtime files.
   Wait past FPM's revalidation interval; test staging health and exact candidate UI.
6. Restart only staging queue, lift staging maintenance and run the real two-product
   workflows with dedicated synthetic staging accounts, CSRF/tenant/session/recognition
   checks, logs and measured results. No destructive tests on staging. Stop on any
   failed identity/migration/freshness/access gate.

Rollback (corrected 2026-10-07; the earlier text here said to keep the new tables under
the old code, which is only safe while they are empty): before the migration, recover
the recorded baseline code/assets/config. After it, the baseline may return only while
all four tables are empty (counted in maintenance). Once a preference, exposure, request
or recognition exists, recovery is a forward repair, or a compatibility rollback that
keeps preference handling and consent invalidation and has been tested (none exists
yet); see `docs/runbooks/product-promotion-recognition.md`, "Recovery". A full database
restore is a separate approved maintenance operation requiring no intervening writes and
the fresh verified backup. Never drop the tables, replay old signature/security
migrations or restore a pre-security code baseline. `CROSS_PROMOTION_ENABLED=false`
alone is not a feature rollback. Failed post-maintenance gates leave staging down.

No production checkout, database, configuration, URL or process is a deployment target.

Superseded 2026-10-07 by the owner: no Dhiran staging hostname is to be created; Retail
staging is released alone with `DHIRAN_REGISTER_URL=` (explicitly disabled), and the
two-product staging checks are recorded NOT RUN (local two-host evidence stands).
Earlier note: the DNS owner must publish
`dhiran.staging.jewelflows.com A 147.93.96.166` (suggested TTL 300), with no AAAA
unless a measured working IPv6 target is provided. An operator must provision a
certificate whose SAN covers that exact host and route it exclusively to
`/var/www/jewelflow-staging/public`, preserving all production vhosts. DNS-01
validation avoids stopping the shared web server. A new nginx vhost normally
requires a shared nginx graceful reload: that is an explicit coordination/approval
prerequisite, not a staging-only process restart or an action authorized here.
After that separately reviewed provisioning, verify DNS, certificate chain/SAN,
realm routing and both front doors without production fallback. No provisioning
or shared reload has been performed by this local batch.
