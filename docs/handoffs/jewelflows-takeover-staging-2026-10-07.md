# JewelFlows takeover — Retail staging release and production request

**Version 2, 7 October 2026** (version 1 is commit `aae763b`). The application
code is the independently reviewed candidate
`5cbad199a719ffdc86a4a87ac2e60dc7d2ea01f0`; every commit after it is
documentation, runbooks or `tests/`.

**Production has not been changed. Section 7 is a request, not a record.**

Corrected since version 1:

- "Writes nothing that survives", said of the proposed production check, was
  wrong. A rollback leaves sequence values and more behind. See 6.
- "16 tables matched" was a sample of row counts, not the whole backup. The
  backup has since been compared table by table. See 3.
- The schema gate that passed the staging release removed more than dump
  noise. It was re-run tightly. See 3.
- Production has five untracked files, not four (`.claude/settings.local.json`
  as well). The script preserves whatever is there.
- The mobile API answers 401 without a token to a JSON request; a plain
  request is redirected.
- The production target is no longer the commit staging runs but a later one
  with identical runtime paths, because it carries the production check script.

## 1. Where things stand

| | Commit | State |
|---|---|---|
| Reviewed candidate | `5cbad199a719ffdc86a4a87ac2e60dc7d2ea01f0` | application code of everything below |
| Staging | `a87dcd58b8c8e1d1cfff9f24025872b24ab83401` | released 7 Oct, up, re-read 16:19Z, tracked tree clean |
| Production candidate | `346eb2dd5ea0266f398be926870b25955e2770b7` | read-only preflight passed 16:30Z; not released |
| Production | `aa3a62a93630751058c38b31f2b49c604450ec68` | unchanged |

The integration branch (`integration/jewelflows-takeover`) is not pushed. The
original worktree `ui-navigation-batch1` and its uncommitted files are untouched.

## 2. Commits on the integration branch after the reviewed candidate

| Commit | Content |
|---|---|
| `a87dcd5` | Corrected recovery rule; staging release script; staging acceptance script |
| `8d50919` | Staging release script: schema gate fixed, `resume` added |
| `aae763b` | This handoff, version 1 |
| `496a7cd` | Production procedure, release script and check script |
| `346eb2d` | Production script: the mobile API probe asks as JSON |
| this commit | This handoff, version 2 |

`app`, `bootstrap`, `config`, `database`, `resources`, `routes`, `public`,
`composer.lock`, `package-lock.json` and `vite.config.js` have the same tree
hash in `5cbad19`, `a87dcd5` and `346eb2d` (compared locally and, for what is
deployed, on the server).

## 3. Staging release, with the evidence reconciled

Deployed `a87dcd5` on `https://staging.jewelflows.com` from `aa3a62a`.
Maintenance 15:48:10Z to 15:51:05Z. Evidence, root only, on the server:
`/root/takeover-staging/{preflight,release,resume,recheck}-*`.

**Code and assets against the reviewed candidate.** Staging's HEAD is `a87dcd5`
with a clean tracked tree; its runtime tree hashes equal the reviewed
candidate's. The asset manifest on disk and the one served are both
`5d0d0da8…18ad51a49`, the manifest of the build verified locally; no file under
`public/build` differs from the released tarball (`47cdcf82…0a9e0e16`).

**Which revision of the release script ran.**

| Run | Revision | How that is known |
|---|---|---|
| preflight 15:47:46Z, release 15:48:02Z | as committed in `a87dcd5` (SHA-256 `7149fea4…82400b8`) | **Inferred.** The runs did not record the script's hash and the file was then replaced. The two logs print a "schema hash" that differs between them, which only this revision computes, and its failure message. |
| resume 15:50:52Z | as committed in `8d50919` (SHA-256 `db5b863d…6220702`), the schema-gate correction | **Verified**: the file still on the server has this hash, and the log's "existing schema (6530 lines) identical" exists only in this revision. |

The production script records its own SHA-256 and keeps a copy of itself with
each run, so this cannot be a matter of inference again.

