# Takeover release on production — procedure

Script: `docs/runbooks/deploy-takeover-production.sh`. Check script:
`tests/Production/verify_takeover_production.php`.

This release is complete. The script and check are kept as the record of how it
was done; the optional cross-product feature they were written around has since
been removed from the application, so do not re-run them as they stand.

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

## 2. One window for everything

Retail (`jewelflows.com`, `www.jewelflows.com`) and the mobile API are one
application, one database and one php-fpm pool. `artisan down` answers 503 on all of them at once.

- **Sessions** are rows in the database and are not touched: owners and staff
  stay signed in. A request made during the window gets 503 with
  `Retry-After: 30`; the mobile app sees the same.
- **Queue worker** `jewelflow-production-ops-alerts` (queue `ops-alerts`) is
  stopped for the window and started again **after** `up`, never before it.
  Alerts raised meanwhile wait in the `jobs` table. A worker run with
  `--max-time` that finds the application in maintenance leaves after its
  first pause with status 0 (Laravel's `Worker::pauseWorker` asks
  `stopIfNecessary` without a start time, so the limit counts from the
  machine's boot), and the unit's `Restart=always` brings it back five
  seconds later, over and over until `up`. That happened in the staging
  release (started 15:51:03Z, gone 15:51:06Z, restarted 15:51:11Z) and nothing
  noticed, because "did not fail" was all that was asked. The script now
  requires the worker to be active with the same main process ten seconds
  apart, and stops with production up if it is not.
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
  the maintenance bypass, on both hosts.

## 3. What is left exactly as it is

The script compares before and after, and stops if any of these moved:
every `.env` line; every configuration
value in effect (hashed per section); ownership and mode of `.env`, the config
cache and the assets; every untracked file in the tree, by path and by a hash
of its content (not git's summary of them, which folds a directory into one
line and unfolds it when the target tracks a file in it); the scheduler file;
nginx's site file, including its five private-storage refusals (`/storage/kyc/`,
`signatures/`, `karigar-invoices/`, `purchases/`, `repairs/`), each probed for
403 on every production host before, during and after; staging's commit, state and
configuration; every row of every existing table (count and content hash) and
the full schema text outside the fifteen relations the migration adds.

Two things are compared more narrowly, each for a stated reason:

- **`sessions`.** The script's own page requests through the maintenance bypass
  open guest sessions, so after the first of them that table cannot be equal
  to its earlier self. It is then checked row by row instead. Understood: a
  guest row added since the window opened; a row the session garbage collector
  removed because it had expired. Refused: a row that existed and changed, a
  new row belonging to a user, a new row older than the window, an unexpired
  row gone. Every other table is still compared whole.
- **Untracked files in the target's way.** Before the window the script
  refuses a release if any untracked file sits where the target has a path of
  its own (the same path, a file where the target needs a directory, or inside
  a directory where the target has a file). Git would refuse that checkout;
  nothing is ever forced or overwritten.

## 4. Steps

1. Put the bundle of the target commit and the assets tarball in
   `/root/takeover-production/incoming/` with a copy of the script.
2. `deploy-takeover-production.sh preflight <deployed> <target> <bundle> <assets> <sha256>`.
   It changes nothing in the application, its database, repository,
   configuration or services: the bundle is unpacked into a throwaway
   repository, a dump is taken, restored in isolation, compared and deleted.
3. In the approved window, the same arguments with `release`.
   Order: quiesce → fresh dump, restored in isolation and compared → checkout
   and autoload as `dev` → assets → the config cache →
   the one migration → caches → proof of fresh code on both hosts →
   (synthetic check, if approved) → scheduler back → `up` → worker started and
   seen steady → smoke.
4. A gate that fails before the checkout puts everything back by itself. A gate
   that fails after it leaves production in maintenance and says where the
   evidence is. See 6.

## 5. The synthetic check inside the window

It was an optional step, run only with its own yes, that exercised nothing but
the removed cross-product feature. There is nothing left for it to check; do
not approve it. Skip the "(synthetic check, if approved)" step in section 4.

## 6. Recovery

Three modes of the script, all run with the stopped release's evidence
directory. Each checks first and changes nothing if it refuses.

