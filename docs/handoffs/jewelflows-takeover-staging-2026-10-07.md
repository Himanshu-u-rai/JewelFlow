# JewelFlows takeover — Retail staging acceptance and production review

**Version 3, 7 October 2026** (version 1 `aae763b`, version 2 `a1ed636`). The
application code is the independently reviewed candidate
`5cbad199a719ffdc86a4a87ac2e60dc7d2ea01f0`; every commit after it is
documentation, runbooks or `tests/`.

**Production has not been changed.** The window and the production actions
are the owner's to choose after this review. Section 7 lists what would be
approved.

New in version 3:

- The signed-in browser acceptance on staging was run, through the owner's
  signed-in session. No failure; staging was not redeployed. See 4.
- The production check script was rehearsed on PostgreSQL 14 in production
  mode, in isolation. That found three defects in the script, now corrected,
  and the list of what it leaves behind was corrected again. See 6.
- The production candidate is therefore a later commit, `e49e611`. Its runtime
  paths are unchanged.

Corrections carried from version 2: a rolled-back transaction is not "nothing
that survives"; "16 tables matched" was a sample; the schema gate removed more
than dump noise; production has five untracked files; the mobile API answers
401 only to a JSON request.

## 1. Where things stand

| | Commit | State |
|---|---|---|
| Reviewed candidate | `5cbad199a719ffdc86a4a87ac2e60dc7d2ea01f0` | application code of everything below |
| Staging | `a87dcd58b8c8e1d1cfff9f24025872b24ab83401` | released 7 Oct, up, unchanged since |
| Production candidate | `e49e611768e62674de0c4b2a77da42d018b28c30` | read-only preflight passed 17:06:56Z; not released |
| Production | `aa3a62a93630751058c38b31f2b49c604450ec68` | unchanged (re-read 17:08Z) |

The integration branch (`integration/jewelflows-takeover`) is not pushed. The
original worktree `ui-navigation-batch1` and its uncommitted files are untouched.

## 2. Commits on the integration branch after the reviewed candidate

| Commit | Content |
|---|---|
| `a87dcd5` | Corrected recovery rule; staging release script; staging acceptance script |
| `8d50919` | Staging release script: schema gate fixed, `resume` added |
| `aae763b` | Handoff, version 1 |
| `496a7cd` | Production procedure, release script and check script |
| `346eb2d` | Production script: the mobile API probe asks as JSON |
| `a1ed636` | Handoff, version 2 |
| `dde68f4` | Check script boots in production mode and a crash can no longer pass |
| `e49e611` | Check script keeps the rate limiter in memory; procedure records the PostgreSQL 14 rehearsal |
| this commit | Handoff, version 3; the rehearsal script |

`app`, `bootstrap`, `config`, `database`, `resources`, `routes`, `public`,
`artisan` and the dependency and build manifests are identical in `5cbad19`,
`a87dcd5` and `e49e611` (tree hashes; the production preflight checks it
against both the reviewed commit and what staging runs).

## 3. Staging release, with the evidence reconciled

Deployed `a87dcd5` on `https://staging.jewelflows.com` from `aa3a62a`.
Maintenance 15:48:10Z to 15:51:05Z. Evidence, root only, on the server:
`/root/takeover-staging/{preflight,release,resume,recheck}-*`.

**Code and assets against the reviewed candidate.** Staging's HEAD is `a87dcd5`
with a clean tracked tree; its runtime tree hashes equal the reviewed
candidate's. The asset manifest on disk and the one served are both
`5d0d0da8…18ad51a49`; no file under `public/build` differs from the released
tarball (`47cdcf82…0a9e0e16`).

**Which revision of the release script ran.**

| Run | Revision | How that is known |
|---|---|---|
| preflight 15:47:46Z, release 15:48:02Z | as committed in `a87dcd5` (SHA-256 `7149fea4…82400b8`) | **Inferred.** The runs did not record the script's hash and the file was then replaced. The two logs print a "schema hash" that differs between them, which only this revision computes, and its failure message. |
| resume 15:50:52Z | as committed in `8d50919` (SHA-256 `db5b863d…6220702`), the schema-gate correction | **Verified**: the file still on the server has this hash, and the log's "existing schema (6530 lines) identical" exists only in this revision. |

The production script records its own SHA-256 and keeps a copy of itself with
each run.

**"16 tables matched".** A sample: the row counts of sixteen named tables, the
number of migrations and the number of triggers, restored copy against live.
Closed afterwards, read-only, at 16:19Z: the same dump (SHA-256
`0a402ac0…2aea7381`) restored in isolation again and **every one of its 144
tables** fingerprinted by row count and content hash; 78 hold rows. Against
live staging at that moment 142 are identical; the two that differ are
`migrations` (383 → 384, the release) and `sessions`. Fifteen relations exist
now that the backup does not have, exactly the migration's four tables, two id
sequences and nine indexes; none was lost; 42 triggers on both sides.