**"16 tables matched".** A sample. The restore rehearsal inside the window
compared the row counts of sixteen named tables (shops, users, customers,
invoices, invoice items and payments, cash transactions, karigar invoices,
stock purchases, billing settings, loyalty transactions, report exports,
idempotency keys, platform admins, categories, sub-categories), the number of
migrations and the number of triggers, between the restored copy and live
staging. It did not look at the other 128 tables or at any content.

Closed afterwards, read-only, at 16:19Z: the same dump (SHA-256
`0a402ac0…2aea7381`) was restored in isolation again and **every one of its
144 tables** was fingerprinted by row count and content hash; 78 of them hold
rows. Against live staging at that moment, 142 are identical. The two that
differ are `migrations` (383 → 384, the release itself) and `sessions`
(browser sessions since). Fifteen relations exist now that the backup does not
have, exactly the migration's four tables, two id sequences and nine indexes;
none was lost; 42 triggers on both sides.

**Schema normalization.** The gate that passed the release dropped every line
starting `--`, every blank line and the `\restrict` lines, and left relations
out by two wildcard patterns. That is more than dump noise: comment and blank
lines can be part of a function body, and a wildcard would hide an unexpected
relation with a matching name. Re-run tightly: only pg_dump's two `\restrict`
token lines are removed (2 of 16,160), and six relations are excluded by exact
name. The schema inside the backup and the live schema are then identical byte
for byte: 16,158 lines, 9,628 of them comment or blank lines that now take
part in the comparison.

## 4. Acceptance on staging

| Check | Result |
|---|---|
| `tests/Staging/verify_takeover_release.php` (synthetic tenants, rolled back) | 25 passed, 0 failed |
| `tests/Staging/verify_security_batch.php` (rolled back) | all passed |
| Guest real browser, Chrome at 360, 390, 768, 1440 px | 28 passed, 0 failed |
| Public answers | `/health` 200; guests redirected; mobile API 401 to a JSON request without a token |

**NOT RUN on staging**

- **Signed-in browser behaviour.** The rolled-back PHP script is not evidence
  for it and is not offered as such. The agent's rules forbid it to create an
  account or enter a password on a host that is not a local development host;
  the owner's browser was reachable but not signed in to staging, and
  staging's three demo accounts are disabled. Results: none. What is needed is
  a synthetic shop registered and signed in by the owner; the agent can then
  drive that session, or the owner can run the list below.
- **Two-product checks**: no Dhiran staging host exists and none is to be
  created. Local two-host evidence at the reviewed candidate stands.
- Physical phones, Safari, Firefox.

Signed-in list, at desktop width and at 390 px:

1. Sidebar has no Tag Printing, Reorder Alerts or Returns / Exchange.
   Jewellery Stock shows Categories, Tag Printing, Reorder Alerts; each opens,
   keeps Jewellery Stock highlighted and leads back. Invoices shows Historical
   Sales and Returns / Exchange; Invoices stays highlighted on Returns.
2. Categories: add a name → message, totals up by one, no reload. Add the same
   name → the form stays with what was typed and its error; totals unchanged.
   With more than 20 categories: search, go to page 2, add and delete → same
   search, same page. Delete the last one on a page → the nearest page.
3. Back and Forward across Stock → Categories → Tag Printing: right page,
   right highlight, no replayed message, no "Content missing".
4. Add Category dialog: focus lands in the name field, Enter saves, Escape and
   a click outside close it.
5. On the phone width, after a save: the message is readable, sits clear of
   the dialog's buttons, can be dismissed, and nothing under it is dead.
6. Invoices → Open POS: a tap just above or below the button still opens it;
   Tab reaches it with a visible focus ring and Enter opens it.

## 5. Remaining conditions

1. The signed-in list above, on staging.
2. `/root/takeover-staging/` holds a staging dump and copies of the staging
   `.env` and config cache (root only). Keep until production is signed off.
3. The integration branch is not pushed.
4. Inherited, unchanged: the Reports hub's Cash Book card is shown to roles
   its page refuses; `showToast` ignores the tone it is given.

## 6. Production: prepared, preflight only

Procedure: `docs/runbooks/takeover-production-release.md`. Script:
`docs/runbooks/deploy-takeover-production.sh` (SHA-256 `93016155…715becb2`).
Check script: `tests/Production/verify_takeover_production.php`.

