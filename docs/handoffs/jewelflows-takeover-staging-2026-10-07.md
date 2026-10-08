# JewelFlows takeover — staging acceptance and production release

**Version 15, 8 October 2026: the closure record of the takeover and
stabilization batches** (version 1 `aae763b`, version 2 `a1ed636`,
version 3 `99587f2`, version 4 `f158425`, version 5 `e9bdb7d`, version 6
`0a7eb55`, version 7 `35b640e`, version 8 `1287a8e`, version 9 `896d70e`, version 10 `cf3ee22`, version 11 `4b496b3`, version 12
`a1703fe`, version 13 `c809f6e`, version 14 `8456682`).

**Production and staging now run `6c2ac60050d7ac728bbe872e98a5b2d56f825a39`**
(10:19Z on 8 October): the stabilization batch of section 10, its second
pass (11) and the closure checks (12). What follows describes the takeover release that came
before them. The
application code is the independently reviewed candidate
`5cbad199a719ffdc86a4a87ac2e60dc7d2ea01f0`; every commit after it is
documentation, runbooks or `tests/`.

**Production was released on 8 October 2026, 03:36Z, with the owner's
approval. It runs `e49e611768e62674de0c4b2a77da42d018b28c30`.** Section 8 is the
record. The signed-in browser checks on production were then run (end of
section 8), the recognition flow included: the owner typed the passwords, the
agent verified each step and removed the recognition again.

**Start here for the next task: section 15.** It supersedes every earlier
"where the next task starts" in this file (sections 9 to 14) and holds the
one backlog. The earlier sections remain as the record of how things got
here. It names the one worktree and
branch to use. The inventory of every older branch, stash and worktree, and
what became of each, is in `jewelflows-continuity-audit-2026-10-08.md`.

New in version 9: `main` is being reconciled through **draft pull request
#2** (section 9); the inventory of older work is finished, with a disposition
for every item, in the audit (version 3). No application code changed.

New in version 8: the release was verified again after the window and the
second log-out direction was run (end of section 8); the branch is published
(section 9). The placement of the Product preferences page is **deferred by
the owner's decision**; nothing about that page, its navigation or its design
was changed.

New in version 5: section 8, the release and its verification. Versions 6
and 7: the signed-in results at the end of section 8. One finding from the
owner: the Product preferences page is hard to find (end of section 8).

New in version 4 (section 6a):

- The release script's steps after its preflight, an interrupted run finished
  by `resume`, and the return to baseline were rehearsed in an isolated
  disposable copy: 26 scenarios, all as expected, on the script as committed.
- That rehearsal found three defects in the release script, each of which
  would have stopped a production release inside its window. All corrected.
- The return to baseline is now a mode of the script that enforces its rule:
  it refuses while any promotion row exists.
- The application candidate is unchanged, `e49e611`. The release script is a
  later revision than the copy inside that commit, so **the production
  preflight has to be run again, with this revision, immediately before an
  approved release.** It was not run again now.
- The review packet of version 3 is superseded by the one issued with this
  version.

From version 3: the signed-in browser acceptance on staging (4) and the
PostgreSQL 14 rehearsal of the check script (6).

Corrections carried from version 2: a rolled-back transaction is not "nothing
that survives"; "16 tables matched" was a sample; the schema gate removed more
than dump noise; production has five untracked files; the mobile API answers
401 only to a JSON request.

## 1. Where things stand

| | Commit | State |
|---|---|---|
| Reviewed candidate | `5cbad199a719ffdc86a4a87ac2e60dc7d2ea01f0` | application code of everything below |
| Staging | `a87dcd58b8c8e1d1cfff9f24025872b24ab83401` | released 7 Oct, up, unchanged since |
| Production | `e49e611768e62674de0c4b2a77da42d018b28c30` | released 8 Oct 03:36Z from `aa3a62a…`; up |
| Release tooling | `de92e604da275768ed1ea7391ea19193639a3242` | rehearsed in isolation 8 Oct from 02:39Z; used for the release |
| Earlier production release | `aa3a62a93630751058c38b31f2b49c604450ec68` | the baseline the window's backup and evidence belong to |

The integration branch (`integration/jewelflows-takeover`) is published
(section 9). The original worktree `ui-navigation-batch1` was removed on
8 October, without force, after its eleven uncommitted paths were verified
byte for byte against `archive/ui-navigation-batch1-uncommitted-20261008`.

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
| `99587f2` | Handoff, version 3; the PostgreSQL 14 rehearsal script |
| `effafd0` | Work in progress on the release tooling (superseded by the next) |
| `de92e60` | Release script: worker health, untracked files by content, sessions row by row, `baseline`, `tree-check`; the tooling rehearsal script; the procedure |
| `f158425` | Handoff, version 4 |
| this commit | Handoff, version 5: the production release |

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

Procedure: `docs/runbooks/takeover-production-release.md`. Release script
(`preflight`, `release`, `resume`, `baseline`, `tree-check`, `restore-check`):
`docs/runbooks/deploy-takeover-production.sh`, SHA-256
`8ef5bac9bd49f5d73f3a6798f87eb5dc1d3e966498f410db81393da52227914e`. Check
script: `tests/Production/verify_takeover_production.php`, SHA-256
`1d60522f6fe1b5c2493df34e3f458d6342d9f7808a29a73b7365ac7b2b095ffd`. Rehearsal
scripts: `docs/runbooks/rehearse-production-check-pg14.sh` and
`docs/runbooks/rehearse-release-tooling.sh` (SHA-256
`a71ef1572bb3f5a13529f2a2de2e1f46ec07fccdc66d59b11f7a7c8bb7ca922c`).

The preflights below ran on 7 October with the script revisions of that day
(`9994a48e…0ff13ec` for the last). They are evidence about the candidate and
about production as it then was; they are not a preflight of the present
script revision.

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

## 6a. The release tooling, rehearsed in an isolated copy (8 October)

`docs/runbooks/rehearse-release-tooling.sh <deployed> <target> <assets sha256>`,
run on the server as root from 02:39:47Z, about six and a half minutes.
Evidence, root only:
`/root/takeover-production/tooling-rehearsal-20261008T023947Z`.

**What it ran against.** Not production. One transient unit in which the
production paths and names resolve to a copy built for the run:

| | In the unit |
|---|---|
| `/var/www` | a directory of the run: a clone of the repository at `aa3a62a` owned by `dev`, the same vendor and asset directories, a generated `.env` with no real credential, empty storage, untracked files laid out as production's are (dummy content) |
| `/root`, `/etc/cron.d`, `/home` | directories of the run |
| `/run`, `/dev/shm` | empty memory filesystems: no service-manager socket, no D-Bus, no PostgreSQL or php-fpm socket of the host |
| Processes | a process namespace of its own: the host's worker, php-fpm, PostgreSQL and cron cannot be seen or signalled |
| Network | loopback only; the three host names reach an nginx started in the unit from the real site file with a throwaway certificate, and a php-fpm of its own |
| Database | PostgreSQL 14.24 on a memory filesystem, built by the application's 383 migrations at `aa3a62a`, three synthetic shops, two sessions |
| Everything else | read-only; `/var/lib/postgresql`, `/etc/letsencrypt`, `/var/backups`, `/var/log`, `/etc/ssh` masked; `/sys`, control groups and `/proc/sys` read-only |

The driver proves all of that before it does anything (per-run sentinels in
the three directories, no host process visible, the sockets absent, loopback
only, the masked directories empty, the database on the default socket being
its own) and aborts otherwise. It did abort twice while it was being built,
before running anything: once because `/run` was not masked (two unit
settings, since removed, defeat the mask on this systemd), once on a check of
its own that was too strict. The first draft had no process namespace; review
caught that before any run.

