# JewelFlows takeover — Retail staging release and production plan

**Version 1, 7 October 2026.** Supersedes the deployment status in
`jewelflows-takeover-local-review-2026-10-06.md`. The application code is the
independently reviewed candidate `5cbad199a719ffdc86a4a87ac2e60dc7d2ea01f0`;
every commit after it is documentation or release tooling.

**Production has not been changed. Section 6 is a plan waiting for approval.**

## 1. State found at takeover (7 Oct, 21:03 IST)

- Worktree `jewelflows-takeover`, branch `integration/jewelflows-takeover`, HEAD
  `5cbad19…`, clean. The previous agent's last write was at 15:38 IST; no
  deployment, migration or remote session was running.
- Staging and production were both at `aa3a62a93630751058c38b31f2b49c604450ec68`,
  up, 383 migrations each, no promotion tables, no backup taken that day.
  **Nothing of the staging plan had been executed.**
- The original worktree `ui-navigation-batch1` (`ui/navigation-batch-3`,
  `1d01ba4`, its uncommitted files) was not touched.

## 2. Commits added on the integration branch

| Commit | Content |
|---|---|
| `a87dcd58b8c8e1d1cfff9f24025872b24ab83401` | Recovery rule corrected (runbook and earlier handoff); staging-only release script; staging acceptance script. No path under `app`, `config`, `database`, `resources`, `routes` or `public` differs from the reviewed candidate. |
| `8d50919f8c99d0791e318f465207f2f1a8fbc6ad` | Release script: schema gate fixed, gated `resume` added (see 3). Script only. |
| this document | — |

Recovery rule, as corrected: the earlier release may return only while the
four new tables are empty. Once any preference, exposure, request or
recognition row exists, recovery is a forward repair, or a compatibility
rollback that keeps preference handling and consent invalidation and has been
tested (none exists). Detail: `docs/runbooks/product-promotion-recognition.md`.

## 3. Retail staging release

**Deployed: `a87dcd58b8c8e1d1cfff9f24025872b24ab83401`** on
`https://staging.jewelflows.com`, from `aa3a62a…`. Maintenance 15:48:10Z to
15:51:05Z (2 min 55 s). Procedure: `docs/runbooks/deploy-takeover-staging.sh`.
Evidence on the server: `/root/takeover-staging/{preflight,release,resume}-*`.

| Gate | Measured |
|---|---|
| Fresh backup | `pg_dump -Fc`, SHA-256 `0a402ac0…2aea7381`, 144 table-data entries, read end to end |
| Restore rehearsal | Isolated instance (own PostgreSQL, throwaway user, no network): 16 counted tables, 383 migrations, 42 triggers equal staging |
| Migration | Only `2026_10_05_000001_create_product_promotion_tables`: 22 statements, 4 `create table`, none on an existing table. Four tables, empty |
| Existing schema | Text identical to the pre-release backup's (6,530 lines); 42 triggers; row counts of 16 tables unchanged |
| Assets | Tarball SHA-256 `47cdcf82…0a9e0e16`; served manifest, CSS and JS byte-identical to the build verified locally (manifest `5d0d0da8…18ad51a49`) |
| Configuration | `DHIRAN_REGISTER_URL` explicitly empty; no Retail override; staging identity unchanged |
| Processes | php8.2-fpm and nginx not reloaded (start times unchanged); new code proven served while still in maintenance; only the staging worker restarted; staging reconcile cron held and put back byte-identical |
| Production | Commit, maintenance state, config cache and `/health` unchanged |
| Logs | 0 new error lines in any `laravel*.log` since the release mark |

**One stop, caused by the tooling.** The first run stopped itself after the
migration at "the schema of the existing tables changed". Nothing had changed:
the gate compared hashes of two `pg_dump -s` outputs, and pg_dump writes a
random `\restrict` token into each. Staging stayed in maintenance. The schema
was compared properly against the run's own backup (identical apart from the
new tables' two id sequences), the gate was fixed (`8d50919`), and the release
was finished forward with the new `resume` mode. Preflight now reads the schema
twice and refuses to continue if the reads differ.

## 4. Acceptance on staging

| Check | Result |
|---|---|
| `tests/Staging/verify_takeover_release.php` (synthetic tenants, one transaction, rolled back) | 25 passed, 0 failed |
| `tests/Staging/verify_security_batch.php` (rolled back) | all checks passed |
| Guest real browser, Chrome at 360, 390, 768, 1440 px | 28 passed, 0 failed |
| Public answers | `/health` 200; guests redirected; mobile API 401 without a token |

The 25: landing (Retail log-in and register controls, both illustrations, no
Dhiran destination, no `dhiran.*` address); moved navigation for the owner and
for returns-only, sales-only and inventory-only roles, with the refusals;
Categories (totals after each change, search and page kept, a rejected save, a
refused delete, last-on-page falling back to the nearest page); the built
bundle on disk holding the toast strip and the Open POS touch area; Retail
product preferences, the recognition request rules, the lifecycle invalidation
on a password change; another shop's rows refused on each new surface.

**NOT RUN on staging**

- **Signed-in browser behaviour** (Turbo Back, toast placement, Open POS touch
  area, the Category modals): staging's three demo accounts are disabled and
  the agent does not sign in to a non-local host. What stands in for it: the
  served bundle is byte-identical to the one measured in a real browser
  locally, and the pages carry the markup those behaviours hang on.
  A five-minute owner check: sign in on a phone-width window; Stock →
  Categories → Back; add a category, add the same name again, delete one;
  watch where the message sits; Invoices → tap just above and below Open POS.