**Preflight, 16:30:23Z, passed.** Production is `aa3a62a`, clean, up; the
candidate descends from the reviewed commit and every runtime path equals both
it and what staging runs; one additive migration, no dependency change; 144
tables, 42 triggers, 383 migrations, nothing pending, none of the fifteen
relations exists; schema 16,165 lines, stable across two reads; the three
hosts and the mobile API answer as expected; ten private-storage probes
refused with 403; nightly archive present. A dump was taken, restored in
isolation and compared: 143 of 144 tables identical to live by row count and
content hash, relations, triggers and the full schema text identical; the one
table not compared, `sessions`, was written while the dump ran. The dump was
then deleted.

What the preflight left behind: its evidence under `/root/takeover-production/`
(3.5 MB, no dump), and guest session rows from its own page requests, as any
visitor leaves. Production's repository, tree, `.env`, config cache, database
schema, worker and scheduler are as they were (re-read afterwards). A first
preflight at 16:29:47Z stopped itself on a wrong expectation of the script's
(it probed the mobile API as a plain request) after reading the database and
before taking any dump.

**The check script, described correctly.** It runs in one transaction that is
rolled back, creates three test shops with one owner each and touches only
promotion metadata. Rehearsed on the local test database (PHP 8.2,
PostgreSQL 16; production is PostgreSQL 14), inside and outside a local
maintenance window, with committed synthetic shops and promotion rows already
present: 18 checks passed.

| | |
|---|---|
| Rolled back | every row (148 tables compared by count and content hash, 14 holding rows, all identical afterwards); the row lock on the shop-code counter and the advisory owner locks |
| **Stays** | sequence values: `shops` +3, `users` +3, `roles` +9, `role_permission` +384, `shop_editions` +3, `product_promotion_preferences` +10, `product_promotion_exposures` +2. Not reset. Dead row versions and WAL until vacuum |
| Files | none written in the rehearsal; a log line would stay; the script lists them |
| Captured, not sent | 0 queue pushes, 0 notifications, 0 mails, 0 HTTP calls through the framework |
| Checked | one database connection; one transaction id from first write to rollback; only ten expected tables written |
| Observers that ran | shop code from its counter row; the shop's edition row; the user's realm default; the recognition invalidation on each user and role save. All on the one connection, inside the transaction |
| Not exercised | anything deferred until after a commit; anything a browser does |

Not rehearsed anywhere: this script on PostgreSQL 14 with production's cached
configuration. Its in-memory captures and the maintenance bypass were checked
on staging's `--no-dev` install (boot only, no database write).

## 7. Approval request

**Release `346eb2dd5ea0266f398be926870b25955e2770b7` to production**, from
`aa3a62a`, with the bundle `cfbd2379…deb5b8c2`, the assets tarball staging
received (`47cdcf82…0a9e0e16`) and the script named in 6.

- **Downtime**: one window in which Retail, Dhiran and the mobile API answer
  503 together. Expected about 2 minutes, up to 4; up to 10 more only if a
  scheduled command is mid-run when the window opens. Sessions survive. Not
  between 23:40 and 03:10 or 05:40 and 06:10 India time (the script refuses).
- **Changes**: the code and assets; two lines appended to `.env`
  (`DHIRAN_REGISTER_URL=https://dhiran.jewelflows.com/register`,
  `ERP_REGISTER_URL=https://jewelflows.com/register`); one additive migration
  (four tables, fifteen relations). Nothing else, and the script stops if
  anything else moved.
- **Test actions**: inside the window, the script's own gates, including the
  proof on three hosts. The synthetic check of 6 **only if separately
  approved**; it leaves the sequence gaps listed there. After `up`: guest
  browser checks by the agent; signed-in checks by the owner (procedure, 8).
- **Recovery**: before the checkout the script undoes itself. After it,
  production stays in maintenance: finish forward with `resume`, or return to
  the baseline from the window's evidence, which is allowed only while the
  four tables are empty. Once any owner has recorded a preference or consent,
  forward repair only. The window's dump stays on the server, proven by an
  isolated restore of every table; a full restore is the last resort and only
  if the site never left maintenance. Not rehearsed: the return to baseline
  and a full restore on production.

Needed from the owner: the time of the window; yes or no to the synthetic
check; who runs the signed-in checks afterwards.