The release script is run unmodified and passes its production identity
checks because the copy is production-shaped. No guard was loosened. The two
approval variables are set inside the copy; that approves nothing.

**Stand-ins, which are the limits of this rehearsal.** `systemctl`: the worker
is a real `artisan queue:work` run as `www-data` with the unit's command line,
`Restart=always` and `RestartSec=5`, every start and exit recorded; php-fpm
and nginx report a fixed start time. `systemd-run`: the script's isolated
restore runs as an unprivileged user in a mount namespace of its own inside
the unit (the real transient unit ran in the production preflights). `date`:
only the India-time reading of the scheduler-window guard is pinned. Data:
synthetic, not production's.

**Result: 26 scenarios, 26 as expected; no error line in the copy's log.**

| | Scenario | Outcome |
|---|---|---|
| A | preflight | passed, nothing changed |
| A | release without the approval variable | refused |
| A | release at 23:50 India time | refused at quiesce, nothing changed |
| A | preflight with an untracked file where the target has a path | refused; the file untouched |
| B | whole release, synthetic check approved | passed; 18 checks; worker started after `up` and steady |
| B | `baseline` after that release | refused: not in maintenance |
| C | release killed (SIGKILL) after the migration | left in maintenance |
| C | `resume` | passed |
| D | release killed after the migration | left in maintenance |
| D | `baseline` with one preference row present | **refused; commit, `.env`, assets, config cache and the row unchanged** |
| D | `baseline` with the tables empty | returned; `.env` byte-identical; tables left in place |
| D | `release` again | passed; migration recognised as applied, not run again |
| E | release killed before the migration | left in maintenance |
| E | `resume` | refused: nothing to finish |
| E | `baseline` | returned; every table and relation identical to before |
| F | release killed after the migration | left in maintenance |
| F | `resume` after a business row was changed | refused, naming `categories` |
| F | `resume` after a signed-in user's session was changed | refused: one existing row changed |
| F | `resume` after an untracked file was changed | refused, naming the file |
| F | `resume` with all three put back | passed |
| G | release killed at the checkout; cut-short state completed by hand | left in maintenance |
| G | `resume` on the dirty tree | refused |
| G | `baseline` on the dirty tree | refused; three files, `.env` and the lock untouched |
| G | `tree-check` with a hand-edited file | STOP; no command written |
| G | `tree-check` | every path the other commit's version; four commands written, none run |
| G | the commands run by hand, then `baseline` | returned; every table identical to before |

The first fourteen are the scenarios of the unfinished run of 7 October; the
other twelve were added for the review points.

**The worker (why "release again after baseline" had failed).** Not a harness
artefact alone. With `--max-time`, Laravel's worker leaves with status 0 after
its first pause when the application is in maintenance:
`Worker::pauseWorker` calls `stopIfNecessary` without a start time, so the
limit counts from the machine's boot. Evidence: the framework source; the
staging journal of 7 October (started 15:51:03Z in maintenance, deactivated
15:51:06Z, restarted by systemd 15:51:11Z); the copy's own unit record
(started in maintenance, exit status 0 after 3.2 s, restarted, the same
again; started with the site up, the same process twelve seconds later). The
old script started the worker before `up` and only asked that it had not
failed. The stand-in had no restart policy, so there the worker stayed gone
and the next preflight rightly refused. Fixed on both sides: the script
starts the worker after `up` and requires it active with the same main
process ten seconds apart; the stand-in restarts like the real unit. In the
clean run every worker exit after the diagnosis block was a requested stop.

**Defects the rehearsal found in the release script**, all corrected in
`de92e60`:

1. *Untracked files.* The gate compared git's listing, which folds an
   untracked directory into one line and unfolds it once the target tracks a
   file in it. Production has such a directory (`docs/superpowers/`); the
   release would have stopped after the checkout with production in
   maintenance and no file touched. Now every untracked file is compared by
   path and content hash, and a file in the target's way is refused before
   the window.
2. *The comparison after the synthetic check* included guest sessions opened
   by the script's own proof requests, so an approved check would have failed
   the release. The check is now bracketed by two fingerprints taken
   immediately before and after it.
3. *The worker*, above.

Two more were mine and never left the work in progress: a baseline proof
that expected 404 on a Dhiran path the earlier release redirects, and a
whole-table exemption for `sessions`, since narrowed to the two understood
kinds of change.

**Still not rehearsed:** any of this on production, on production's data or
under the real service manager; SIGKILL landing inside `git checkout` itself
(its state was built by hand, in the form where the index is not yet
written); `tree-check` and `baseline` with HEAD at the target and a dirty
tree; a full database restore. The procedure lists these too.

## 7. What an approval would cover

**Release `e49e611768e62674de0c4b2a77da42d018b28c30` to production**, from
`aa3a62a93630751058c38b31f2b49c604450ec68`, with the bundle
`98e43e0819eb71239f4b03f1b017f3b3dace65400f51865ca40f9263e016150a`, the assets
tarball staging received
(`47cdcf8200d2991ff4ae9491d3269741ed59277ad471bcedab71a9610a9e0e16`) and the
script named in 6, preceded by a fresh `preflight` with that script.

- **Downtime**: one window in which Retail, Dhiran and the mobile API answer
  503 together. Expected about 2 minutes, up to 4 (in the copy, about half a
  minute from `down` to `up`, without real data or load); up to 10 more only if a
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
  production stays in maintenance: finish forward with `resume`, or return
  with `baseline`, which refuses unless the four tables are absent or empty
  and the site never left that window. Once any owner has recorded a
  preference or consent, forward repair only. A dirty tree is refused by both
  and nothing is discarded; `tree-check` and the procedure cover it. The window's dump stays on the server, proven by an
  isolated restore of every table; a full restore is the last resort and only
  if the site never left maintenance.

To decide: the time of the window; yes or no to the synthetic check; who runs
the signed-in checks afterwards.

## 8. Production release, 8 October 2026

Approved by the owner for: one shared window for Retail, Dhiran and the
mobile API; candidate `e49e611768e62674de0c4b2a77da42d018b28c30` from
`aa3a62a93630751058c38b31f2b49c604450ec68`; the release script of `de92e60`
with SHA-256 `8ef5bac9bd49f5d73f3a6798f87eb5dc1d3e966498f410db81393da52227914e`;
the synthetic check with its documented lasting effects.

**Before.** No other login, deployment, rehearsal, git or artisan process on
the server; no local writer. Script, bundle (`98e43e08…016150a`) and assets
tarball (`47cdcf82…0a9e0e16`) on the server matched the version 4 packet.
Fresh `preflight` with that script at 03:27:32Z: passed (production at the
expected baseline, clean, 5 untracked files recorded by content, 144 tables,
383 migrations, nothing pending; dump restored in isolation, 143 of 144 tables
identical, `sessions` written meanwhile; dump deleted). India time 08:57,
outside the refused windows.

A first attempt to start the release at 03:27:59Z started nothing: the
agent's own launch guard matched its own command line. Production was
re-read (unchanged, up) and the release started at 03:36:00Z.

**The release** (`/root/takeover-production/release-20261008T033600Z/run.log`):