- **Two-product checks** (approval by a Dhiran owner, finish, revoke, cookies
  and logout per product): no Dhiran staging host exists and none is to be
  created. The local two-host evidence at the reviewed candidate stands.
- Physical phones, Safari, Firefox.

## 5. Remaining conditions

1. The owner's signed-in check on staging (above).
2. Evidence directories under `/root/takeover-staging/` hold a staging dump and
   copies of the staging `.env` and config cache (root only, mode 700). Keep
   until production is signed off, then remove.
3. The integration branch is not pushed; staging received it as a git bundle.
4. Inherited, unchanged: the Reports hub's Cash Book card is shown to roles
   its page refuses; `showToast` ignores the tone it is given.

## 6. Production release plan (version 1) — needs approval before any step

**Verified read-only on 7 Oct, about 16:00Z**

- Production is at `aa3a62a…`, the commit staging was released from, with a
  clean tracked tree. The production delta is therefore exactly the delta
  staging received: 21 commits, 64 files; 11 application files, 1 additive
  migration, 17 views, the stylesheet and script, `routes/web.php`,
  `config/platform.php`. No payment, ledger or mobile-API file.
- **Retail and Dhiran share the production application**: one nginx server
  block serves `jewelflows.com`, `www.jewelflows.com` and
  `dhiran.jewelflows.com` from one root, through one php-fpm pool, on one
  database. Both realms have live owners.
- Database: 383 migrations, 42 triggers, 144 tables, the four tables absent,
  nothing pending. Tree owned by `dev`; `.env` readable by www-data; four
  untracked files to preserve. A scheduler cron runs every minute; one
  ops-alerts worker. The nightly backup is current.
- The candidate commit is already in the staging repository, which is where
  production's bundle comes from.

**What the approval has to cover (shared Retail / Dhiran impact)**

1. One maintenance window takes down Retail, Dhiran and the mobile API
   together. Staging measured 2 min 55 s.
2. `User` and `Role` saves in both realms go through the new lifecycle path
   (owner locks, invalidation in the same transaction).
3. The public landing page on the Retail hosts is replaced. The Dhiran root
   keeps redirecting to its log-in.
4. New owner-only metadata routes in both realms; four new tables.
5. From the first preference or consent recorded, recovery is forward-only.

**A production-only setting that is required.** Without an explicit override
the Dhiran address is derived from the request host. On `www.jewelflows.com`,
which serves the application directly, that gives `dhiran.www.jewelflows.com`,
which does not exist (verified by running the function). Production's `.env`
must therefore carry `DHIRAN_REGISTER_URL=https://dhiran.jewelflows.com/register`
and `ERP_REGISTER_URL=https://jewelflows.com/register`.

**Procedure.** The staging script with these differences, as a production
variant that must first pass its read-only `preflight` on production:

| | Staging (done) | Production |
|---|---|---|
| Target | `a87dcd58…` | the same commit, bundled from the staging repository |
| Tree owner | root | `dev` (checkout and composer as `dev`) |
| Held during the window | reconcile cron, staging worker | scheduler cron (wait for running scheduled commands), production worker |
| Configuration | Dhiran address empty | the two explicit addresses above |
| Proof before `up` | landing, new route, manifest | the same on all three hosts; no `dhiran.www.` anywhere; Dhiran log-in page served |
| Smoke | Retail host | both realms; staging and the shared services untouched |
| Shared services | not reloaded | not reloaded (same proof of fresh code) |

Gates otherwise identical: identity, floor, one-migration delta, no dependency
change, pinned assets tarball, fresh dump read end to end and restored in the
isolated instance, statements of the migration, existing schema text, triggers,
row counts, ownership, logs.

**Backup and recovery.** In the window: `pg_dump -Fc` of `jewelflow`, hashed,
read end to end, restored in isolation and compared; copies of `.env`, config
cache and assets; the last nightly archive confirmed present. Recovery by the
count of the four tables: empty → baseline may return; any row → forward
repair only. A full restore only if maintenance was never left.

**Verification with synthetic accounts in both realms.** Proposed, each part
needing its own yes:

1. *Before `up`, inside the window:* a production variant of the acceptance
   script — synthetic Retail and Dhiran tenants in one transaction that is
   rolled back, requests through the kernel for both hosts, mail, sessions,
   cache and queue in memory. It would run the full recognition flow across
   the two realms (start, approve, finish, suppression, revoke), preferences,
   isolation and lifecycle. It must be written and proven against two local
   hosts first. It writes nothing that survives, but it does run synthetic
   writes inside a production transaction, hence the explicit approval.
2. *After `up`:* guest real-browser checks of the three public hosts.
3. *Signed-in checks by the owner:* one Retail owner and one Dhiran owner
   (dedicated synthetic accounts created through normal registration, or the
   owner's own), for what only real sessions show: per-product cookies and
   logout, the recognition flow in two browsers. The agent does not sign in to
   production.

**Decisions needed**

- The shared window and its time.
- Yes or no to part 1 above on the production database.
- Who performs part 3, and with which accounts.
- Whether the production dump may be copied off the server.