| Mode | Does | Refuses when |
|---|---|---|
| `resume <deployed> <target> <evidence dir>` | finishes forward: configuration, the migration checks, caches, proof, `up`, worker, smoke | not in maintenance; HEAD is not cleanly the target; the migration is not applied; an untracked file changed; a business row or a user's session changed since quiesce |
| `baseline <deployed> <target> <evidence dir>` | returns to the deployed commit, its assets, `.env` and configuration, proves the earlier release is what is served, `up` | production is not in maintenance, or was up in between (maintenance newer than that run's backup); the tracked tree is dirty; an untracked file changed |
| `tree-check <deployed> <target> <evidence dir>` | nothing: it reads and reports (see "A checkout cut short") | — |

Which one:

- **A gate fails before the checkout.** The script has already put production
  back. Nothing to recover.
- **A gate fails, or the run dies, after the checkout, and the migration is
  applied.** Fix what stopped it, then `resume`. Or `baseline`.
- **The run dies after the checkout and before the migration.** `resume`
  refuses (nothing to finish). `baseline`.
- **Data is damaged and the site never left maintenance.** Full restore of the
  window's dump, last resort, with the owner present: `restore-check` the dump
  first; dump the damaged state too; restore into a new database, fingerprint
  it against the restore check, and only then swap it in.

### A checkout cut short

If the run dies *inside* `git checkout`, the tree is left between two
commits: HEAD and the index still at the deployed commit, some files already
the target's, files the target adds lying there untracked, and often
`.git/index.lock`. `resume` and `baseline` both refuse such a tree and touch
nothing. **Neither discards anything, and nothing here may be solved by a
forced checkout, a reset or a clean:** those would also destroy a change
somebody made by hand, and nobody would know.

1. Stay in maintenance. `pgrep -x git` must print nothing.
2. `deploy-takeover-production.sh tree-check <deployed> <target> <evidence dir>`.
   It changes nothing. For every tracked path that differs from HEAD and every
   untracked file that was not there before the release, it says whether the
   file is, byte for byte, the other commit's version.
3. **If it says STOP** (a path that is neither version, or a recorded
   untracked file changed or gone): restore nothing, remove nothing. Copy those
   files aside, find out what they are, and decide by hand. This is the case
   the refusal exists for.
4. **If every path is the other commit's version**, the cut-short checkout is
   all there is in the tree, and those bytes are in the repository. It writes
   the exact commands into its evidence directory (`commands.sh`): remove the
   left-over lock, `git restore --source=HEAD --staged --worktree --` those
   paths, remove exactly the added files, and a final `git status` that must
   print nothing. It runs none of them. Read the list, then run them yourself.
5. The tree is now clean at the commit HEAD names. `baseline` (HEAD is the
   deployed commit) or `resume` / `baseline` (HEAD is the target).

### What has been rehearsed, and what has not

Rehearsed on 8 October in an isolated disposable copy on the server (see the
handoff for how it is isolated and what stands in for what), with the script
byte for byte as committed:

- the whole release, uninterrupted, with the synthetic check;
- a run killed (SIGKILL) after the migration, finished by `resume`;
- a run killed after the migration, `baseline` refused while one preference
  row existed and changing nothing, permitted once the tables were empty, and
  a second `release` over the tables left in place;
- a run killed before the migration: `resume` refused, `baseline` returned,
  every table identical to before;
- `resume` refused after a business row, a signed-in user's session, or an
  untracked file had been changed, and passing once each was put back;
- a cut-short checkout: both modes refusing, `tree-check` saying STOP for a
  hand-edited file, then classifying, the commands it wrote run by hand,
  `baseline` returning.

Also rehearsed earlier: the staging form of `resume` (7 October, on staging);
the isolated restore (staging dump; production dump, in the preflights).

**Not rehearsed:**

- Any of this on production itself, on production's data, or under the real
  service manager (a stand-in ran the worker with the unit's own command line
  and restart policy).
- SIGKILL landing inside `git checkout` itself. The state it leaves was built
  by hand, in the form where the index has not been written yet. The form
  where git had already written the index is handled by the same commands
  (`--staged --worktree`) but was not produced.
- `tree-check` and `baseline` with HEAD at the target and a dirty tree (a
  checkout cut short on the way *back*).
- A full database restore.

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

- Guest, by the agent: both hosts at phone and desktop widths — landing
  page, log-in and register pages, no console error.
- Signed in, by the owner (the agent does not sign in to production): one
  Retail owner. Stock → Categories,
  Tag Printing, Reorder Alerts and back; Invoices → Returns / Exchange and
  Open POS; a category save, a duplicate name, a delete; where the message
  sits on a phone.