| | Measured |
|---|---|
| Script | SHA-256 `8ef5bac9…227914e`, recorded by the run and copied into its evidence |
| Maintenance | from just after 03:36:05Z to 03:36:27Z: **about 22 seconds** of 503 on the three hosts and the mobile API |
| Backup | `/root/takeover-production/release-20261008T033600Z/jewelflow.dump`, on the server, root only (directory 700, file 600), SHA-256 `e03a895c09c323808a0fee6dc398ad9b9c21d68ddeeb5f578beaf7bc76a75030`, 144 table-data entries, read end to end |
| Isolated restore | 144 of 144 tables identical to live by row count and content hash; relations, triggers and the full schema text identical |
| Code and assets | checked out `e49e611…` as `dev`; manifest `5d0d0da8e6f1789fe183291f9fac84e49f8c28e22cf1cea01368b9718ad51a49`, the one verified on staging |
| Configuration | the two product addresses appended to `.env` and in effect; every other `.env` line and every other setting unchanged; ownership and modes as before |
| Migration | `2026_10_05_000001_create_product_promotion_tables`: fifteen named relations added, four empty tables; every existing table with its rows and content; existing schema (16,165 lines) identical; 384 migrations |
| Files | every changed file readable by `www-data`; no new root-owned file; all 5 untracked files present with their content |
| Proof before `up` | new landing with the one Dhiran address on both Retail hosts, new routes in both products, released manifest; visitors still 503; ten private-storage probes 403 |
| Synthetic check | 18 passed, 0 failed; no row left (every table fingerprinted immediately before and after) |
| After `up` | scheduler file back byte-identical; worker started after `up` and steady; smoke passed; no new error line; staging, php-fpm, nginx and its site file untouched |

What the synthetic check left, as approved: sequences `users` +3, `shops` +3,
`roles` +9, `role_permission` **+474**, `shop_editions` +3,
`product_promotion_preferences` +10, `product_promotion_exposures` +2; four
files under `storage/framework/views` (two framework-generated component
templates and their compiled forms). The `role_permission` gap is larger than
the +384 measured in the rehearsals: production has more permissions per
default role than the rehearsal databases had. Nothing was reset.

**After reopening** (read again at 03:37Z and 03:41Z):

| | Result |
|---|---|
| Hosts | `jewelflows.com`, `www.jewelflows.com`: `/health`, `/`, `/login`, `/register` 200. `dhiran.jewelflows.com`: `/health`, `/login`, `/register` 200, `/` 302. Served manifest on all three: the released one |
| Product links | both Retail hosts link to `https://dhiran.jewelflows.com/register` and `/login`; no `dhiran.www.`, no staging address |
| New routes | a guest is redirected on `/product-preferences` and `/dhiran/product-preferences` |
| Mobile API | 401 to a JSON request without a token |
| Private storage | 403 on all five prefixes, both products |
| Worker | active; the same main process at 03:37Z and 03:41Z, started 03:36:27Z |
| Scheduler | file identical; cron ran `schedule:run` every minute after `up` (five runs by 03:41Z) |
| Application log | no line at all since the release began, so no error |
| Promotion tables | 0 rows in all four |
| Staging | `a87dcd5…`, `/health` 200; php-fpm (since 19 Sep) and nginx (since 16 Sep) not reloaded |

**Guest real browser** (Chrome, 360, 390, 768 and 1440 px, nothing typed or
submitted): 34 passed, 0 failed. New landing on both Retail hosts without
sideways scroll; log-in and register controls; both illustrations; the Dhiran
links exactly the two explicit addresses, also on `www`; "Dhiran login" opens
the Dhiran log-in and Back returns; Retail log-in, Retail register and Dhiran
register open; the Dhiran root lands on its log-in.

One thing the browser showed that is not of this release: Cloudflare's edge
adds its analytics beacon to every page and the application's content policy
refuses it (a console error and a blocked request on each page load). The
policy file is unchanged by this release; the page as the origin serves it
does not contain the beacon. Whether the policy should allow it or the edge
should stop adding it is a decision, not a defect of the release.

**Signed in on production** (the owner's Chrome, 04:20Z to 04:32Z; the owner
signed in to the Retail owner of shop 1 and the Dhiran owner of shop 2, which
the owner designated as the test accounts; the agent entered no credential):

| | Result |
|---|---|
| Retail navigation | sidebar without the three moved entries; Stock offers Categories, Tag Printing, Reorder Alerts, each opening with Jewellery Stock highlighted and a way back; Invoices offers Returns / Exchange with Invoices highlighted and a way back; Turbo throughout, never "Content missing" |
| Categories | one test category added (totals 3/17/0 → 4/17/1, message shown, focus in the name field); the same name in another case refused with the text kept; deleted after its confirmation (focus on Cancel); totals back to 3/17/0 |
| Introduction | on the Retail owner's first dashboard, not on the second visit; one exposure row. The Dhiran owner pressed "I already use JewelFlows Retail" themselves: recorded, and the Dhiran page says offers are switched off |
| Product preferences | opens in each product on its own host, with the preference, code and approval forms |
| A request | created by the owner with the Retail password: one pending row, only a hash stored, ten-minute expiry; the code is not shown again on a second visit |
| Wrong password | an attempt with a password the application did not accept created nothing and said so |
| Withdrawal | "Cancel this request" on the Retail page: "Request cancelled.", the row consumed, the page no longer waiting |
| Independent sessions | logged out of Retail through its confirmation dialog: Retail asks for a log-in, the Dhiran session still opens its pages |
| Left behind | 2 preference rows, 2 exposure rows, 1 consumed request, 0 recognitions. Categories, invoices, customers and loans of the two shops as before. No error line |

**Recognition across the two products, in the owner's two signed-in sessions**
(the owner typed every password and ticked every consent; the agent read the
pages and the rows, and typed no credential):

| Step | Result |
|---|---|
| Request (Retail owner, 10:07:24 India time) | one pending row, expiring ten minutes later |
| Approval (Dhiran owner, 10:08:00) | the row carries the Dhiran owner and shop; the Retail page then names the approving business and asks for the final confirmation; no recognition exists yet |
| Final confirmation (Retail owner, 10:11:32) | request consumed; one recognition row for exactly that pair, purpose `promotion_suppression`, a 64-character proof on each side |
| Both products | each shows "Goldlux — other product confirmed on 08 Oct 2026" and "Remove this recognition" |
| Removal (from the Dhiran side, 10:12:20) | "Recognition removed."; `revoked_at` set; neither product shows it or offers to remove it any more |
| Nothing else moved | invoices, customers, loans, users and editions of the two shops unchanged (no edition granted, nothing shared); no error line |

Rows now: 2 preferences (both owners chose "I already use…" themselves), 2
exposures, 2 consumed requests, 1 revoked recognition; nothing open.

**A finding from the owner while doing this: the page is hard to reach.**
Measured: each product has exactly one standing entry, a secondary
"Product preferences" button in the header of its Settings page (visible and
on top at 1920 px and at 390 px); the only other entry is the introduction
card, which is shown once. Nothing in the fourteen Settings tabs mentions it,
and one of them is called "Preferences" and is something else. The owner did
not find it. By the owner's own rule for where features live (prominent,
clearly labelled) that is a defect of placement, not of function. It is not
fixed here: it is an application change and goes local → staging →
production.

**NOT RUN on production**

- Suppression of the introduction by an established recognition, as seen in
  a browser: both owners had already switched offers off themselves, so there
  was no introduction left to suppress. It is covered through the HTTP kernel
  by the check script (18 checks in the window).
- Physical phones, Safari, Firefox; restricted roles in a browser; the phone
  width signed in on production (measured on staging on the same bundle).
- The Dhiran "Sign out" button pressed by a person on production (see the
  next table: the log-out itself was run, the press was not).

**The other log-out direction (version 8)**