**Schema normalization.** The gate that passed the release dropped every line
starting `--`, every blank line and the `\restrict` lines, and left relations
out by two wildcard patterns. Re-run tightly: only pg_dump's two `\restrict`
token lines are removed (2 of 16,160) and six relations are excluded by exact
name. The schema inside the backup and the live schema are then identical byte
for byte: 16,158 lines, 9,628 of them comment or blank lines that now take
part in the comparison.

## 4. Acceptance on staging

| Check | Result |
|---|---|
| `tests/Staging/verify_takeover_release.php` (synthetic tenants, rolled back) | 25 passed, 0 failed |
| `tests/Staging/verify_security_batch.php` (rolled back) | all passed |
| Guest real browser, Chrome at 360, 390, 768, 1440 px | 28 passed, 0 failed |
| **Signed-in browser, owner's session, 1920 px and 390 px** | **no failure; below** |

### Signed-in browser acceptance (16:45Z to 17:00Z)

In the owner's own Chrome, signed in by the owner to an existing staging test
shop (owner role); the agent entered no credential. Pages were read as text
and measured, not judged from pictures. The agreed behaviour was the
criterion: a Turbo page replacement is fine if state is kept; the timed
message has to be readable and block nothing.

Desktop, 1920 px window, with real mouse and keyboard events:

| | Measured |
|---|---|
| Moved navigation | Sidebar has no Tag Printing, Reorder Alerts or Returns / Exchange. Jewellery Stock offers Categories, Tag Printing and Reorder Alerts; each opens, keeps Jewellery Stock highlighted and has Back to Stock. Invoices offers Historical Sales and Returns / Exchange; Returns keeps Invoices highlighted and has Back to Invoices. Never "Content missing" |
| Dialog | Add Category: focus in the name field 31 ms after opening; Escape closes; a click outside closes; Enter saves; both buttons 44 px high |
| Save | Totals 1/0/1 → 2/0/2, the card appears, "Category created successfully!" shows for about 4 s; the submit button is disabled while the request runs |
| Duplicate | Same name in another case: the dialog comes back with what was typed, focus in the field and the reason; totals unchanged |
| Search and page | On page 2 of a search: an accepted save, a rejected save and a delete all return to the same search and page. Deleting the last one on the page lands on page 1 of that search, with its message. Delete asks first; focus starts on Cancel; Escape cancels |
| Back and Forward | Stock → Categories → (save) → Stock → Tag Printing, then Back three times and Forward three times: the right page and highlight each time, current totals, no replayed message |
| Open POS | Tab reaches it, it shows a focus ring, Enter opens the Sales Counter |

Phone width, a 390 × 787 window of the same session:

| | Measured |
|---|---|
| Navigation | The drawer opens and closes itself after a choice. The Stock and Invoices rows are fully on screen (links 40 px high), no sideways scroll; every moved page opens and leads back |
| Dialog | A bottom sheet; Cancel and Add Category 173 × 44, in view and reachable; Escape and a tap outside close it |
| Message | Sits at the top (12,8 to 243,55). The page moves down 63 px while it shows and back afterwards. No link, button or field lies under it. With the dialog, or the delete confirmation, opened while it shows, their buttons are clear of it and reachable. The text fits |
| Categories | Duplicate, search and page kept, last-on-page fallback: as on desktop |
| Back and Forward | As on desktop; no message replayed, no space left behind |
| Open POS | Drawn 114 × 34, answers taps over 44 px of height, overlaps no other control, opens the Sales Counter |

How far this goes, and what it is not:

- The window manager would not narrow the owner's Chrome window and the
  application forbids framing itself, so the 390 px pass ran in a second
  window of the same session. The tools cannot send real input to a second
  window: there, clicks, Escape and form submission were dispatched by script.
  The same handlers took real keys and clicks at desktop width.
- It is desktop Chrome with a mouse pointer, not a touch device. Physical
  phones, Safari and Firefox: NOT RUN.
- One role (owner). What a restricted role sees in a browser: NOT RUN; the
  refusals themselves are server answers and stand on the staging script above.
- One probe of the agent's read the page 1.2 s after Enter, before the reply
  had rendered, and left the page mid-request; the save had succeeded. Re-run
  waiting for the page event: 0.8 s from submit to the updated page.
- Test data: 29 categories were created in that shop and all removed; it is
  back to its one category. No error line in the staging log during the run.

Seen and left alone, because none is part of the agreed behaviour: on the
phone width the Back links on Tag Printing and Reorder Alerts are 34 px high
and the one on Returns 36 px; the Open POS focus ring is faint (a 3 px amber
shadow at 20% opacity); closing the dialog returns focus to the page, not to
the button that opened it.

**NOT RUN on staging:** two-product checks (no Dhiran staging host exists and
none is to be created; local two-host evidence at the reviewed candidate
stands).

## 5. Remaining conditions

1. `/root/takeover-staging/` holds a staging dump and copies of the staging
   `.env` and config cache (root only). Keep until production is signed off.
