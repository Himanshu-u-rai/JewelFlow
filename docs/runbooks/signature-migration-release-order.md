# Release order for the disk-column and signature-relocation schema changes

Findings S3-02, S3-03, S3-04 (attachment/signature disk recording).

**Status: PROPOSED. Nothing here has been executed.** No migration in this set
has run anywhere but the local `jewelflow_testing` database. Applying any of it
to production or staging needs explicit approval.

Last observed deployed baseline: `018b3d810e37d534f498033ab582ee41f3197c27` (observed on the server 2026-09-20T18:36:08+00:00; not re-observed since)
(authored 2026-09-15, subject "Stop the read-only KPI calling a lapsed shop
'unattributed'"). Verified as a local git object on 2026-09-21T00:37+05:30.
That is a check of the SHA I was *given*, not an observation of what is running
on the servers. Each phase has its own drift check (§ Drift checks) — run the
one for the phase you are about to execute, immediately before it.

---

## The mistake this document exists to correct

I previously described the three disk-column migrations as "additive" and
treated that as meaning they were safe to apply while the deployed application
kept serving. That inference was wrong.

Each of the three did three things in one file: add a nullable disk column,
backfill it, and add a both-or-neither CHECK constraint. The column and the
backfill are genuinely additive — the old code neither reads nor writes the new
column, and a NULL is a state it already produces. The **constraint is not**. It
forbids a row that has a path and no disk, and writing exactly that row is what
the baseline does on every file upload:

| Baseline writer | Line | Writes |
|---|---|---|
| `SettingsController::update()` | 526-527 | `digital_signature_path`, never `_disk` |
| `SettingsController::update()` | 528-530 | nulls the path on removal, leaves `_disk` behind |
| `StockPurchaseController` | 140, 354 | `invoice_image` only |
| `KarigarInvoiceService` | 49, 114 | `invoice_file_path` only |

So applying the constraint before the new code is live turns every file upload
on the running site into a database error, and every signature removal too.

This is demonstrated, not argued: `tests/Feature/Security/DiskColumnReleaseOrderTest.php`.

---

## Phases

### Phase 1 — EXPAND (safe while the baseline is still serving)

| Migration | Adds |
|---|---|
| `2026_09_15_120000_add_invoice_file_disk_to_karigar_invoices.php` | `karigar_invoices.invoice_file_disk` + backfill |
| `2026_09_15_140000_add_invoice_image_disk_to_stock_purchases.php` | `stock_purchases.invoice_image_disk` + backfill |
| `2026_09_16_120000_add_digital_signature_disk_to_billing_settings.php` | `shop_billing_settings.digital_signature_disk` + backfill |
| `2026_09_20_120000_create_signature_relocations_table.php` | new `signature_relocations` table |
| `2026_09_21_120000_create_invoice_payment_claims_table.php` | new `invoice_payment_claims` table (S3-07b). Must precede the code: code without the table refuses keyed payments with 503 (handoff §6) |
| `2026_09_23_120000_add_response_headers_to_idempotency_keys.php` | nullable `idempotency_keys.response_headers` (S3-09e) |
| `2026_09_24_120100_add_notification_outcome_to_report_exports.php` | nullable `report_exports.notified_at`, `notification_error` (S3-17). Must precede the code, which records every delivery outcome there |
| `2026_09_24_130000_add_expires_lot_id_to_loyalty_transactions.php` | nullable, unique, self-referencing `loyalty_transactions.expires_lot_id` (S3-16). Must precede the code: a reversal reads it. Validating the new key scans the table and the unique index is built in place, so writes to `loyalty_transactions` wait for both; not measured on production-sized data |

All eight are nullable-column / new-table additions. The baseline neither reads
nor writes any of them. Adding a column fires no row trigger, so the
append-only `loyalty_transactions` guard is not involved.

Run them from a checkout of the release SHA that is **not** the serving tree,
one file per command, in the order above — the form rehearsed in handoff §6a.
Naming each file is what keeps the contract from running early:

```bash
php artisan migrate --force --pretend --path=database/migrations/<file>.php   # prints the SQL, writes nothing
php artisan migrate --force --path=database/migrations/<file>.php
```

The backfills record `'public'` for rows that already hold a path, because that
is where those bytes physically are. **This is a truthful relabel of the status
quo, not a remediation.** The files remain on the public tree until the
separately-approved relocation procedure runs.

### Phase 2 — APPLICATION

Deploy the new application code to every serving node. Until this completes,
Phase 2b and Phase 3 must not run. `LOYALTY_EXPIRY_ACTIVE_FROM` stays unset:
loyalty expiry activates only by its own decision (handoff §0a, S3-16).

### Phase 2b — NOTIFICATIONS TABLE (only after Phase 2 is live everywhere and D2 is clean)

| Migration | Adds |
|---|---|
| `2026_09_24_120000_create_notifications_table.php` | Laravel's `notifications` table (S3-17); skipped if one exists, and its `down()` never drops it |

**Why after the code, not in Phase 1.** The baseline stores each queued export
at a path any two exports finishing in the same second share (S3-18). While no
`notifications` table exists, the baseline marks each queued export `failed`
at its notification, after the file is written, and the download route refuses
a `failed` export — during the rollout window, that is what keeps a shared
file from being served by the baseline. Created while the baseline serves, the
table would let those exports finish `done` and make shared files downloadable
in the window. The new
code stores one directory per export and survives the missing table (the
delivery failure is recorded and the export stays `done` —
`ExportNotificationDeliveryTest`).

After applying: `php artisan reporting:notify-export` lists the unexpired
finished exports not recorded as notified; `--send` delivers them, without
regenerating anything. If D0 found a `notifications` table already present,
the baseline's queued exports can finish `done` in the current configuration,
so shared paths can be served now: record it with D0. What happened before —
whether any file was shared, notified, downloaded or disclosed — is a separate
set of questions with their own evidence (handoff R10); the table's presence
answers none of them.

### Phase 3 — CONTRACT (only after Phase 2 is live everywhere and D2b is clean)

| Migration | Adds |
|---|---|
| `2026_09_20_130000_add_disk_column_check_constraints.php` | all three both-or-neither CHECK constraints |

This migration:

1. **Reconciles first.** The window between Phase 1 and Phase 3 is one in which
   the old code keeps writing rows the constraint will reject — in both
   directions (path without disk on upload, disk without path on removal). The
   Phase 1 backfill ran *before* those rows existed. Without a second sweep,
   `VALIDATE CONSTRAINT` aborts the deploy on exactly the rows the plan told the
   old code it was free to write. Pinned by T-07.
2. **Adds each constraint `NOT VALID`, then `VALIDATE`s it, with explicit
   transaction boundaries (XR-04).** Per table, one short transaction takes
   `SHARE ROW EXCLUSIVE` (writes wait, reads continue), reconciles, and adds
   the constraint `NOT VALID` — `ACCESS EXCLUSIVE` only for the catalogue change,
   released at that transaction's commit. `VALIDATE` then runs as its own
   statement under `SHARE UPDATE EXCLUSIVE`, blocking neither reads nor writes.
   **Correction:** before XR-04 the migration ran inside the transaction
   Laravel's migrator wraps around `up()` on PostgreSQL, so every
   `ACCESS EXCLUSIVE` lasted to the end of the whole migration. Measured by
   `tests/Rehearsal/contract_migration_locks.php`: the finished tables were
   unreadable and unwritable while the migrator worked on the next one. A
   migration interrupted part-way leaves finished tables constrained and the
   rest untouched; re-running `migrate` completes it (measured).

Run it the same way: `php artisan migrate --force --path=database/migrations/2026_09_20_130000_add_disk_column_check_constraints.php`.

The `VALIDATE` step is load-bearing and easy to lose: a migration that adds the
constraint and skips it reports a **successful deploy** while permanently
exempting every row written during the expand window. T-06 asserts
`pg_constraint.convalidated`, which is the only signal that separates the two.

---

## Drift checks — one per phase

**Correction.** An earlier revision had a single check: the server's `HEAD`
equals `018b3d8` before any step. That is wrong for Phase 3, which may run only
after every node has moved **off** `018b3d8`. Applied literally it blocks
Phase 3; skipped, it checks nothing. Each phase has its own expected state. On
any mismatch: stop, change nothing by hand, and re-derive the plan from what
was observed.

The ten branch migrations, for the queries below:

```sql
-- :ten
('2026_09_15_120000_add_invoice_file_disk_to_karigar_invoices',
 '2026_09_15_140000_add_invoice_image_disk_to_stock_purchases',
 '2026_09_16_120000_add_digital_signature_disk_to_billing_settings',
 '2026_09_20_120000_create_signature_relocations_table',
 '2026_09_21_120000_create_invoice_payment_claims_table',
 '2026_09_23_120000_add_response_headers_to_idempotency_keys',
 '2026_09_24_120100_add_notification_outcome_to_report_exports',
 '2026_09_24_130000_add_expires_lot_id_to_loyalty_transactions',
 '2026_09_24_120000_create_notifications_table',
 '2026_09_20_130000_add_disk_column_check_constraints')
```

### D0 — before Phase 1

| Check | Expected |
|---|---|
| `git -C /var/www/jewelflow rev-parse HEAD` on **every** serving node | `018b3d810e37d534f498033ab582ee41f3197c27` |
| `git -C /var/www/jewelflow status --porcelain --untracked-files=no` | empty |
| `php artisan migrate:status --pending`, from the serving tree | nothing pending: every `018b3d8` migration is applied |
| `select migration from migrations where migration in :ten` | 0 rows |
| `select to_regclass('signature_relocations'), to_regclass('invoice_payment_claims')` | both NULL |
| `select table_name, column_name from information_schema.columns where (table_name, column_name) in (('karigar_invoices','invoice_file_disk'), ('stock_purchases','invoice_image_disk'), ('shop_billing_settings','digital_signature_disk'), ('idempotency_keys','response_headers'), ('report_exports','notified_at'), ('report_exports','notification_error'), ('loyalty_transactions','expires_lot_id'))` | 0 rows — nothing added by hand |
| `select to_regclass('notifications')` | **record the answer — the current configuration only, not a gate.** It says nothing on its own about the past: a table present now may postdate the exports in question, and an absent one may have existed before. Whether exports finished, whether a notification was stored, whether a file was downloaded and whether anything was disclosed are separate questions with separate evidence — `report_exports` status and error text, `notifications` rows naming an export, the web server's access log (handoff §0d Part 1, R10) |
| `git rev-parse HEAD` in the release checkout | the SHA the reviewer approved |
| each `--pretend` output | only the DDL and backfills of that file |

### D1 — before Phase 2

| Check | Expected |
|---|---|
| serving nodes' `HEAD` | still `018b3d8` — nothing else deployed meanwhile |
| `select migration from migrations where migration in :ten` | exactly the eight Phase 1 names; the notifications table and the contract absent |
| `select conname from pg_constraint where conname in ('shop_billing_settings_digital_signature_disk_check', 'karigar_invoices_attachment_disk_check', 'stock_purchases_invoice_image_disk_check')` | 0 rows |
| the connection the application will **actually** use (XR-02) — see below | the effective endpoint is PostgreSQL itself, or a pooler whose mode is **session**; `persistent` is `false` |

**The connection check, corrected (second review).** An earlier revision read
`DB_HOST`, `DB_PORT` and `DB_PERSISTENT` from `.env`. The application does not
necessarily use those: `config/database.php` switches host and port to
`DB_POOLER_HOST` and `DB_POOLER_PORT` when `DB_USE_POOLER` is true; a `DB_URL`
overrides host, port and database; and a cached configuration
(`bootstrap/cache/config.php`) is used instead of `.env` altogether. So check
the configuration as the release resolves it, from the release checkout, as
the web user, printing no credentials:

```bash
cd <release checkout> && runuser -u www-data -- php -r '
require "vendor/autoload.php"; $app = require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$c = Illuminate\Support\Facades\DB::connection();   // after DB_URL parsing; does not connect
$o = $c->getConfig("options") ?? [];
echo json_encode([
  "connection" => $c->getName(), "driver" => $c->getDriverName(),
  "config_cached" => $app->configurationIsCached(),
  "url_set" => filled(config("database.connections.pgsql.url")),
  "use_pooler" => (bool) env("DB_USE_POOLER", false),
  "host" => $c->getConfig("host"), "port" => $c->getConfig("port"),
  "persistent" => (bool) ($o[PDO::ATTR_PERSISTENT] ?? false),
]), PHP_EOL;'
```

`env()` returns null under a cached configuration, so `use_pooler` is
meaningful only when `config_cached` is false; `host` and `port` are what
decide. Then establish what answers at that `host:port`:

* On this machine: `sudo ss -ltnp "sport = :<port>"`. `postgres` means direct.
* PgBouncer: its effective `pool_mode` — the `[pgbouncer]` default **and** any
  `pool_mode=` override on the matching `[databases]` or `[users]` entry — from
  `SHOW DATABASES` / `SHOW USERS` on its admin console, or its configuration
  file. It must be `session`.
* A managed pooler: the provider's mode for that exact host and port. Record
  the setting as evidence.

A behavioural probe (for example `pg_backend_pid()` across two statements)
only corroborates: under light load a transaction pooler can hand back the same
server session and pass it. The configuration decides.

What this check is for has changed. Reconnection is handled in code: while the
middleware holds a claim lock, the connection may not be re-established
(`idempotency-unresolved-claims.md` §4.4). That was measured with a **direct**
connection only — PostgreSQL 16.15 on 127.0.0.1:5432, no pooler
(`claim_release_race.php`, scenario 2). Behind a session-mode pooler the same
result is an **inferred expectation, not a measurement**: the application
would still see a lost connection and have to reconnect, which is what is
refused, but no pooler was run. **Correction:** an earlier revision said it
was measured "whether the endpoint is direct or session-pooled". A
transaction-mode pooler is different — it moves
statements between server sessions with no reconnect the application could
see — so it must be excluded here. `DB_PERSISTENT` is excluded because a
persistent connection can keep a lock past a fatal error; the tool then
refuses, which is safe but blocks reconciliation. Checked before the code goes
live, because it is a precondition of the code.

### D2 — after Phase 2, before Phase 2b

**Corrected.** This checkpoint used to be headed "before Phase 3" while
expecting the notifications migration absent — but Phase 2b applies it before
Phase 3. It is the gate for Phase 2b; D2b below is the gate for Phase 3.

| Check | Expected |
|---|---|
| serving nodes' `HEAD` | the release SHA on **every** node — **not** `018b3d8` |
| PHP-FPM pool workers (`ps -eo lstart,cmd \| grep 'php-fpm: pool'`) and any queue worker or scheduler daemon | every one started **after** the code switch. Opcache and long-running workers otherwise keep executing baseline code — and a baseline **queue worker** is exactly what Phase 2b must not meet |
| migrations and constraints | as D1: eight applied, notifications and contract absent, 0 constraints |
| `php artisan config:show loyalty.expiry_active_from` (release checkout, as the web user) | empty — or the date the S3-16 decision approved, recorded with that approval |
| queued exports written since the switch in the old flat layout: `select count(*) from report_exports where created_at > :switch and file_path is not null and file_path !~ '^reporting-exports/[0-9]+/[0-9]+/[^/]+$'` | 0. Any row means a baseline export job still ran after the switch; creating the notifications table would let such exports finish `done` |
| baseline-shaped rows written since the switch, per table: `select count(*) from karigar_invoices where updated_at > :switch and ((invoice_file_path is not null and invoice_file_disk is null) or (invoice_file_path is null and invoice_file_disk is not null))`, and the same for `stock_purchases` (`invoice_image`, `invoice_image_disk`) and `shop_billing_settings` (`digital_signature_path`, `digital_signature_disk`) | 0 on all three. The release always writes both columns, so any such row since the switch shows a baseline writer still serving somewhere |

The new code serves the export panel and authorized downloads without the
table (rehearsed: handoff §8, section C2; `ExportNotificationDeliveryTest`);
exports finished meanwhile record the missing table and are delivered after
Phase 2b.

### D2b — after Phase 2b, before Phase 3

| Check | Expected |
|---|---|
| `select migration from migrations where migration in :ten` | the eight Phase 1 names and `2026_09_24_120000_create_notifications_table`; the contract absent |
| `select to_regclass('notifications')` | not NULL |
| `select conname from pg_constraint where conname in (…the three…)` | 0 rows |
| `php artisan reporting:notify-export` | the list of unexpired finished exports not recorded as notified, recorded; `--send` only as approved |
| the flat-layout export count and the baseline-shaped row counts from D2, re-run | still 0 — the contract must not meet a baseline writer either |

### D3 — after Phase 3

| Check | Expected |
|---|---|
| `select migration from migrations where migration in :ten` | all ten |
| `select conname, convalidated from pg_constraint where conname in (…the three…)` | three rows, all `t` (T-06's signal; `f` means the deploy "succeeded" without validating) |
| `php artisan migrate:status --pending` | nothing pending |

---

## Recovery — keep the schema, move the code forward

| Step | Status |
|---|---|
| Phase 3 `down()` — drop the three constraints | Allowed and reversible: re-running the contract reconciles again. **Required before any baseline-shaped writer serves.** |
| Revert forward: revert the faulty commit(s) on top of the release SHA | **The default recovery.** The schema stays. The recovery revision is reviewed against three properties before it serves: it writes path **and** disk for all three columns; its payment route honours durable claims (`payment-idempotency-rollback-constraints.md` §4); it takes the claim advisory lock. It then goes through D1 → Phase 2 → D2 under its own SHA |
| Serve `018b3d8` again | **Prohibited while serving traffic** — below |
| `down()` of `invoice_payment_claims`, `response_headers` or any Phase 1 migration | Not a recovery step. Phase 1 down is one-way (measured, handoff §6a); claims down destroys the only durable record of served payment keys |

**Why the baseline cannot serve on the retained schema.** Each reason is
sufficient on its own:

1. **Payments.** `018b3d8`'s payment route is cache-only and double-charges a
   retry once the cache entry is gone (RB-1) or during any mixed-version
   overlap (RB-4) — `payment-idempotency-rollback-constraints.md` §1.
2. **Uploads fail** while the contract's constraints exist (T-01/T-03/T-05).
   That one is avoidable by running Phase 3 `down()` first.
3. **Disk labels go stale.** The baseline writes paths and never the disk
   columns (`018b3d8`: `SettingsController:520-527`,
   `KarigarInvoiceService:110-114`, `StockPurchaseController:350-354`). On a
   row the release already labelled `local`, a baseline re-upload stores the
   new file on the public disk and leaves `local` beside it. The contract's
   `reconcile()` fills only NULL disks and clears only stranded ones, so running
   it again does not repair this. The release then looks for the new file on the
   private disk and does not find it. The movers' `--verify` reports such rows as
   a missing private copy; correcting the label is a data change that needs its
   own approval. Source-level, not executed.

**Correction.** The previous revision of this section said that keeping the
columns under baseline code "costs nothing". Keeping them is right. It does not
cost nothing: reason 3 is the cost.

**Effects of switching code, in either direction** — expected, and refusals
rather than losses:

* **Version tags.** Since XR-05 the tag includes the row version, so a tag
  minted by one version never matches the other's. Each edit screen open across
  the switch gets one `412`, and the mobile app refetches (`mutation-error-alert.ts`,
  stale path).
* **Unresolved claims under the baseline.** The baseline replays any stored
  claim's status as-is. A claim the release staked and never resolved has
  status 0; `new JsonResponse(null, 0)` throws `The HTTP status code "0" is not
  valid.` (measured with the installed vendor), so every retry of that key gets
  a 500 — no duplicate. The baseline itself never stakes a claim before the
  controller runs, so it leaves none unresolved.

**If `018b3d8` must be restored anyway** because the release cannot serve at
all: stop serving first (maintenance mode), run Phase 3 `down()`, switch the
code, and satisfy `payment-idempotency-rollback-constraints.md` §4a before
payment traffic resumes. Before moving forward again, run each mover's
`--verify` to find the rows the baseline window left stale (reason 3). Not
rehearsed at the code level: §6a rehearsed the schema only, and running the
cache-only payment code is prohibited.

---

## Rollback — schema steps, as rehearsed

| From | Rolling back | Result |
|---|---|---|
| Phase 3 | `down()` drops the three constraints | Returns to the expand-only window, which the baseline tolerates. Fully reversible. |
| Phase 2 | Redeploy baseline code | **Prohibited while serving** — § Recovery. Recover by reverting forward instead. |
| Phase 1 | `down()` drops the columns and the relocations table | Reversible as schema, but destroys the record of which disk each file is on. |

### What a rollback cannot undo

State this plainly rather than implying the change is freely reversible:

- **Files already written to the private disk by the new code.** The baseline
  reads attachments from the public disk unconditionally. A file the new code
  stored privately is recorded as such in its row's disk column — and rolling
  back Phase 1 *deletes that column*. The baseline will then look for those
  bytes on the public tree and not find them. Those uploads become unreachable
  to the old code, and the record of where they actually are is gone.
- **Files already relocated.** Same shape, plus the `signature_relocations`
  ledger that maps a historical reference to its verified private copy is
  dropped with it.

Practical consequence: **roll back Phase 3 freely; never use Phase 1
rollback as recovery.** Recover by moving the code forward on the retained
schema (§ Recovery). An earlier revision advised reverting the code to the
baseline and leaving the columns, "which costs nothing"; see the correction
there.

---

## Dependencies

- `2026_09_20_130000` (contract) requires all three expand migrations. It skips
  any table whose disk column is absent rather than failing, so a partial estate
  does not abort the deploy.
- `2026_09_20_120000` (`signature_relocations`) is required by
  `App\Services\SignatureRelocationLedger`, which
  `App\Services\InvoiceSignatureRenderer` and
  `App\Console\Commands\RelocateShopSignatures` both depend on. It has no
  ordering relationship to the contract phase.
- The relocation command must not run before Phase 2, and its purge step
  requires ledger evidence per row (pinned by R-18).

---

## Recorded limitations

- **S3-02b (new, open).** The signature and purchase constraints also restrict
  the disk to `('public','local')`; the karigar one does not. The contract
  migration carries each constraint over verbatim so that this phase changes
  release order and nothing else. Tightening karigar to match is a behaviour
  change for its own reviewed commit.
- The flat `signatures/` layout the baseline uses means every shop's signature
  shares one public directory and the paths are mutually enumerable. That is
  part of S3-04's exposure. No path check fixes it; only relocation does.
- Test evidence here is local only (`jewelflow_testing`, synthetic fixtures).
  Production exposure remains OPEN until containment is applied and verified.