| Step | Result |
|---|---|
| Both sessions signed in | Dhiran and Retail each open their own pages |
| Log out of Dhiran | the Dhiran session's own log-out request, sent from its page with the form's own fields: answered with a redirect to the Dhiran log-in; Dhiran pages then ask for a log-in |
| Retail afterwards | still signed in: Settings opens, same user, same shop |
| What this does not show | two automated presses of the "Sign out" button sent no request at all (the web server's log has none), so the request was sent from the page instead. On the deployed code run locally with a synthetic owner, a real press of the same button signs out. An artefact of the automation, not a defect; a person's press on production remains NOT RUN |

**Verified again after the window (05:01Z to 05:14Z, read-only)**

| | Result |
|---|---|
| Source | production at `e49e611…`, no tracked file modified; staging at `a87dcd5…`, the same |
| Built assets | every file equal to the released tarball; manifest `5d0d0da8…` on both |
| Migrations | 384 run, none pending; 148 tables, 42 triggers |
| Configuration | differs from the pre-release copy in the two explicit product addresses and in nothing else |
| Health | 200 on the three production hosts and on staging; no maintenance file |
| Worker | active; one restart at 04:36:33Z, the hourly one its `--max-time=3600` asks for, result success |
| Scheduler | cron file present; one run a minute, 85 in the 85 minutes after reopening |
| Errors since the window | application log: no line (no log file for the day exists). php-fpm: none. Web server error log: 40 lines, every one a refused probe for private storage. Server errors answered: 7, all 503, all between 03:36:10Z and 03:36:26Z, inside the window |
| Another deployment or agent | none running; no other login on the server |

**Why production, staging and the branch differ.** Staging (`a87dcd5`) is
seven commits behind production (`e49e611`); those commits change one
handoff, three runbooks and the production check script, and nothing an
installation runs. The branch tip is the deployed commit plus commits that
change only `docs/`. `app`, `bootstrap`, `config`, `database`, `resources`,
`routes`, `public`, `artisan` and the dependency and build manifests are the
same tree in all three. Staging was left as it is: bringing it level would be
a deployment that changes no behaviour.

**Recovery position now.** Production has left the window and two owners have
recorded promotion metadata, so the return to `aa3a62a` is closed: the script
refuses it and so does the rule. Recovery is forward repair.
The window's dump is the last complete copy of the database before the
release.

## 9. Closure, and where the next task starts

**Use this, and nothing older:**

| | |
|---|---|
| Worktree | `/home/himanshu/Desktop/jewelflow-worktrees/jewelflows-takeover` |
| Branch | `integration/jewelflows-takeover`, tracking `origin/integration/jewelflows-takeover` |
| Commit | the one that adds this version; `git rev-parse HEAD` and `git rev-parse @{u}` must print the same value, and `git status --short` nothing |
| Relation to production | production runs `e49e611768e62674de0c4b2a77da42d018b28c30` (tag `release/production-20261008`), which is an ancestor of this commit; everything after it changes only `docs/` (`git diff --stat release/production-20261008 HEAD -- . ':!docs'` prints nothing) |
| Relation to `main` | `origin/main` (`dab0774…`) is an ancestor and predates the release. It is being brought level by **draft pull request #2** (`https://github.com/Himanshu-u-rai/JewelFlow/pull/2`, base `main`, head this branch, opened by the owner). **Do not open a second pull request, do not push `main` directly, and do not deploy `main` until #2 is merged** |

Cut the next task's branch from this commit. Do not start from
`fix/small-batch-20260914` (the owner's checkout, September), from any
`archive/…` tag, or from a branch listed as unfinished in the audit.

**Published on 8 October:** this branch and the tag
`release/production-20261008`, by an ordinary push, nothing forced. Before
publishing, the 9,467 added lines of the 34 commits were searched for keys,
passwords, addresses, phone numbers and names: the only credentials are the
fixed test password and test phone number of synthetic local fixtures. The
`archive/…` tags were **not** published: several hold old unreviewed work and
browser snapshots. They exist only in the local repository, which makes that
repository the recovery copy for them.

**Not done, by decision or because it is not this release:**

- The placement of the Product preferences page: deferred by the owner.
- Moving `origin/main`: in hand through draft pull request #2. Commits pushed
  to this branch join that pull request; they are documentation only.
- Everything in sections 3 to 5 of the audit (version 3): two confirmed
  defects with a ready fix on an old branch, the open findings of the
  security and tenant batch, and the decisions that are the owner's.

**Local repository after the inventory (version 9):** three worktrees (the
owner's checkout, this one, `main-integration`); branches `main`, this
branch, the owner's `fix/small-batch-20260914`, and three kept on purpose
(`fix/returns-flash-channel`, `design/customers-codex-polish`,
`fix/masters-safety-guards`: see the audit); no stash; 17 `archive/…` tags,
local only.

## 10. Stabilization batch, 8 October 2026 (version 10)

One ordered batch on branch `fix/stabilization-20261008`, cut from the head
of pull request #2. **Historical work is not all closed: section 4 of the
audit still lists confirmed defects and open decisions.**

### Deployed

| | Commit | When (UTC) | Maintenance |
|---|---|---|---|
| Production | `bcc336a4b7c88e5431f35dbaa7bddc5d56774574` (from `e49e611…`) | 09:04:04 to 09:04:13 | 9 s |
| Staging | the same (from `a87dcd5…`) | 08:57:07 to 08:58:53, then 09:03:03 to 09:03:08 | 106 s, then 5 s |

Asset manifest on both: `75085b6b4d80a448…` (assets tarball
`1659514cc24a4097…`). Release script `docs/runbooks/deploy-stabilization.sh`,
SHA-256 `3c157bcc29489173…` for the production run. No migration; 384 run,
none pending. Evidence, root only: `/root/stabilization/` on the server.

### What changed

| Commit | Change | Evidence |
|---|---|---|
| `fe9d114` | Mobile quick-bill creation is idempotent: the route runs behind the existing idempotency middleware, key optional for older builds | 7 feature tests (replay, changed payload refused, unrecorded outcome refused, key reusable after a refusal, two shops independent, no permission, no key); a multi-process race probe, 3 scenarios, with a keyless control that books all four; staging check through the HTTP kernel |
| `7e78687` | Warning flashes are shown: the return-policy bounce says why. The message stays a `warning`; no controller changed | a check that fails if a layout emits a flash channel the script does not show; a feature test on the page the user lands on; the served script on staging contains the reader |
| `76dd1ef` | Account Security linked from the platform-admin sidebar, for every admin | feature test for both roles; rendered by the deployed layout on staging |
| `d6fd2ca` | `subscription:reconcile-payments` defers on a provider timeout or rate limit and fails after six in a row; `platform:archive-audit-logs` is retired | 7 tests with the provider listing replaced; the schedule on both environments no longer lists the archive command |
| configuration | `SESSION_SECURE_COOKIE=true` on both environments, the one line of `.env` that changed | 4 tests; every cookie `Secure` on the three production hosts and on staging, read over the public path; plain HTTP redirects to HTTPS |

Full suite on PHP 8.2 at the deployed commit: 3,542 tests, 17,845
assertions, 0 failures, 1 skipped. JavaScript checks: 4 of 4.

### What the staging run caught

1. **The script unpacked the assets as root** where staging's belong to the
   web user. Its own gate stopped it, in maintenance. Fixed (`a47d956`), and
   the release finished with the new `resume` mode.
2. **My first assets were built from a clean export of the commit and lost
   142 CSS rules.** The stylesheet is generated partly from compiled views
   that only a real checkout has. Staging served that stylesheet from
   08:58:53Z to 09:03:08Z; production never received it. The preflight now
   refuses a build that loses a deployed rule (`be19d9d`); the corrected build
   differs from the previous production build in the script and the manifest
   only. **Build assets in the worktree itself, never from an export.**

### On the server, outside the application

- **Old copies of configuration contained, then removed.** Eleven copies of
  old environment files inside the old agent worktrees were readable by other
  local accounts; they were restricted to their owner and then removed with
  the worktrees. All 22 worktrees are gone, each only after every one of its
  uncommitted files matched a verified archive, without force. A second
  archive holds the ignored files that existed nowhere else. Both archives
  are root-only beside the release evidence.
- **Correction to version 9 and the audit:** the Redis password was not a
  live secret. It is the placeholder `null`; no Redis server is installed.
  The application key was the only current secret in those copies. **It has
  not been rotated**: `docs/runbooks/app-key-rotation-proposal.md`.
- **Storage:** 33% of the disk and 3% of inodes in use. Removed: the
  worktrees (2.2 GB), 649 old agent leftovers in `/tmp` (103 MB), root's npm
  download cache (943 MB). A detailed inventory is kept root-only on the
  server. Left for a decision: an editor's server installs, browser caches
  and agent session data in root's home (about 9 GB together), and the older
  backup series (14 GB). No container runtime is installed. Nothing in
  application storage, the database, sessions or idempotency claims was
  cleared.
- **Off-site backups:** planned, not configured. No approved private
  destination exists: `docs/runbooks/offsite-backup-plan.md`.

### NOT RUN, and other limits

- **Signed-in browser checks after the release**, on Retail, Dhiran and
  staging: both of the owner's sessions had expired (the lifetime is two
  hours) and the agent does not sign in. Measured instead: a cookie stored
  without `Secure` still carries its session when sent back; a request with
  no cookie gets another session; no signed-in user was active across the
  window, so nobody was interrupted and nobody's continuity was observed.
- The return-policy message and the Account link **seen in a browser**.
- Quick-bill creation exercised on production: not done, by instruction (no
  financial test writes). Staging and local only.
- The reconciliation command's deferral observed on production: it needs a
  provider timeout to happen.
- The suite on PHP 8.4; physical devices; a restore of this window's dump
  (it was read end to end, not restored).

### Seen in passing, not changed

- A shop's very first quick bill also creates its number counter; several
  first bills at the same instant collide there and the losers get a 500
  (nothing is booked). Found by the race probe's control.
- Quick-bill **update** also receives a key from the app and is not behind
  the middleware.
- `docs/runbooks/tenant-inventory-resolved.tsv` still lists the removed
  archive command's file.

### Pull requests

- **#2** (draft, base `main`, head `integration/jewelflows-takeover` at
  `896d70e`): unchanged by this batch. Nothing was pushed to that branch.
- This batch is on `fix/stabilization-20261008`, published, stacked on #2's
  head. It needs its own pull request **after #2 is merged**; this session
  cannot open one. `main` was not touched.

### Where the next task starts now

Same worktree, `/home/himanshu/Desktop/jewelflow-worktrees/jewelflows-takeover`,
branch **`fix/stabilization-20261008`**, at the commit that adds this
version, level with its `origin` branch. Production's commit `bcc336a…` is an
ancestor; the commits after it change only the release script, a staging
check and `docs/`.

## 11. Stabilization, second pass (version 11)

Same branch, `fix/stabilization-20261008`, now **draft pull request #3**
(base `integration/jewelflows-takeover`, stacked on #2). **Not everything is
closed: the list at the end of this section is what remains.**

### Deployed

| | Commit | When (UTC) | Maintenance |
|---|---|---|---|
| Production | `affb59c1e0e1344a4aea04cbf010f420bf33fea4` (from `bcc336a…`) | 09:41:03 to 09:41:07 | 4 s |
| Staging | the same | 09:38:14, 09:39:04, 09:40:03, 09:44:09 | 5 to 6 s each (release, return, release, a run of the final script) |

Manifest `c9e1e434a8510a69…`; assets tarball `9cb9a7d5847d7609…`, built by
`docs/runbooks/build-assets.sh`. Release script SHA-256 `a5c509ed9d85a457…`
for the production run. No migration. Suite on PHP 8.2 at the deployed
commit: 3,553 tests, 17,894 assertions, 0 failures, 1 skipped.

### 1. The first use of a number counter (`2f44a60`)

A shop's first quick bill, invoice, purchase or credit note creates the
counter row. Several first uses at once: all but one answered 500 and booked
nothing. Cause, in `BusinessIdentifierService::nextCounter()`: it caught the
unique violation and carried on, which PostgreSQL does not allow inside a
transaction (the failed statement aborts it). Now `ON CONFLICT DO NOTHING`;
only that conflict is absorbed, any other error still raises. The two
platform counters had the same pattern and the same fix.

Race probe (`tests/Concurrency/quick_bill_create_race.php`), every scenario
with measured overlap. A to C keep their warm-up; D to I are fresh shops:

| Scenario | Before the fix | After |
|---|---|---|
| D. fresh shop, six identical requests, one key | 1 bill | 1 bill, 1 payment |
| E. fresh shop, four requests, four keys | 1 × 201, 3 × 500 | 4 bills, 4 payments, 4 numbers |
| F. fresh shop, four requests, no key | 1 × 201, 3 × 500 | 4 bills, 4 payments, 4 numbers |
| G. six first invoice numbers | 1 issued, 5 failed | `INV-1001` to `INV-1006` |
| H. six first purchase numbers | 1 issued, 5 failed | `PUR-1` to `PUR-6` |
| I. two fresh shops, three credit notes each | 1 each | `CN-1` to `CN-3` in each |

Six sequential tests pin the numbering: a shop's own starting number, prefix
and suffix; other counters from one; each shop for itself; an existing
counter continued; a caller's transaction still usable; a missing shop still
raises.

**Quick-bill edit sent twice, measured:** the bill is identical afterwards
(same number, totals, one set of lines and payments: an edit replaces them).
The only lasting difference is a second audit entry. No financial duplicate;
nothing changed. A test pins it.

Seen by reading, not reproduced, not changed: the fiscal-year reset of the
invoice counter checks and resets without a lock, so two first invoices of a
new fiscal year could both reset it. It needs `year_reset` on and happens
once a year.

### 2. The asset build (`815293e`, `19f53ac`)

The stylesheet was generated partly from `storage/framework/views`, the
compiled-view cache of the machine doing the build. That cache had the
framework's own error and mail pages in it, compiled by local test runs.
**Of the class names a clean build lost, 137 are used only by those
framework templates, none of which loads this stylesheet; the rest are used
nowhere.** No rule naming a class the application writes was lost.

Tailwind now scans tracked sources only: `resources/views` (the
application's own pagination and error views included) and `resources/js`.
The second adds one rule the scripts needed and never had, the dimmed
backdrop of the confirmation dialog. Measured:

- two builds from clean exports with `npm ci`, and the working checkout:
  byte-identical tarballs;
- against the stylesheet deployed before: 154 rules gone, 2 added; none of
  the 154 names a class written in a template, a script or a PHP string;
- on production, signed in to Retail: 16 pages fetched (paginated lists
  among them), none uses a class that lost its rule; the backdrop class
  computes to `rgba(0, 0, 0, 0.45)`.

The release script's comparison with the deployed stylesheet stays as a
safeguard and refused this build until the removal was declared.

### 3. Signed-in acceptance

**Run, on production, in the owner's Retail session:**

| Check | Result |
|---|---|
| Signed in with `Secure` cookies | yes |
| The session across the 09:41Z release | signed in before the window, still signed in after it |
| Turbo navigation | two sidebar links followed without a new document (a marker on `window` survived) |
| Retail does not sign Dhiran or staging in | both asked for a log-in while Retail was signed in |
| Stylesheet change | see 2 |

**Pending, with the reason:**

| Check | Why not |
|---|---|
| The session across the change to `Secure` itself | no session was held across it; nothing was inferred from that |
| The return-policy message in a browser | the owner's Retail shop has a return policy, so its return form opens (seen; nothing submitted). It needs the synthetic staging shop: sign in at `https://staging.jewelflows.com/login` |
| Dhiran, and logging out of one product while the other stays in | not signed in: `https://dhiran.jewelflows.com/login` |
| The platform-admin Account link | not signed in: `https://jewelflows.com/admin/login` (or staging's) |
| Phone layout | the browser window cannot be resized by the agent; a physical phone is the owner's check |

### 4. APP_KEY rotation (`72539de`): corrected, not done

The first proposal was wrong: a rotation would not leave recognitions
"invalid", it would **revoke them for good** on first read. Tested.
`promotion:restamp-proofs` migrates them inside the window; a proof whose
owner changed is not revived. The lifecycle test goes from the old key to
its removal, after which nothing made under it is accepted. The proposal has
the order, the retirement date (31 days, for catalogue links) and the way
back at each stage. The command is released and inert: no previous key is
configured anywhere.

### 5. The release tooling, accounted for

- **Shared PHP-FPM.** One pool serves production and staging; every release
  of either reloads it. Seven reloads on 8 October, five of them caused by
  staging runs. Requests in flight finish; no 5xx after any of them in the
  shared log, which has no host field, so production and staging cannot be
  told apart in it. The script now says this and checks the other
  environment's commit, `.env`, config cache and assets byte for byte, and
  that it answers 200 after the reload.
- **Cron is not held.** Production's scheduler started every minute
  throughout (10 of 10 around the first window, 8 of 8 around this one).
  Staging has one job, every ten minutes; none started inside the
  interrupted 106-second window. **One did start two seconds before a later
  staging window**; no error was logged, and whether it was still running at
  the checkout is not known. The script now opens a window only when no
  scheduled command is running.
- **A way back.** `return` goes to an earlier commit of the batch with its
  own assets; rehearsed on staging (forward, back, forward).
- **Still true:** a release stopped by a gate stays in maintenance with cron
  running and scheduled commands skipped; a restore of a window's dump has
  not been rehearsed for this script (the dumps are read end to end).

### What remains

Pending checks: the five rows of 3; the suite on PHP 8.4; physical devices;
a restore of a stabilization dump.

Decisions for the owner: merge pull requests #2, then #3; the APP_KEY
rotation date; an off-site backup destination; retention of dumps and of the
older backup series; the tool data in root's home.

Open findings, not changed here: the fiscal-year reset race (above); POS 500
on an item with no price or metal type; three private files on the public
disk; `pageinspect`; the review lows of the security batch; the mobile items
(physical device, build 25, runtime policy, iOS).

Deferred by the owner: Product preferences placement; the policy questions
(quick-bill edit, loyalty expiry, shopfront); mobile builds.

## 12. Closure checks (version 12)

Same branch and pull request (#3). **Still not everything is closed: see the
list at the end.**

### Deployed

| | Commit | When (UTC) | Maintenance |
|---|---|---|---|
| Production | `6c2ac60050d7ac728bbe872e98a5b2d56f825a39` (from `affb59c…`) | 10:19:03 to 10:19:08 | 5 s |
| Staging | the same | 10:15:04, 10:16:04, 10:17:03 | 4 to 5 s each (release, return, release) |

One runtime path changed (`routes/mobile.php`). Assets unchanged (manifest
`c9e1e434…`). Release script SHA-256 `51e65c97ef12422c…`. Suite on PHP 8.2
at the deployed commit: 3,556 tests, 17,916 assertions, 0 failures.

### 1. A late retry of a quick-bill edit (`2501d5f`)

The earlier test sent the same edit twice in a row and supports only that.
The case that matters: edit A (key A), a different edit B (key B), then the
retry of A with key A.

| | Before | After |
|---|---|---|
| The bill after the retry | A's again: total 1500.00, line "Edit A item", payment 1500.00. **B was undone** | B's: total 2500.00, line "Edit B item", payment 2500.00 |
| The retry's answer | 200, applied as a third edit | 200, a replay of A's first reply |
| Audit entries for the three requests | 3 | 2 |

Fix: the edit route runs behind the idempotency middleware, after the
permission check, key optional. An edit with no key (an older build) is
applied as before; another shop's user gets 404 with or without a key. The
app already keeps its key across a network or server error and takes a new
one after a success. Which of two different edits should win is a separate
policy and is unchanged. Checked again on staging's deployed code.

### 2. A boundary for going back (`6c2ac60`)

> **Superseded in part by section 13.** The rule below let a return drop a
> route's retry protection when no claim for it remained. That exception was
> unsafe and is gone: a return target must keep both quick-bill protections,
> whatever the claim table holds. Nothing in this subsection permits a return
> to `affb59c` or `bcc336a` any more, on staging or on production, now or
> after the claims are pruned.

`return` accepted any ancestor with unchanged migrations and dependencies.
That is not compatibility: an idempotency claim says a request was carried
out, and a commit without the middleware on that route would carry a retry
out again. `return` now refuses, in its first gates:

- a target below `bcc336a…`, the first released commit of the batch;
- a target that drops the retry protection of a route the deployed commit
  protects **while a claim for that route, or an unresolved claim, remains**.

Claims are counted, never deleted. Rehearsed on staging:

| Rehearsal | Result |
|---|---|
| Return to `affb59c` with no quick-bill claim on staging | allowed: went back and forward again |
| The same after a synthetic shop left 1 create claim and 2 edit claims | **refused**: "2 claim(s) for it remain"; nothing touched |
| Return to `e49e611` | **refused**: below the boundary; nothing touched |
| After the two refusals | same commit, no maintenance file, 200, worker active, claims unchanged, no dump made, no PHP reload |

**Left on staging by this rehearsal, deliberately:** one synthetic shop
(`SYNTH-…`, deactivated) with two quick bills and three claims. The claims
are pruned 48 hours after they resolved. (The two sentences that stood here,
on when that return would be allowed, are withdrawn: section 13.)

### 3. Signed-in acceptance

| Check | State |
|---|---|
| Retail session across a release | **observed twice**: signed in before the 09:41Z and the 10:19Z windows, still signed in after each |
| Session across the switch to `Secure` cookies (09:04Z) | **not observed, and cannot be now**: no session was held across it. Recorded as a limitation; the live setting was not weakened to recreate it |
| Return-policy warning in a browser | **pending**: staging not signed in (`https://staging.jewelflows.com/login`); the owner's Retail shop has a policy |
| Platform-admin Account link in a browser | **pending**: not signed in (`https://jewelflows.com/admin/login`) |
| Dhiran and Retail: log out of one, the other stays | **pending** for this release: Dhiran not signed in. (Both directions were run on 8 October before the stabilization batch: section 8.) |
| Narrow screen, signed in | **pending**: the agent's clicks do not reach the owner's browser window while it is hidden, so no narrow window could be opened in it |
| Narrow screen, pages without a log-in | **run in viewport emulation** (375 × 812, touch reported), not on a phone: Retail log-in, landing page, Dhiran log-in, staging log-in. No horizontal overflow and no element past the right edge on any. Dhiran's log-in button is 42 px high, Retail's 52 |

### 4. Restore test

A fresh dump of production, taken at 10:09:26Z at `affb59c` (after the
releases of the day), restored with the established isolated procedure
(`deploy-takeover-production.sh restore-check`, the released revision): own
PostgreSQL on a tmpfs, throwaway user, no network. The dump and the live
fingerprint were taken on **one exported snapshot**, so the comparison is
exact although the site stayed up.

| | Live | Restored |
|---|---|---|
| Tables / holding rows | 148 / 90 | 148 / 90 |
| Rows in all | 5,444 | 5,444 |
| Relations / triggers / migrations | 802 / 42 / 384 | 802 / 42 / 384 |
| Tables whose count or content differs | — | **none** |

Dump: `/root/stabilization/restore-test-20261008T100925Z/jewelflow.dump`,
SHA-256 `f734ea5afa86040a…`, root only. Every earlier recovery copy is
untouched. The nightly archives made by the backup package were **not** the
thing tested: none exists yet from after the releases.

### 5. APP_KEY: not rotated

The proposal now says plainly that a new current key does not end the
exposure: for as long as the old key is kept as previous, whoever holds it
can still sign links the application accepts and read cookies they have
captured. Only retirement ends that. It gives the two dates to choose
between (31 days later, or the rotation day itself with everyone signing in
again and sent links breaking) and what each costs.

### What remains

Pending checks (the owner's sign-in or hardware): the four pending rows of 3;
a physical phone; the suite on PHP 8.4; a restore test of a nightly archive
once one exists from after the releases.

Decisions for the owner: merge pull requests #2, then #3; the APP_KEY
rotation and retirement dates; an off-site backup destination; retention of
dumps and of the older backup series; the tool data in root's home.

Leads and open findings, **not proven and not resolved here**: the
fiscal-year reset of the invoice counter checks and resets without a lock
(read in the code, never reproduced); POS 500 on an item with no price or
metal type; three private files on the public disk; `pageinspect`; the
review lows of the security batch; Dhiran's 42 px log-in button; the mobile
items (physical device, build 25, runtime policy, iOS).

Limits of the tooling that stand: one PHP-FPM pool, so every release of
either environment reloads the other's workers; cron is not held, so a
release stopped by a gate skips scheduled commands until it is resumed.

Deferred by the owner: Product preferences placement; the policy questions
(which quick-bill edit wins, loyalty expiry, shopfront); mobile builds.

## 13. Review corrections integrated (version 13)

Two corrections prepared in review (pull request #4, commit
`4f042e6dcec03b1f56f4417f96996470b50094a4`, parent `a1703fe…`) were
fast-forwarded into `fix/stabilization-20261008`; one commit of mine follows
(`1bc2f0e`). **No application change and no deployment: production and
staging still run `6c2ac60…`.** Only the release script and tests changed.

### 1. Return never drops quick-bill retry protection

Section 12 refused such a return only while a claim remained. **That was
wrong.** The claim table is read during preflight, while the site still
accepts requests: a claim can be made between the count and the maintenance
window, and an empty table proves nothing. Now:

- a return target must carry the idempotency middleware on **both**
  quick-bill create and quick-bill edit, regardless of how many claims exist;
- if the target's route file cannot be read, the return is refused;
- the boundary `bcc336a…` and every other gate stay.

So from the deployed `6c2ac60` the one earlier commit that is eligible is
`2501d5f` (the edit fix itself). `affb59c` and `bcc336a` are not, and will
not become so when claims are pruned.

| Check | Result |
|---|---|
| `python3 tests/Runbooks/stabilization_return_test.py` (the script's own gate, run against temporary git history: no deployment, database or service) | 8 passed, 0 failed: refuses losing edit protection and losing create protection with an empty table, refuses with claims, refuses a missing route file, refuses below the floor, refuses the current commit; allows a compatible ancestor with and without claims; the claim table is never queried |
| `bash -n` on the script | clean, locally and on the server |
| The operator copy asked to return staging to `affb59c` | refused in preflight; same commit, no maintenance file, 200, the 7 claims kept |
| Production | not asked to return anywhere |

**Operator copy:** `/root/stabilization/incoming/deploy-stabilization.sh`,
SHA-256 `10f4bf61fadf49d60315af5ac9d2016ae888078cf057fc719580c458bb5fa14a`,
identical to the integrated file. The previous copy (`51e65c97ef12…`) and my
claims-based rehearsal helper are kept beside it under `superseded/` and are
not to be used.

### 2. The late-retry test proves what it says

The assertion I wrote compared `quick_bill.total_amount`, a path that does
not exist: both sides were null. The review's version asserts the real one.
Run on the local disposable test database (`jewelflow_testing` on
127.0.0.1, confirmed before the run), 4 tests, 33 assertions, all passing:

| Claim | Assertion |
|---|---|
| Edit A is answered with a ₹1,500 receipt | `quick_bill.totals.total_amount` is 1500 |
| Edit B leaves a ₹2,500 bill with B's line and payment | stored total `2500.00`, line "Edit B item", payment `2500.00` |
| The retry of A returns A's original receipt while B stays stored | the whole receipt equals A's first, value for value and type for type; the stored records equal those after B |
| Two edit audit entries | exactly 2 |

One thing failed when the review's version was first run, and was fixed
without loosening it: the stored reply comes back from a `jsonb` column with
its own key order, so the receipts are compared with the key order set
aside. Related suites (quick bill, idempotency): 113 tests, 0 failures.

### 3. Browser acceptance: still pending, and why

At the time of this pass **no session was signed in** on Retail, Dhiran,
staging or either admin console (the Retail session of section 12 had
expired). The agent does not sign in.

What could be established without one: real mouse input now reaches the
owner's browser (the click event was trusted), and a real 390-pixel-wide
window opened on the Retail log-in page: no horizontal overflow, nothing
past the right edge. That is a narrow desktop window, not a phone and not
touch.

| Pending check | Sign-in needed |
|---|---|
| Return-policy warning (toast seen, text, clearance) | `https://staging.jewelflows.com/login`, a staging shop with no return policy |
| Platform-admin Account Security link | `https://staging.jewelflows.com/admin/login` or `https://jewelflows.com/admin/login` |
| Dhiran and Retail: log out of one, the other stays | `https://jewelflows.com/login` and `https://dhiran.jewelflows.com/login` |
| Signed-in narrow-screen navigation, dialogs and toast clearance | any one of the above, kept signed in |

### What remains

Pending checks: the four rows above; a physical phone; the suite on PHP 8.4;
a restore test of a nightly archive from after the releases.

Decisions for the owner: pull requests #2 and #3; the APP_KEY rotation and
retirement dates; an off-site backup destination; retention of dumps and of
the older backup series; the tool data in root's home.

Leads and open findings, unchanged and **not proven or resolved**: the
fiscal-year reset of the invoice counter (read in the code, never
reproduced); POS 500 on an item with no price or metal type; three private
files on the public disk; `pageinspect`; the review lows of the security
batch; Dhiran's 42 px log-in button; the mobile items.

Deferred by the owner: Product preferences placement; key rotation; backup
deletion; the policy questions; mobile builds.

## 14. Browser acceptance, run in the owner's signed-in sessions (version 14)

The owner signed in to staging (a shop owner), Retail, Dhiran and the
production admin console. The agent typed no credential. **No deployment and
no code change: production and staging still run `6c2ac60…`.** Nothing was
submitted on production; on staging one confirmation dialog was opened and
cancelled.

How each result was obtained is stated, because it differs. "Real click" is
a mouse event the browser marks as trusted, in a window the browser reports
as visible. "By script" is a click or navigation issued from the page's own
JavaScript. The narrow window is a real Chrome window 390 pixels wide: not a
phone, no touch.

### 1. Return-policy warning (staging)

The staging shop that was signed in has a return policy, so its return form
opens and nothing is shown. To see the warning, that shop's
`return_policy_configured_at` was set to NULL **on staging only** for the
length of the check and then put back to its exact saved value (compared
after; the saved value is kept root-only beside the release evidence). No
other row changed; no return was created.

| | Desktop, 1920 px, **real click** on the invoice's own "Return" link | Narrow window, 390 px, by script |
|---|---|---|
| Lands on | Settings, `tab=return-policy`, same document | the same |
| Text | "Please set up your return policy before processing returns. This takes about 1 minute." | the same |
| Painted | opacity 1, on top, white on `rgb(190, 18, 60)` | opacity 1, on top |
| Where | 420 × 66 at the bottom right, inside the viewport | 366 × 66 at the top, 12 px from each side, inside the viewport |
| Controls underneath it | — | **none** |
| Cleared | 8.4 s after the click | 8.7 s after the visit |
| Flash tags | warning consumed, no error tag | the same |

After the value was restored the same link opens the return form again.

### 2. Platform-admin Account Security link (production)

**Real click** on the sidebar's "Account / Security" entry (the last of 14,
220 × 42, on top): opens `/admin/account`, titled "Account Security", the
entry marked active, with the email and mobile forms. Nothing submitted.

### 3. Logout isolation

Signed out of Dhiran with its own "Sign out" button (by script; the window
was hidden at that moment). Afterwards: Dhiran asks for a log-in; **Retail
is still signed in** (dashboard and Settings open); the production admin
console is still signed in; staging is still signed in.

The other direction (out of Retail, Dhiran stays) was **not run in this
pass**: it needs Dhiran signed in again. It was run on 8 October before the
stabilization releases (section 8).

### 4. Narrow screen, signed in (staging, 390 px window)

The window was opened by a real click; inside it the steps were by script.

| | Result |
|---|---|
| Dashboard | 390 × 787, no horizontal overflow, nothing past the right edge; the sidebar is off-canvas |
| Navigation | the menu button opens the drawer (sidebar from −278 px to 0, `aria-expanded` true); 18 entries, none shorter than 44 px, all inside the width; "Invoices" navigates in the same document and the drawer closes; no overflow on the new page |
| Confirmation dialog (delete a category, **cancelled**) | shown; backdrop `rgba(0, 0, 0, 0.45)` over the whole window; panel 358 × 211, inside the viewport, on top, focus inside; buttons 44 px high; Cancel closes it, the page and the form are unchanged, the category still exists |
| Toast clearance | see 1: at the top, clear of every control |

Seen and not changed: the menu button is 32 × 32; a warning has the same
red as an error (the known "toast ignores its tone" finding).

### Limits of this pass

- Not a phone: no touch, no mobile browser.
- The narrow-window steps and the Dhiran sign-out were issued by script.
  The owner's browser window kept being covered, during which real input
  does not reach it and animations do not run; the measurements above were
  taken while it was visible, except where marked.
- Session continuity across the switch to `Secure` cookies was never
  observed and is not claimed.

### What remains

Pending checks: a physical phone; Retail-out-while-Dhiran-stays on the
current release; the suite on PHP 8.4; a restore test of a nightly archive
from after the releases.

Decisions for the owner, leads and deferred items: unchanged from section 13.

## 15. Closure (version 15)

This is the last entry for the two batches. It was written **before** the
final alignment on purpose, so that no further documentation commit has to
follow it: the commit that adds this section is the release commit, and the
SHAs observed after alignment are in the closing report of the session, not
in this file.

### Where the next task starts

| | |
|---|---|
| Branch | **`main`** |
| Commit | the one that adds this section (`git rev-parse origin/main`) |
| Worktree | `/home/himanshu/Desktop/jewelflow-worktrees/jewelflows-takeover`, on `main` |
| Deployed | production and staging run that same commit. Their application code is that of `6c2ac60050d7ac728bbe872e98a5b2d56f825a39`, the last commit that changed anything the application runs; everything after it is documentation, tests and operator tooling |

Cut the next task's branch from `main`. `integration/jewelflows-takeover`,
`fix/stabilization-20261008` and `fix/stabilization-review-20261008` are
finished and merged; do not start from them.

### How the pull requests were integrated

- **#2** (`integration/jewelflows-takeover`, 36 commits): `main` was an
  ancestor of it, so `main` was fast-forwarded. History kept, nothing
  rewritten, nothing forced.
- **#3** (`fix/stabilization-20261008`, stacked on #2): fast-forwarded into
  `main` in the same way. The command-line login this work has can only read
  the repository, so the pull request's base could not be changed to `main`
  through GitHub; its base branch was fast-forwarded to the same commit
  instead, which is how GitHub came to show it as merged. The result is the
  one asked for: both batches on `main`, each commit once.
- **#4** (the review corrections, `4f042e6`): fast-forwarded into the
  stabilization branch earlier; it is in `main` exactly once.
- Reviewed before integrating: 63 commits, one author, no merge commit, no
  required check and no branch protection on the repository. Nothing the
  application runs differs between the last tested and deployed commit
  (`6c2ac60`) and the final one, so the suite of that commit stands: PHP 8.2,
  3,556 tests, 0 failures. The checks that the later commits touch were run
  again: the return guard (8 of 8), shell syntax of the three scripts, and
  the quick-bill edit retry tests (4 tests, 33 assertions).

### How the servers were aligned

The deployed trees differed from the final commit only under `docs/` and
`tests/`. `docs/runbooks/sync-release-docs.sh` moves a checkout across such
a difference and nothing else: it refuses if any other path differs, takes
no maintenance window, installs, rebuilds, reloads and restarts nothing, and
afterwards requires `.env`, the config cache, the built assets, the worker's
process and PHP-FPM to be exactly what they were. Staging first, then
production. The operator copy of `deploy-stabilization.sh` on the server is
the corrected one (SHA-256 `10f4bf61fadf49d6…`) and keeps both quick-bill
retry protections.

### Acceptance

| Check | State |
|---|---|
| Return-policy warning, admin Account Security link, Dhiran sign-out leaving Retail signed in, signed-in narrow window | done on 8 October: section 14 |
| **Out of Retail while Dhiran stays signed in, on this release** | **pending at the time of this record**: Dhiran was not signed in. It needs `https://dhiran.jewelflows.com/login` (Retail was signed in). If it was run later in the same session, the closing report says so |
| A physical phone | not run. Everything narrow was a 390-pixel desktop window or viewport emulation: no touch, no mobile browser |
| Session continuity across the switch to `Secure` cookies (8 October, 09:04Z) | never observed, and cannot be now. Sessions were seen to survive two later releases; that is a different thing |
| Restore test of a nightly archive made after the releases | pending: the newest archive was made at 18:30Z on 7 October, before them. A fresh post-release dump was restore-tested instead (section 12); it does not stand in for the archive |
| The suite on PHP 8.4 | not run for the stabilization commits |

### Cleaned up

- Local branches deleted after an archive tag was verified on the same
  commit: `fix/returns-flash-channel` (its purpose is delivered by `7e78687`
  in another way), `design/customers-codex-polish` (the Account link is
  delivered by `76dd1ef`; its hover fix no longer applies),
  `fix/masters-safety-guards` (a rejected alternative).
- After integration: the local and remote branches of the three merged pull
  requests, and the spare `main-integration` worktree.
- 19 `archive/…` tags and `release/production-20261008` are kept; the
  archive tags exist only in the local repository.

### Deliberately kept

- On the server, root only: every release dump and pre-release copy under
  `/root/takeover-production`, `/root/takeover-staging` and
  `/root/stabilization`; the archives of the old agent worktrees; the storage
  inventory; both nightly backup series.
- On staging: one deactivated synthetic shop (`SYNTH-…`) with its
  idempotency claims.
- Locally: the owner's checkout and its untracked notes; the review packets
  and evidence folders; the databases `jewelflow_test_mobile_returns` (in
  use) and `jewelflow_test_pre_merge` (purpose unconfirmed); the mobile
  repository, untouched.

### The one backlog

**Deferred by the owner (decisions, not started):** the APP_KEY rotation and
the date the old key is retired; an off-site backup destination; retention
or deletion of dumps and of the older backup series; the placement of the
Product preferences page; the policy questions (which of two quick-bill
edits wins, loyalty expiry, what the shopfront publishes); mobile builds;
the tool data in root's home on the server.

**Pending checks:** the three rows marked pending or not run above, and a
physical phone.

**Leads and open findings, not proven and not resolved:** the fiscal-year
reset of the invoice counter checks and resets without a lock (read in the
code, never reproduced); POS 500 on an item with no price or metal type;
three private files on the public disk; `pageinspect`; the review lows of
the security batch; the 32 × 32 mobile menu button and Dhiran's 42 px
log-in button; a warning toast in an error's red; the mobile items
(physical device, build 25, runtime policy, iOS).

**Limits of the tooling that stand:** one PHP-FPM pool serves both
environments, so a release of either reloads the other's workers; cron is
not held during a release window.