2. The integration branch is not pushed.
3. Inherited, unchanged: the Reports hub's Cash Book card is shown to roles
   its page refuses; `showToast` ignores the tone it is given.

## 6. Production: prepared, nothing run but read-only preflights

Procedure: `docs/runbooks/takeover-production-release.md`. Release and resume
script: `docs/runbooks/deploy-takeover-production.sh` (SHA-256
`9994a48e…0ff13ec`). Check script:
`tests/Production/verify_takeover_production.php` (SHA-256 `1d60522f…b095ffd`).
Rehearsal script: `docs/runbooks/rehearse-production-check-pg14.sh`.

**Preflight of `e49e611`, 17:06:56Z, passed.** Production is `aa3a62a`, clean,
up; the candidate descends from the reviewed commit and every runtime path
equals both it and what staging runs; one additive migration, no dependency
change; 144 tables, 42 triggers, 383 migrations, nothing pending, none of the
fifteen relations exists; schema 16,165 lines, stable across two reads; the
three hosts and the mobile API answer as expected; ten private-storage probes
refused with 403; nightly archive present. A dump was taken, restored in
isolation and compared: 143 of 144 tables identical to live by row count and
content hash, relations, triggers and the full schema text identical; the one
table not compared, `sessions`, was written while the dump ran. The dump was
then deleted.

Four preflights ran in all (16:29, 16:30, 17:03, 17:06Z), one per candidate
and one that stopped on a wrong expectation of the script's. Each left its
evidence under `/root/takeover-production/` and guest session rows from its
own page requests, as any visitor leaves. Production's repository, tree,
`.env`, config cache, schema, worker and scheduler are as they were.

**The check script, rehearsed where it will run.** PostgreSQL 14.24, PHP
8.2.30, `APP_ENV=production`, the `--no-dev` vendor directory of the same
`composer.lock`, configuration, routes and views cached, maintenance on. It
ran on the server, the only PostgreSQL 14 available, inside a unit with no
network, its own database on a memory filesystem, and `/var/www`, `/root`, the
live data directory and its sockets hidden; its `.env` was generated and holds
no real credential. The schema came from the application's own 384 migrations
(148 tables, 42 triggers); three committed synthetic shops with promotion
rows were there before the run. Result for the script exactly as committed in
`e49e611`, 17:07:01Z: **18 checks passed**, and the release script's own
acceptance rule was met.

What that rehearsal found, each corrected before `e49e611`:

1. The script could not boot in production mode: the application forces the
   https scheme while booting and needs a request to exist first.
2. That crash exited 0. The release script trusted the exit code and would
   have recorded a check that never ran as passed. The script now exits 3
   unless it reaches its last line; the release script also requires the final
   PASSED line, no FAIL line and eighteen PASS lines.
3. The rate limiter is built during boot on the file cache, so twelve throttle
   counters were left in the cache directory although the script said the
   cache was in memory. It is now in memory.

What stays after its rollback, as measured in that rehearsal:

| | |
|---|---|
| Rolled back | every row: all 148 tables identical by count and content hash, measured inside the script and again from outside it (14 hold rows, the pre-existing promotion rows among them); the row lock on the shop-code counter and the advisory owner locks |
| **Stays: sequences** | `shops` +3, `users` +3, `roles` +9, `role_permission` +384, `shop_editions` +3, `product_promotion_preferences` +10, `product_promotion_exposures` +2. Not reset. Dead row versions and WAL until vacuum |
| **Stays: files** | four under `storage/framework/views`: two templates the framework generates for components, and their compiled forms. php-fpm writes the same files the first time such a page is served |
| Not the check's own | each boot of the application refreshes its five-minute cache of the platform's mail settings; every artisan command of the release does the same |
| Captured, not sent | 0 queue pushes, 0 notifications, 0 mails, 0 HTTP calls through the framework |
| Checked | one database connection; one transaction id from first write to rollback; only ten expected tables written; no error line logged |
| Not exercised | anything deferred until after a commit; anything a browser does |

Still not rehearsed anywhere: the release script's steps after its preflight
in their production form (their staging form ran on 7 October), the return to
baseline, and a full restore.

## 7. What an approval would cover

**Release `e49e611768e62674de0c4b2a77da42d018b28c30` to production**, from
`aa3a62a`, with the bundle `98e43e08…016150a`, the assets tarball staging
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
  approved**; it leaves the sequence gaps and files listed there. After `up`:
  guest browser checks by the agent; signed-in checks by the owner
  (procedure, 8).
- **Recovery**: before the checkout the script undoes itself. After it,
  production stays in maintenance: finish forward with `resume`, or return to
  the baseline from the window's evidence, which is allowed only while the
  four tables are empty. Once any owner has recorded a preference or consent,
  forward repair only. The window's dump stays on the server, proven by an
  isolated restore of every table; a full restore is the last resort and only
  if the site never left maintenance.

To decide: the time of the window; yes or no to the synthetic check; who runs
the signed-in checks afterwards.
