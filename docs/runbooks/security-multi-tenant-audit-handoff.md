# Multi-tenant security audit — reviewable handoff

Branch `security/multi-tenant-audit`, worktree
`/home/himanshu/Desktop/jewelflow-worktrees/security-multi-tenant-audit`.

**Nothing in this branch has been pushed, deployed, or applied to any
environment other than the local `jewelflow_testing` database.** Every number
below is local evidence. Production exposure status is tracked separately and is
not improved by any of it.

**Last observed deployed baseline:** `018b3d810e37d534f498033ab582ee41f3197c27`,
observed on the production server at **2026-09-20T18:36:08+00:00** by a
read-only `git rev-parse HEAD` in `/var/www/jewelflow`
(`kyc-public-exposure-containment.md` §1). **It has not been re-observed since.**
Nothing in this document describes the server's current state or current
exposure: every production statement is as of that observation or earlier.
Before executing any approved step, run that phase's drift check
(`signature-migration-release-order.md` § Drift checks) and stop on drift.

**This round (§0e — answering the review of `592d864`):** web code through
`4b53fb3e98a7ec1deea9ee82b44e3a941a73ce0f` (S3-17 deployment window `b2c1094`,
S3-16 loyalty writers `0069839`, inventory trust rules and report joins
`f6d3473`, audit tools and historical wording `4b53fb3`); mobile unchanged at
`4f10a3b5df7d64c11b39b90713169155c713d233`. The reviewed candidate is
`592d864f1f2003d104310c51af6903a705a83475`; the packet carries the delta from
it. That review accepted the legacy-export download refusal, worker cleanup
on `Looping`, the child-process database guard and the mobile "Already
recorded" message; earlier acceptances stand; **it is not release approval.**
**Rounds before:** §0d (review of `c9d30b0`: S3-14/S3-15 validation repairs
and S3-18's protection for new exports accepted); §7e (candidate
`d37879b6c36991f17bafec50137adaaaba7c677b`).
Four independent source reviews so far: packet
`7b1d709162109fe51f56d03d77c14e3e50d7b9ea` (seven findings, §0b); candidate
`9aaf1af8f366cb1b3d038a383586d6cf9ae81112` (XR-01, XR-04, XR-06 accepted;
XR-02, XR-03, XR-05, XR-07 partial, §0c); candidate
`d37879b6c36991f17bafec50137adaaaba7c677b` (**XR-02, XR-03, XR-05 and XR-07
accepted**); candidate `c9d30b05da4c0c43362eb897bd0ca3aeaa893927` (§0d);
candidate `592d864f1f2003d104310c51af6903a705a83475` (above).
Source-review acceptance is not deployment approval and does not certify the
application as a whole. This document's own commit is later than `4b53fb3`
and changes documentation, the resolved inventory and the packet builder only.

Mobile repository `/home/himanshu/Desktop/jewelflowMobileApp`, branch
`rebrand/jewelflows-mobile`. Audit baseline
`d8a07819ac41291bd3a7e4ba27d31261d96aef28`; current HEAD
`4f10a3b5df7d64c11b39b90713169155c713d233`. Three audit commits, not pushed:
`ffcd034` (S3-04 mobile half — warn when a bill printed without its signature),
`2cad553` (S3-09c — uncertain mutation outcome) and `4f10a3b` (a dedicated
message for `idempotency_outcome_reconciled`, §0d Part 6). **Correction:** this
paragraph used to say "I made no change to that repository"; true when written,
stale after `ffcd034`. The repository carries one pre-existing dirty file,
`src/utils/storage.ts` (last written 2026-07-09, before this audit opened; a
web-preview fallback from SecureStore to `localStorage`) — unrelated, not
reviewed, and excluded from the packet — plus untracked scratch files that are
not mine.

---

## 0e. Review of `592d864` — this round

The reviewer accepted the bounded legacy-export download refusal, worker
cleanup on `Looping`, the child-process database guard and the mobile "Already
recorded" message; earlier accepted repairs stand. **Not release approval.**
The findings below were source-review findings; each was reproduced locally
before it was repaired, and the reproduction is recorded. Everything is local
to `jewelflow_testing`.

| Finding | Reproduced (local) | Repair | Commit | Executed evidence |
|---|---|---|---|---|
| S3-17: the Phase-2 window — panel and download required the table | **yes**: on the Phase-2 schema both answered **500** (`42P01 relation "notifications" does not exist`) | the panel reads notifications only when the table exists; marking read on download is bookkeeping — skipped without the table, a savepoint otherwise, never thrown | `b2c1094` | `ExportNotificationDeliveryTest` +4, RED first; an injected refusal of the read-state update still serves the file (without the guard: 500). Rehearsal section C2 runs the application between phases |
| S3-17: D2 checkpoint | **yes**: D2 was headed "before Phase 3" and expected the table absent, though Phase 2b creates it first | D2 = after Phase 2, before 2b, plus a flat-layout export count (no baseline export job still runs); D2b = after 2b, before Phase 3 | `b2c1094` | runbook |
| S3-16: expiry against the other loyalty writers | **yes**, through the real route with real processes: redemption bound 110, expiry committed 100, redemption of 80 accepted — **302**, balance **−70**, last row `redeem 80 → 30`; a real race: 1 negative balance, 1 broken `balance_after` chain, 24 × **500**, 59 of 60 customers expired | earning and redemption now share expiry's and reversal's customer row lock: fresh balance checked, balance and ledger row written as one unit; the adjust route answers a validation error | `0069839` | `LoyaltyWriteBoundaryTest` (4, RED first); `loyalty_redeem_expiry_race.php` (three scenarios; the third decides the lock) |
| Scanner trust rules | **yes**: fixtures show the committed rules trusting 7 of 10 expressions that must be read | operators, assignments and record provenance classified conservatively | `f6d3473` | `TenantQuerySweepTrustRulesTest` — 14 fixtures, RED on the old rules |
| Rows the corrections surfaced | 118 tenant-facing | reinspected with per-file verdicts; no finding | `f6d3473` | `reviewed.tsv`; resolved inventory |
| `ok-fk-join` verdicts conditional on reference integrity | **yes**: with inconsistent references, shop A's day book named shop B's customer and its cash flow shop B's user | 24 report joins also require the referenced row's shop to equal the base row's | `f6d3473` | `ReportReferenceIntegrityTest` (2, RED first); 286 reporting, dashboard and inventory tests unchanged |
| "75 families without coverage" | wording | "no heuristic match" — absence of coverage not independently checked (66 after detection counted tests that build both shops through one helper) | `f6d3473` | `test_family_map.php` |
| `AuditForeignReferences` identity | **yes**: printed the invoice's id as the line's (`invoice_items 2` for line 5002) | the row's own id and each reference it holds, with shops | `4b53fb3` | test RED on the old command |
| `AuditExportFiles` physical claim | **yes**: overlapping roots and a scoped disk → exit 0 (a missed shared file); two S3 stores with one bucket name → exit 1 (a false match), FTP counted as resolved | resolution per driver on this host's configuration; unresolved storage exits 2 — unknown, never clean | `4b53fb3` | 2 tests, RED on the old command |
| Historical conclusions from the notifications table | — | corrected: configuration now, historical records, logs and disclosure kept apart | `4b53fb3` | §0d Part 1, R10, runbook |

### Part 1 — the S3-17 deployment window

Between Phase 2 (new code) and Phase 2b (the table), the job already
survived; the two request paths did not. The panel now shows no ready list
until the table exists; a download marks its notification read only as
bookkeeping that can never stand between an authorized user and the file.
Authorization, the foreign-shop 404 and the legacy-layout 410 are unchanged
and asserted on the same schema.

The rehearsal now runs the application between phases, not only the
migrations: on the Phase-1 schema (the new code serving before Phase 2b) the
export job finishes and records the missing table, the panel and the
download are served, another shop is refused, a flat-layout file gets 410,
and `loyalty:expire` writes nothing; after Phase 2b the pending export is
delivered once without regeneration, listed, downloaded and marked read. The
protection against enabling unsafe baseline exports stays: the table only
after the code, and D2 now proves no baseline export job ran after the switch
before Phase 2b.

### Part 2 — S3-16 against the other writers

Writers of `customers.loyalty_points` and the ledger: earning at invoice
finalization and manual earning (`addLoyaltyPoints`), manual redemption
(`redeemLoyaltyPoints` — `redeemPoints` has no caller), reversal on
cancellation and return (`reversePoints`), expiry (`expireDue`). The column
is mass-assignable, but no request path fills it (request sweep); seeds and
imports that set it without a ledger row are the drift expiry skips and
reports.

All four now take the same customer row lock, read the balance under it,
and write the balance with its ledger row as one unit. Executed:

* **the precise interleaving** (real route, separate expiry process):
  refused against the fresh 10 ("Insufficient loyalty points", a validation
  redirect); balance 10; nothing written for it;
* **a failure between the writes** (injected on the ledger insert): neither
  balance nor ledger moves, for earning and redemption;
* **a real race**, expiry against 60 redemptions in three processes: no
  negative balance, no ledger mismatch, 60 of 60 expired;
* **the lock itself**, deterministically: the harness holds the row as a
  mid-flight expiry and commits only once the redemption is seen waiting on
  a lock → balance 10; with the lock removed → −70. **Correction to my own
  evidence:** the random race passed twice with the lock removed — it does
  not decide the lock; this scenario does.

**Pre-existing, independent of expiry:** redemption checked the model's
loaded balance; the balance change and its ledger row were separate writes
(injected failure: 110 → 80 with no row); `balance_after` came from the
stale model; "insufficient points" was a 500; no constraint prevents a
negative balance. **Introduced by enabling expiry:** a scheduled writer
decrementing balances daily gives that stale check a frequent partner (the
race before the repair: one negative balance, one broken chain); expiry skips
a customer whose ledger and balance disagree, so a write in progress can drop
a customer from a run (59 of 60 before, 60 after — the attribution is
inferred, not isolated).

Activation stays unset; backlog, allocation, drifted balances and reporting
remain the decisions of §0d Part 5. No trigger or historical row was touched.

### Part 3 — the inventory, conservative

**Scanner.** `where('shop_id', '<>' / '!=' …)`, `whereNot`, `whereNotIn` are
`where-not`; a `shop_id` in `update()`/`fill()` or updateOrCreate's values is
`assign` (in its attributes, a filter); neither is ever trusted. A record's
own id or shop is trusted only when the record's ownership was established
where it was obtained — a scoped lookup, a query with a trusted filter, a
controller-bound scoped model; a key to another record (`record-fk`), one
reached through a relation, a model's own `$this->shop_id` (`self`) and any
unproven record (`record?`) are read. Tracing depth rose from 3 to 10 hops
(a depth artefact had marked `$shop = $user->shop` untraceable).

**Inspected.** 284 tenant-facing rows now carry verdicts (the 178 earlier
plus 106 surfaced; 42 re-keyed by the join edits were re-read; 42 stale keys
pruned). Admin 61 and console 139 rows are listed **UNREAD**. The resolved
inventory — every expression with status, note, location and full text — is
`docs/runbooks/tenant-inventory-resolved.tsv`.

**Joins on stored references.** No database constraint makes a referenced
row's shop equal the referencing row's. The write-side invariants, per
reference:

| Reference | Written with | Read side now |
|---|---|---|
| `invoices.customer_id` | `exists` + shop on every POS and invoice form (web, mobile, API) | customer joins require `customers.shop_id = invoices.shop_id` |
| `invoice_items.item_id` | `exists` + shop (POS forms) | item joins require `items.shop_id = invoices.shop_id` |
| `credit_notes.customer_id`, `.invoice_id` | derived server-side from the return's invoice | shop equality |
| `cash_transactions.user_id` | the authenticated user | `users.shop_id = cash_transactions.shop_id` |
| `compliance_alerts.customer_id`, `.invoice_id` | derived from the invoice checked | shop equality |
| `installment_plans.invoice_id`, `.customer_id` | `exists` + shop; `lockActiveOrFail(shop)` | shop equality |
| `scheme_enrollments.customer_id`, `.scheme_id` | `exists` + shop | shop equality |
| `customer_gold_transactions.customer_id` | the route-bound customer | shop equality |
| `stock_purchase_items.stock_purchase_id` | created under its purchase | shop equality |
| `users.role_id` | `exists` + shop (staff); explicit same-shop check (admin) | unchanged — seat counts read the role name only |

Rows written before an invariant existed (S3-14 was such a gap) are
uncertain; `tenant:audit-foreign-references` counts them (NOT RUN on
production).

### Part 4 — the audit tools

Both tools now say what they resolved and what they could not; neither
turns an unknown into a clean result, and neither infers the past from the
configuration of today.

**Still no claim that tenant isolation is complete.** This round covers the
paths and rules named; 200 admin and console rows are unread, production is
unobserved, and the test-family map is a heuristic.

---

## 0d. Review of `c9d30b0`

The reviewer accepted the bounded S3-14/S3-15 validation repairs and S3-18's
protection for newly generated exports; the earlier XR acceptances stand.
**Not release approval.** Everything below is local to `jewelflow_testing`:
nothing pushed, deployed, purged or relocated; no production database fix.
"Executed" means a test, harness or command ran; "inspected" means read.

| Part | ID | Status (local) | Commit | Executed evidence |
|---|---|---|---|---|
| 1 | S3-18 — exports stored before the fix | **Contained**: the download route refuses (410) any file outside its export's own directory; rows and files untouched, nothing reassigned | `b2b6e77` | `QueuedExportIsolationTest` +3 against the current controller: a legacy file two shops' rows name is served to neither; a legacy file only one row names is still refused; a path climbing out of its directory is refused |
| 1 | R10 (corrected) | **Read-only checks prepared, NOT RUN on any server**: four separate questions, below | `b2b6e77`, `530e634`, `70681a4` | `reporting:audit-export-files` (+2 tests); `tenant:audit-foreign-references` (+2 tests); D0 records `to_regclass('notifications')` |
| 2 | §7e inventory | **Blind spots closed in the tools; every tenant-facing row read; admin/console rows listed UNREAD** | `530e634` | the three sweeps; `MobileLookupIsolationTest` (3) with a mutation control |
| 3 | worker boundary | **Implemented**: tenant context cleared on the worker's `Looping` event | `f507208` | `queue_worker_tenant_reuse.php`; `TenantBackgroundExecutionTest` +3 |
| 3 | harness guards | **Fixed**, both entry points; **and a regression it caused, found this round and fixed** | `f507208`, `70681a4` | refusal run for every guarded script (§8) |
| 4 | S3-17 | **REPAIRED** | `cfda2a0` | `ExportNotificationDeliveryTest` (6, the real `database` channel); worker harness |
| 5 | S3-16 | **Mechanism REPAIRED, inactive by default; activation OPEN (decision below)** | `5efc66f` | `LoyaltyExpiryTest` (7); `loyalty_expiry_race.php`; the S3-16 characterization inverted |
| 6 | mobile `idempotency_outcome_reconciled` | **Built** | mobile `4f10a3b` | jest 280/280, `tsc` clean |

### Part 1 — S3-18's existing exports, and R10 corrected

**Containment.** An export whose `file_path` is not
`reporting-exports/{shop}/{export}/<file>` gets 410 and a log line; nothing is
read from the file, and no row or file changes. Proof of ownership comes only
from the layout: a flat path cannot be shown to hold its own export's bytes,
because a job that overwrote it and then failed before recording its path
leaves no row. So even a legacy file named by exactly one row is refused.

**R10, corrected.** The earlier text grouped rows by `file_path` alone and read
a duplicate as a collision. Duplicate metadata identifies **candidates**; it
establishes neither the bytes served nor a disclosure. Four separate questions,
each with its own check — **all NOT RUN; server state unverified:**

| Question | Read-only check | What it cannot tell |
|---|---|---|
| (a) Was a file overwritten by another export? | `php artisan reporting:audit-export-files` — each row resolved to a physical file on this host's storage configuration (local: canonical absolute path, so overlapping roots and alias disks meet; s3: endpoint + bucket + prefix; scoped disks through the disk they wrap); same-shop duplicates reported apart from **cross-shop** ones; exit 1 on any cross-shop group, **exit 2 when any row's storage cannot be resolved** (an unmodelled adapter, an undefined disk, a path above its root) — unknown is never reported clean | whose bytes the file now holds; an overwrite by a job that crashed before recording its path leaves no row; storage configured differently in the past or on another host |
| (b) Was a ready-notification stored? | `notifications` rows naming the export — the audit prints the count per cross-shop row when the table exists. D0's `to_regclass('notifications')` shows the configuration **now** only: an absent table does not show that none was ever stored, and a present one shows neither delivery nor exposure | who opened it; rows deleted before the check |
| (c) Was the file downloaded, by whom? | the web server's access log for `/reporting/exports/{id}/download`, status 200. The application logs no downloads (since `cfda2a0` a download marks its notification read — new code only). The current route refuses a `failed` export; whether a given export was `failed` when a request arrived is a question for its row history and the log together | nothing, if the log is gone |
| (d) Actual exposure | (a) cross-shop **and** (c) a 200 to a user of a shop other than the one whose bytes the file held, after the overwrite (log time against `generated_at`) | not decidable from the database alone |

Historical S3-14/S3-15 references, and every other stored reference between
shops: `php artisan tenant:audit-foreign-references` — read-only (READ ONLY
transaction, 120 s statement timeout), counts rows whose reference names
another shop's row for **238** references on this schema (232 declared foreign
keys between shop tables whatever the column name, 2 implied by name, 4 held
by rows owned through a parent such as `invoice_items`), prints examples, and
lists the 20 polymorphic or external `*_id` columns it cannot cover. Exit 1 on
any. Tested by injecting the pre-fix S3-14 and S3-15 rows and a line on shop
A's invoice naming shop B's item: exactly those are reported. On
`jewelflow_testing`: 0 crossing.

### Part 2 — the inventory's blind spots

Scanner matches, inspected rows and behavioural tests are reported separately.

**Scanner (`tests/Inventory/tenant_query_sweep.php`, 871 expressions).** Names
now resolve through imports and aliases; `static::`/`self::` resolve inside
models and traits. Forms, each counted, **absent ones stated**: `DB::table`
196, `DB::<sql>` 45, `DB::connection()->…` **absent**, `app('db')->…`
**absent**, `->getConnection()->…` **absent**, injected `$db`/`$conn`
**absent**, unscoped `Model::…` 402, `static::`/`self::` in a model 27, a model
reached through an import alias 1, instance `newQuery()` 2,
`withoutTenant`/`withoutGlobalScope(s)` 153, `newQueryWithoutScopes`/`newModelQuery`
**absent**, tenant tables embedded in joins 41, `->from()` 4, raw fragments 1.
Ownership through parents: 7 tables one level; **none two or more levels
deep** (checked from the schema's foreign keys); 23 tables with no path are
listed — `notifications` among them (owned by its polymorphic user, not
traced). Parse failures are counted and exit 3: **0**.

A `shop_id` mention is no longer counted as a constraint. Every filter, key and
insert value is classified by source — context, a record, `REQUEST` (only an
HTTP-typed parameter), or traced to what reaches it (callers matched by method
name **and** receiver class, DTO constructors, `$this->method()` returns;
three levels; `--trace=<method>` prints the call sites). A request-supplied
`shop_id` is never excused; an `orWhere` breaks the conjunction.

**Inspected.** Rows to read: tenant-facing **179, all read** — 114 this round,
65 carried from the 11e91c3 round (their files unchanged since). Verdicts, each
keyed to file + method + expression in `tests/Inventory/reviewed.tsv`: 50
ok-context, 4 ok-record, 30 ok-fk-join, 26 ok-platform, 4 latent, 65 earlier.
**Admin 35 and console 122 stay listed as UNREAD** — visible, not claimed.
No new defect. Latent (no caller today): `MetalRate::latestFor()` without a
shop returns any shop's newest rate; `OrchestrationEventService`'s two report
methods. Observation, not isolation: `FraudDetectionService` copies full PANs
into platform fraud flags.

Joins that follow a foreign key from a shop-filtered row reach another shop
only if that key names another shop's row — the S3-14/S3-15 class, which
`tenant:audit-foreign-references` now counts for every reference.

**Request ids (`request_id_sweep.php`).** 168 validation keys: 102
exists+shop (this now counts the `*ExistsRule($shopId)` factories and
shop-built rule objects, read and confirmed), 3 exists+parent, 7 exists-only, 56
shape-only. New: 37 reads of id-like request fields outside validation,
including dynamic keys and whole-request bags into writes; 28 to read, **all
read** — list filters and preselects inside the caller's shop, lookups
constrained to it, platform billing callbacks (signature-verified), platform
admin screens. No finding.

**Behavioural, mapped (`test_family_map.php`).** 239 cross-shop test files; 246
families (110 tenant models, 136 route prefixes, matched by URI or route
name); 171 referenced by at least one cross-shop test, **75 by none** —
listed. A reference is a pointer, not proof. Prioritised the tenant-facing
reads with none: `MobileLookupIsolationTest` covers the mobile customer and
item searches, vendor and karigar lookups, vendors, stock, catalog items and
POS bootstrap (both shops' records share one search marker), the vendor
ledger, and the stock batch-status write naming another shop's item (422,
nothing changes in either shop). With the scope's shop filter removed, five of
the eight reads leak and the test fails; the other three filter explicitly
as well.

### Part 3 — the worker boundary

Laravel 12.46, read before choosing: `Worker::daemon()` fires `Looping` before
each fetch (`daemonShouldRun()`), `runNextJob` (`--once`) fires none, and the
`sync` connection fires `JobProcessing`/`JobProcessed` exactly as a worker
does. A clear in `Queue::before` would therefore wipe the **caller's** context
whenever a request dispatches on the sync connection — demonstrated by the
control test. Clearing on `Looping` touches only the daemon loop, between
jobs. Executed:

* one real `queue:work` across shop A, a job throwing inside A's context, a
  job that sets context and returns (an **injected leak — a defensive-control
  test**: no application job does that), and shop B: every probe saw no
  context; each export holds only its own shop and — since S3-17 — is
  delivered to its own requester;
* a job run on the sync connection keeps its caller's context; nested
  `runFor` restores each level.

Also found: tenant context leaked between PHPUnit tests; `TestCase` now clears
it in `setUp`/`tearDown`.

Harness guards: every entry point refuses a database not named
`jewelflow_testing` before any fixture or request — including the child
processes of `production_binding_path.php` (the defect named in the review)
and `idempotency_race.php`. **Correction:** the guard added in `f507208` also
refused `idempotency_race.php`'s control D, whose child is pointed at a
missing database on purpose, so D stopped observing the outage; the harness
prints no single verdict, and my earlier "harnesses SAFE" did not catch it.
Fixed in `70681a4`: the child accepts only the sentinel
`jewelflow_testing_absent`, and only after connecting to it fails. D again
answers 500 with no cash row.

### Part 4 — S3-17, repaired

**Contract.** The frozen reporting plan requires the in-app notification with
its link (`REPORT_EXPORT_ARCHITECTURE_PLAN.md:483`; `REPORT_EXPORT_IMPLEMENTATION_PLAN.md:81`,
"in-app + optional email"), and nothing read it back. The `database` channel is
kept; the table is created (skipped if one exists; its `down()` never drops it).

**Repair.** Delivery runs after the generation `try`, so a notification
failure can no longer turn `done` into `failed`. Delivery is one savepoint
under the export's row lock: the stored notification and `notified_at` commit
together, and a concurrent retry cannot notify twice. A failure is recorded in
`notification_error` (the driver's message — not `QueryException`'s, whose
bindings carry the signed link). A re-run of a finished job only delivers what
is owed; it regenerates nothing. `reporting:notify-export` lists the unexpired
finished queued exports not recorded as notified (failed, or a worker that
died before trying) and `--send` delivers them. Consumer: the export panel
lists the user's unread ready exports; a download marks its notification read.

**Executed, no notification fake:** persistence (a real row for the
requester), the panel and the download; an injected delivery failure (export
stays `done`, error recorded, no row), then retry (one row; file bytes,
`file_path` and `finished_at` unchanged; a second retry refused); the original
missing-table failure inside an enclosing transaction (export stays `done`, no
signed link stored); a job re-run after its worker died (no regeneration, one
notification); another shop's user sees nothing and following the link gets
404; permission revoked after delivery → 403. RED at `b2b6e77`: 4 of 4 failed
(`relation "notifications" does not exist`). Mutants: without the re-run
guard the file is regenerated; without the re-check under the lock two
notifications are stored.

**Release order.** The table goes in **Phase 2b, after the code** — see
`signature-migration-release-order.md`: while the table is absent, the
baseline marks each queued export `failed` after writing it and the route
refuses `failed` exports; creating the table under the baseline would let
shared files be served during the window.

### Part 5 — S3-16: an append-only expiry, and the decision it needs

**Mechanism (inactive until activated).** An expiry is a new `redeem` row
naming the lot it expires (`loyalty_transactions.expires_lot_id`, unique — one
expiry per lot whoever runs). No row is updated; the protected trigger (Art.
IX.A #9) and every historical row are untouched. What a lot still holds comes
from replaying the customer's ledger under the customer's row lock: a reversal
takes from its own invoice's lot, other debits take the earliest-expiring
points first. A customer whose cached balance differs from the ledger is
skipped and reported, never corrected. A reversal takes the same lock and never
takes back what expiry already took. `loyalty:expire` enters each shop's
context and reports per shop; `--dry-run` reports what is due.

**Synthetic examples** (clock 2026-10-15; activation 2026-10-01), each a test in
`LoyaltyExpiryTest`:

| Ledger | Run |
|---|---|
| earn 100 due 10-10, earn 40 due 09-01, earn 25 due 2027-01-01 | 100 expires (fell due after activation); 40 kept (backlog); 25 untouched; balance 165 → 65 |
| earn 100 due 10-10, earn 100 future, redeem 150 | the redemption used the due lot: nothing expires; balance 50 |
| the same with redeem 30 | the 70 left in the due lot expires; balance 170 → 100 |
| a sale earns 100 (due), cancelled before the run | the reversal took the lot: nothing expires |
| a sale earns 100 (due), the run expires it, then it is cancelled | the reversal takes nothing more |
| cached balance set outside the ledger | skipped, reported, nothing written |
| a pre-trigger expiry (flag and debit) | not expired again |
| the run twice; three processes at once over 150 customers | one expiry row per lot (with the lock removed, the unique index refused the duplicates — the harness then reports UNSAFE) |
| not activated | reports each shop's overdue lots and writes nothing |

**The decision — not made here, and nothing retroactive is enabled:**

1. **Activation date** (`LOYALTY_EXPIRY_ACTIVE_FROM`): the first expiry date
   enforced. Unset (the shipped default) nothing expires. Suggest no earlier
   than the deploy date plus whatever customer notice the business requires.
2. **The backlog** — lots whose expiry fell before activation, the ones S3-16
   left unexpired. This implementation never expires them: they stay
   spendable. Expiring them at activation, or after a grace date with notice,
   would be retroactive and needs its own decision and code. Size it first:
   `loyalty:expire` prints per shop "overdue, kept: N lot(s) / P pts" (NOT RUN
   on production).
3. **Which points a redemption uses** — earliest expiry first (expires the
   least; one sort in `LoyaltyService::replay`). The backlog lots sort first,
   so after activation a redemption spends honoured backlog points before
   points that can still expire; treating the backlog as never-expiring would
   expire less. Decide.
4. **Customers whose balance was set outside the ledger** (imports, seeds):
   skipped. The alternative — the difference as a non-expiring opening lot —
   is a policy. The dry run lists them.
5. **Reporting** — an expiry row is a `redeem` row (the type enum is
   unchanged) marked by `expires_lot_id`; the loyalty dashboard's "points
   redeemed" and the loyalty dataset count it as a redemption unless changed
   (reversals already are).

### Part 6 — mobile, and KYC

Mobile `4f10a3b`: 409 `idempotency_outcome_reconciled` gets its own kind —
title "Already recorded", the server's message or "This was recorded. Check
the record; do not enter it again." — and no retry or refresh affordance. RED
first (3 failing), then jest 280/280 in three consecutive full runs and `tsc`
clean; the first full run had one failure I did not capture by name, not
reproduced in the next three. `src/utils/storage.ts` untouched and uncommitted.

KYC containment is unchanged: its own package, **unexecuted**, awaiting its own
approval; **ORIGIN-ONLY remains PARTIAL** (§2).

**Do not read this section as tenant isolation complete.** It covers the
categories, forms and paths named; admin and console expressions are listed,
not read; production state is unobserved.

---

## 0. Reconciliation — every requested item, one table

"Local" means `jewelflow_testing` only; **no row is deployed**. The tenant-query
work (row 10) is one row and settles no other. Test files named below are green
in the full-suite run at execution SHA `751acc4` (§8: 3355 passed, 7
pre-existing skips, 0 failed) unless a row says otherwise.

| # | Item | Status | Commit(s) | Evidence | Still open |
|---|---|---|---|---|---|
| 1 | S3-09b unresolved claims: pruning, and what an operator may do with one | **Pruning defect FIXED locally; retention aligned with the middleware (XR-02).** **Operator procedure and tool PROPOSED** — evidence-gated, one claim at a time, two-person approval, audited; no age or bulk path; acts only when the claim's advisory lock proves the writer has ended (XR-02) | `e68eb31`; `c26a0fa`; tool + procedure `8d5a066`; `9d673a7` | `MobileIdempotencyRetentionTest` (7); `IdempotencyClaimReconciliationTest` (9) — both outcomes reached through the real cashbook route; `claim_release_race.php`, both scenarios (§0b, §0c); `docs/runbooks/idempotency-unresolved-claims.md` | Running the tool on production needs its own approval. The mobile message for `idempotency_outcome_reconciled` is built (mobile `4f10a3b`, §0d Part 6); no device run. Scheduler invocation NOT VERIFIED |
| 2 | 4xx release behaviour | **VERIFIED by inspection on all 16 routes**; the one class-C defect found was fixed separately (row 6); middleware policy deliberately unchanged | `2057a73`; `4e70660` | §7c-2; §7c-6 | 12 routes' 4xx paths are inspection-grade |
| 3 | Mobile retry-key handling (S3-09c) | **FIXED in mobile** — one key per operator intent, rotated only on success | mobile `2cad553` | 40 suites / 275 tests, `tsc` clean at `2cad553` | **No device or emulator run** |
| 4 | Real concurrency, changed shared middleware | **MEASURED on one route** (`POST /cashbook`), separate processes | `b85e296` | §7c-3 | Other 15 routes not raced; one machine, no pooler |
| 5 | Cashbook transaction repair (S3-10) | **FIXED locally** | `fb351f4` | `CashbookWriteAtomicityTest` (4); evidence class SIMULATED INTERRUPTION | No service-layer duplicate guard on `POST /cashbook` — see §0a on why that is not a separate defect |
| 6 | Return-service investigation | Lead **CLOSED**; **S3-11 FIXED** | `4e70660` | `ReturnApprovalAtomicityTest` (4) | Per-line `returned_at` guard NOT RUN |
| 7 | Coverage of the 16 affected routes | **PARTIAL.** Behavioural tests on 4 money/metal routes plus drawer-check atomicity; 12 reviewed by reading. **S3-09e REPAIRED** | `8cddbc3`, `b8673db`, `2057a73`, `b79f182`, `751acc4` | §7c; `MobileIdempotencyReplayFidelityTest` (10) | 12 routes inspection-only at the service layer. S3-12 **REPAIRED locally** (XR-05, §0b, §0c) |
| 8 | S3-05 snapshot rendering | **FIXED locally for tax, terms, payment instructions and — since XR-06 — the parties' identity.** Narrowed: the seller's contact line stays live pending a product decision | `b216b80`, `306aae1`, `d31ab16` | `FinalizedInvoiceSettingsDriftTest` (20) | Contact line (D-17) is a decision, not done. Quick-bill snapshots carry less |
| 9 | Historical / original reprints | **Original-reprint path COVERED**; bills finalized before the keys existed cannot be repaired | `e8e039e` | `QuickBillOriginalReprintTest` (3) | S3-13 open (§0a) |
| 10 | Tenant isolation | **No defect found in the inspected candidate set; missing-context fail-closed behaviour now has regression coverage** | `4877ae1`, `10a525c`, `06ec0e0`, `32ca54f` | §7e | Categories NOT inventoried; wrong/stale context partly tested |
| 11 | Catalogue / direct-file access | S3-06 **FIXED**; **S3-04b FIXED** (settings preview); S3-02/S3-03 code and relocation tooling **complete locally**, findings **OPEN** until relocation runs; S3-06b/S3-06c **OPEN** | `721c06d`, `51ca987`, `9d48331`, `cb5f983` | §0a; `PublicCatalogExposureTest` (8), `SettingsSignaturePreviewTest` (5), `PurchaseInvoiceImageRelocationTest` (5), `KarigarInvoiceRelocationTest`, `RelocationNonDestructiveTest` (10) | Relocation and purge not executed; anonymous fetch of a public-disk file and edge cache NOT RUN. Superseded signature versions stay public after relocation (§7) |
| 12 | KYC containment (S3-01 / S3-01c) | **OPEN — nothing executed.** Two packages; ORIGIN-ONLY is PARTIAL while edge exposure is unresolved | `25b638f`; `4b6e5f3` | `kyc-public-exposure-containment.md` | Cloudflare access unavailable; external consumers unverified |
| 13 | Backup A+C | **A IMPLEMENTED locally** (allowlist, no traversal of `.git`/`.claude`/`output`, only `.env` archived). **C PROPOSED** (procedure text only) | A `3f1bd85`; C `2d0b5f5` | §7g; `BackupSourceExclusionTest` (11) + real `backup:run --only-files` | A needs a deploy. C needs the operator's procedure owner to apply it; its current step text was not visible here |
| 14 | Migration / rollback | **REHEARSED on a populated synthetic database** — up, down newest-first and re-apply on the same data, outside any test transaction. Contract lock boundaries **MEASURED** with the real migrator (XR-04). Recovery keeps the schema and moves the code forward | `01f37a6`, `48981ff` | §6a: 26 checks pass, 17 measurements; `contract_migration_locks.php` (§0b) | Phase-1 rollback measured one-way; no rehearsal on production-sized data; serving `018b3d8` again is prohibited (runbook § Recovery) |
| 15 | Device checks | **NOT RUN** | — | §5 | Android/iOS print and share, multiple copies, desktop browser print, CSP `data:`, S3-09c on a handset |
| 16 | Tenant isolation — the §7e categories never inventoried | **Inventoried, every category** (§7e completion table); **blind spots closed this round (§0d Part 2)**. Five findings: **S3-14, S3-15, S3-18 FIXED locally**; S3-17 **REPAIRED** and S3-16's mechanism **REPAIRED, inactive** this round (rows 18, 19) | `9f633e1`, `11e91c3`; evidence `7c242d7`, `4a4011a`, `7a9ffb0`, `f3b1eba`, `1089ba0` | `TenantForeignReferenceTest` (5), `QueuedExportIsolationTest` (2), `BackupWorkbookIsolationTest` (2), `TenantBackgroundExecutionTest` (1, characterization), `ReturnsApiTest` (+1); harnesses `queue_worker_tenant_reuse.php`, `production_binding_path.php`; sweeps `tests/Inventory/*` | Octane, transaction pooler, production queue driver and `notifications` table not observed; 35 admin and 122 console rows listed UNREAD |
| 17 | S3-18 — exports stored before the fix | **Contained locally**: flat-layout files refused (410), nothing moved or reassigned; R10 split into four read-only questions | `b2b6e77` | `QueuedExportIsolationTest` (+3, +2 audit); `reporting:audit-export-files` | the four R10 checks **NOT RUN** on any server |
| 18 | S3-17 — queued exports failed after writing their file | **REPAIRED locally**: table, failure boundary, recorded outcome, retry without regeneration, in-app consumer; **the Phase-2 window repaired** (panel and download served before the table exists, §0e) | `cfda2a0`, `b2c1094` | `ExportNotificationDeliveryTest` (6, real channel); worker harness | table applied **after** the code (release order Phase 2b); production table not observed |
| 19 | S3-16 — scheduled loyalty expiry expired nothing | **Mechanism REPAIRED locally, inactive by default, and now verified against the other loyalty writers** (shared row lock; balance and ledger as one unit, §0e); activation, backlog and allocation **OPEN (decision, §0d Part 5)** | `5efc66f`, `0069839` | `LoyaltyExpiryTest` (7); `loyalty_expiry_race.php` | no retroactive expiry enabled; backlog size on production unknown |
| 20 | Worker boundary; harness guards | **Implemented** (context cleared on `Looping`); **guards on every entry point**, and the control-D regression the guard caused **fixed** | `f507208`, `70681a4` | worker harness; `TenantBackgroundExecutionTest` (+3); refusal runs (§8) | Octane NOT RUN |
| 21 | Every stored cross-shop reference | **Read-only audit built**, 238 references | `530e634` | `tenant:audit-foreign-references` (+2 tests); 0 on `jewelflow_testing` | **NOT RUN** on production (R10) |

## 0b. Independent source review of `7b1d709` — seven findings, answered

The review covered packet `7b1d709162109fe51f56d03d77c14e3e50d7b9ea` and mobile
`2cad553`. Its findings were source-level conclusions, not executed
demonstrations. Each was checked against the code before anything changed:
**all seven were confirmed, so no counter-evidence is offered.** Each was then
reproduced by a test or a real-process harness that failed first. S3
identities are kept; the XR ids map onto them.

| XR | S3 | Disposition | Commit | Before the repair | After | Mutation |
|---|---|---|---|---|---|---|
| XR-01 | S3-09 | Confirmed. **REPAIRED locally** | `bf37898` | Resolved middleware order: idempotency before authorization on 15 of 16 routes. 4 defect tests RED — a stored success served after revocation or downgrade | `IdempotentReplayAuthorizationTest` 7 passed (36) | Priority entry removed → the 4 defect tests fail, the 3 controls pass |
| XR-02 | S3-09b | Confirmed, both halves. **REPAIRED locally** — second review: partial, completed in §0c; **accepted at `d37879b`** | `9d673a7` | `claim_release_race.php`: claim released under a live writer → **2 cash rows** for one key. A committed-reconciled claim pruned with age; statuses −1/99/700 pruned | Reconciler refused while the writer lived; **1 row**; retry replayed. `IdempotencyClaimReconciliationTest` 9 passed | Writer-side lock removed → UNSAFE again |
| XR-03 | S3-02, S3-03, S3-04 (movers) | Confirmed. **REPAIRED locally** — second review: partial, completed in §0c; **accepted at `d37879b`** | `cb5f983` | 5 of 7 RED: a different destination overwritten; another shop's private signature overwritten before the ledger refused. `relocation_race.php`: **UNSAFE 3/3** — rows recorded private with no file | 7 passed; race **SAFE 3/3**; relocation and immutability suites 45 passed (213) | — the old movers are the negative control |
| XR-04 | S3-02, S3-03, S3-04 (contract migration) | Confirmed by measurement. **REPAIRED locally** | `48981ff` | `contract_migration_locks.php`: ACCESS EXCLUSIVE held to the end of the migration; finished tables unreadable and unwritable | A, B, C pass: locks only on the table in progress; only SHARE UPDATE EXCLUSIVE during VALIDATE, probe completed mid-VALIDATE; killed run leaves 2 validated + 1 untouched, re-run completes. `DiskColumnReleaseOrderTest` 8 passed | — the old migration is the negative control |
| XR-05 | S3-12 | Confirmed. **REPAIRED locally, no migration** — second review: partial, completed in §0c; **accepted at `d37879b`** | `69b4439` | `etag_race.php`, two workers, distinct keys, same starting tag: **[200, 200]**, one price lost | **[200, 412]**, stored price = the winner's | Locked re-check removed → UNSAFE |
| XR-06 | S3-05 | Confirmed. Identity **REPAIRED locally**; contact line **UNRESOLVED** (product decision) | `d31ab16` | D-14 seller, D-15 recipient, D-16 recorded-null vs missing key: RED | Drift file 20 passed (129); every print-related test: 258 passed, 1 skipped | `array_key_exists` → null check: D-16 fails alone |
| XR-07 | S3-09e | Confirmed. **REPAIRED locally** — second review: partial, completed in §0c; **accepted at `d37879b`** | `751acc4` | Boundary test RED: between the two writes, a resolved claim without its headers | Replay fidelity 9 passed; idempotency suites 42 passed (237); rehearsal with the column really dropped: claim resolves, status 200 | — |

Final-SHA figures for the harnesses, the rehearsal and the full suite are in §8.

**XR-01 — scope, stated exactly.** Claims are keyed by (shop, user, key), and
the different-shop control confirms it. What was served was a **stale
authorization decision**, not a cross-shop exploit and not a second write.
Authorization now runs before the replay on every route, whatever order a
route declares, because `EnsureIdempotency` follows `Authorize` in the
middleware priority list. Cash Book keeps its stricter policy (owner or
manager **and** `cash.create`, with its own message) as the route gate
`mobile-cashbook-write`. The session revocations became gates with their
messages unchanged.

**XR-02 — the proof is a lock, not a timeout.** The writer holds a
session-level advisory lock on the claim from before staking until the claim
is resolved; the tool must take the same lock before it may act.
`max_execution_time` is withdrawn as evidence: it does not count database
waits. The lock needs a connection that stays with the request, so release
check D1 verifies there is no transaction-mode pooler and `DB_PERSISTENT` is
off. The pruner now deletes only 2xx claims, so a claim reconciled as committed
keeps refusing its key. "Unresolved" means a status outside 100–599 in the
middleware, the tool and the pruner alike. The baseline never stakes a claim
before its controller runs, so every unresolved claim was staked by code that
takes the lock.

**XR-03 — non-destructive, all three movers.** Ownership is validated before
any byte moves. An absent destination is published from a verified temporary
with `link()`, which is atomic and refuses to replace. An identical one is
resumed. A conflicting one is refused, and both files, the row and the ledger
are left untouched. A mover deletes only its own temporary. Covered: conflict,
resumption, failed copy, a stray temporary from an interrupted run, the
foreign-shop signature path, and two concurrent movers — each by running the
commands themselves. Local disks only: on any other adapter `link()` fails and
the row is reported, never overwritten.

**XR-04 — boundaries measured with the real migrator**, from separate
connections. Partial failure: finished tables stay constrained and validated,
the failed one is unchanged, the migration is not recorded, and re-running
`migrate` completes it. No trigger is touched. Limits: synthetic data (3M
karigar rows), one PostgreSQL 16 instance, no production load.

**XR-05 — version and atomicity, two separate defects.** The tag now hashes
PostgreSQL's `xmin`, which changes with every committed write by another
transaction, whatever code path wrote it. (**Corrected in §0c:** this said
"every UPDATE"; `xmin` identifies the writing transaction, not each update.)
`saveIfMatch()` locks the row, re-checks If-Match against the locked row, and
writes in one transaction. No datetime migration: the `timestamp(6)` repair
proposed earlier for S3-12 is not needed, and the next-day rounding hazard it
carried does not arise. Transition cost: every tag changes once at deploy, so
an edit screen open across the deploy gets one 412 and the app refetches.

**XR-06 — completion narrowed.** Seller and recipient identity print as the
bill stated it. A recorded NULL stays empty; only a key the snapshot never
recorded falls back, to exactly what the template printed before. Layout stays
live (D-12). The seller's phone, WhatsApp and email stay live **pending a
product decision** on what a reprint's contact line is for (D-17 pins today's
behaviour). S3-05 is therefore not claimed complete. S3-13 is separate and
untouched.

**XR-07 — one statement.** Status, body and headers are published in one
UPDATE. The only failure that still resolves the claim is the one the old
two-statement design existed for: `response_headers` missing after a rollback
under this code. It is detected as a `QueryException` naming that column, and
the claim then resolves as legacy claims did. Any other failure leaves the
claim unresolved, so a retry is refused, never re-run. **Supersedes** the
`b79f182` rationale for two statements, which bought the degradation at the
price of the window XR-07 found.

### The release package, corrected in the same round

| Item | Was | Now |
|---|---|---|
| Drift checks | One check for every step: server `HEAD` = `018b3d8`. Wrong for Phase 3, which runs only after the nodes have left `018b3d8` | D0–D3, one per phase: code, migrations, constraints, the database connection (D1), fresh workers and baseline-shaped rows since the switch (D2), `convalidated` (D3). `signature-migration-release-order.md` § Drift checks |
| Recovery | "Revert the code, keep the columns — costs nothing" | Keep the schema and revert forward. Serving `018b3d8` again is prohibited while traffic flows: cache-only payments (RB-1), and stale disk labels — the baseline rewrites paths without disks, and `reconcile()` does not repair a stale label. § Recovery |
| KYC edge check | "Cloudflare block (403), not a 404 from origin" — once the origin deny exists, it passes whichever layer answered | Unique probe path; **0 origin log lines** is decisive, alongside a positive control proving the log records a probe that reached the origin; edge verified before the origin deny in Option B; bodies corroborate only. KYC stays **unexecuted**; ORIGIN-ONLY stays **PARTIAL** |
| Superseded signatures | Residual named in §7; no option | Residual stated after R6, and an unexecuted origin-deny option for `/storage/signatures/` (§7) |

## 0c. Second review of `9aaf1af` — four partial findings, answered; accepted at `d37879b`

**Disposition.** The review of candidate `d37879b` accepted the bounded
repairs for XR-02, XR-03, XR-05 and XR-07. Their evidence below is preserved
and the investigations are not reopened. Two documentation corrections it
asked for are applied: freezing preserves `xmin` on PostgreSQL 16 (XR-05
paragraph), and the reconnection repair was measured on a direct connection
only (release-order runbook, D1).

The review accepted the bounded repairs for XR-01 (authorization order), XR-04
(migration transaction boundaries) and XR-06 (the specified invoice identity
fields); their evidence in §0b stands. It found four others partially
resolved. Each was a source-level conclusion. Each was checked against the
code, reproduced at its specific boundary, and then repaired. **All four
premises held; no counter-evidence is offered.** Identities are unchanged.

| XR | S3 | Boundary | Commit | Before (code at `9aaf1af`) | After |
|---|---|---|---|---|---|
| XR-02 | S3-09b | The lock-holding session is lost after the claim is staked; Laravel 12.46 reconnects outside a transaction and retries on a new session | `5e1bf88` | `claim_release_race.php` scenario 2: tool found the lock free and released the claim; writer reconnected and booked its entry (201); same-key retry booked a **second** row | Writer ends **500 with no row**; the release was true; retry books **exactly one**. Scenario 1 (intact connection) SAFE in both |
| XR-05 | S3-12 | A competing commit, same stored second, between (a) loading a row and building its tag, (b) a PATCH's commit and its response tag — items **and** customers | `412c8ea` | `etag_image_race.php`: **4 of 4 windows UNSAFE** — an edit carrying the tag it was given was accepted (200) and overwrote a value it was never shown | **4 of 4 SAFE**: a GET shows the committed value with that value's tag; a PATCH response's tag belongs to the write, so the next stale edit gets 412. Two-writer race still SAFE |
| XR-07 | S3-09e | A real failure of the completion UPDATE whose SQL names `response_headers` but whose SQLSTATE is unrelated (23514) | `a3de74d` | Claim completed **200 without headers** — a retry would replay success without its tag | Claim stays **unresolved**; retry gets 409 `idempotency_in_flight`. A genuinely missing column (real 42703) still resolves, in the test and in the populated rehearsal |
| XR-03 | S3-04 | Existing ledger evidence for digest A while (a) source and destination agree on B, (b) the destination is absent; and identical evidence | `9a3fec8` | (a) and (b): the command succeeded and **rewrote the digest to B**; (b) also published B. Identical evidence was re-dated | (a), (b): **refused as `ledger_conflict`** before anything is published; files, settings and the ledger row unchanged. Identical evidence reused untouched |

**XR-02 — what now holds, and what still does not.** While a request holds a
claim lock, its connection is pinned: every path that would give it a new
session (automatic reconnect, `DB::reconnect()`, purge and re-create) goes
through the DatabaseManager's `ConnectionEstablished` event, and the listener
drops that session unused and throws until the claim is resolved. A request
whose session is lost therefore fails; PostgreSQL has already discarded its
uncommitted work. No application code calls `DB::purge`, `DB::reconnect`,
`DB::disconnect` or a second connection name (grep, `app/ routes/ bootstrap/
config/`); no model overrides its connection; no read/write split is
configured. **Still procedural:** a transaction-mode pooler moves statements
between server sessions with no reconnect the application could see, so D1
must exclude it. D1 itself was wrong: it read `.env`'s `DB_HOST` and `DB_PORT`,
while the application resolves `DB_URL`, `DB_USE_POOLER`,
`DB_POOLER_HOST`/`PORT` and any cached configuration. It now prints the
effective endpoint without credentials (checked locally, including a `DB_URL`
overriding the pooler switch) and takes the pooler mode from the pooler's own
configuration. Harness artefact, recorded: the first run of scenario 2 made
its retry in-process, and a second in-process request was answered for the
first request's user — the row landed in the other shop. Retries now run in
their own process.

**XR-05 — one row image.** `versioned()` reads the row and its `xmin` in one
statement through the model's own scoped query (tenant scopes kept, 404 if
gone); `entityTagFor()` refuses a model that was not read that way rather than
fetch a version for it. `saveIfMatch()` locks and re-checks one image, then
reads the written image under its own lock, before commit. Return and job-order
`show()` tag their root row the same way; only items and customers are sent
back with `If-Match` (mobile manifest). **Corrected claim:** `xmin` is the id
of the transaction that wrote the row version, not a per-update counter —
several updates in one transaction share it, and xids recur after wraparound.
Freezing does not change it: measured on PostgreSQL 16.15, a row's xmin was
3487483 before and after `VACUUM (FREEZE)`, with `relfrozenxid` advanced to
3487484 past it. **Correction:** this paragraph said a frozen row reports 2
and causes one spurious 412 — older-release behaviour, withdrawn. `updated_at`
stays in the hash; the residual is a writer that does not bump `updated_at`,
combined with xid wraparound.

**XR-07 — recognised by PostgreSQL's report.** SQLSTATE 42703 and its message
naming column `response_headers` of relation `idempotency_keys`, never the
exception text (Laravel appends the SQL, which always names the column).
Both tests use PostgreSQL's own exceptions, produced inside a savepoint and
raised from `updating`, because a failed statement in the test's transaction
would abort it and hide the fallback. The populated rehearsal measures the
missing column with no test transaction at all.

**XR-03 — evidence is not the mover's to rewrite.** Checked before anything is
published (dry run included) and again under a row lock inside the flip's
transaction; an identical row is returned untouched; a conflicting one rolls
the flip back. This is an inconsistent-state recovery case — a ledger row
naming bytes other than those at the path — not a demonstrated upload exploit.

**NOT RUN in this round:** a transaction-mode pooler (none available here); a
real network partition or database failover (the harness terminates one
backend); Octane or other long-lived workers (the pin is cleared in `finally`,
not exercised across requests in one worker); a session-mode pooler (the
reconnection repair was measured on a direct connection only); xid wraparound
against a held tag (freezing itself was measured: xmin preserved); the signature mover over S3 or any non-local
disk; any device run.

## 0a. Remaining findings — actionable

Each entry gives what goes wrong in plain terms, how it is known, and the
smallest repair. "Demonstrated" means a test reproduces it. "Inspection" means
it was found by reading. "Policy" means the code does what it was built to do
and the question is whether that is what the business wants.

### S3-09e — a retried edit lost its version tag — **REPAIRED locally, `b79f182`**

* **What went wrong.** The mobile app edits an item or customer with `PATCH`,
  sending the version tag (`If-Match`) it last received. If the connection
  dropped and the app retried under the same idempotency key, the server
  answered from its stored reply — without the `ETag` header. The app then had
  no tag for its next edit and got `428`. It stayed stuck until it re-loaded the
  record.
* **Class:** demonstrated (`MobileIdempotencyReplayFidelityTest`, RED then GREEN).
* **Impact:** availability only. No duplicate write, no cross-shop effect.
* **Repair:** a nullable `idempotency_keys.response_headers` column stores
  exactly `ETag` and `X-Has-Entity-Tag`, and replays restore them. Since XR-07
  (`751acc4`) it is written in the same statement as status and body. If the
  column is missing (a rollback under this code), the claim falls back to the
  legacy status-and-body write and still resolves — re-measured with the column
  really dropped. Since `a3de74d` only a genuine 42703 naming that column
  qualifies; any other failure leaves the claim unresolved (§0c). **Correction:** the earlier second statement left a window in
  which a concurrent retry replayed success without the tag (§0b, XR-07).
* **Release:** the migration goes out with the code, table first (condition R3).

### S3-12 — two edits in the same second: the second can silently win — **REPAIRED locally, `69b4439` (XR-05)**

* **What goes wrong.** An item's or customer's version tag is derived from
  `updated_at`, which the database stores to the whole second
  (`timestamp(0)`). If operator B saves between operator A's read and A's save,
  within the same second, A's save still passes the version check and
  overwrites B's change. Both see success.
* **Affected operations:** `PATCH /api/mobile/v1/items/{item}` and
  `PATCH /api/mobile/v1/customers/{customer}`.
* **Class:** demonstrated — version identity by `EntityTagResolutionTest`
  (its defect locks are now inverted to pin the repair), atomicity by
  `etag_race.php`. Present in the deployed baseline; not introduced by this
  branch.
* **Impact:** a silently lost edit to a catalogue or customer row. It needs two
  writers inside one second, or two writers whose checks both land before
  either write. Not a ledger or tenant defect.
* **Repair (XR-05):** the tag hashes PostgreSQL's `xmin` — the id of the
  transaction that wrote the row version — alongside id, `updated_at` and
  class, and `saveIfMatch()` re-checks If-Match against the row locked
  `FOR UPDATE` before writing. Since `412c8ea` the version and the data come
  from one row image, so a response's tag always describes the data in it
  (§0c). The review showed version identity alone was not enough: two writers
  from one version both passed a check against their own loaded copy.
  Measured by `tests/Concurrency/etag_race.php` (§0b).
* **Superseded proposal.** An earlier revision proposed `timestamp(6)` on both
  tables and called it the smallest repair. It is not needed, and the
  next-day rounding hazard it carried (`'2026-09-23 23:59:59.6'::timestamp(0)`
  is `2026-09-24 00:00:00`, measured) does not arise, because no column type
  changes.
* **Transition cost:** every tag changes once at deploy. An edit screen open
  across the deploy gets one 412; the app refetches.
* **Status:** repaired locally. It predates the branch, and the repair now ships
  with this release's code.

### S3-13 — editing a quick bill re-states its supply type

* **What goes wrong.** Saving any edit to a quick bill re-captures its shop
  snapshot on the shared save path. If the shop switched between intra-state and
  inter-state since the bill was issued, the edited bill prints the new
  presentation (IGST vs CGST+SGST) under its **original** number.
* **Class:** demonstrated (`QuickBillOriginalReprintTest` Q-02). Present in the
  baseline.
* **Impact:** presentation only. The bill number and every stored figure were
  verified unchanged across the edit; total tax is identical either way.
* **Smallest repair:** re-capture the snapshot only on creation. This is a
  one-line guard on the shared path in `QuickBillService`.
* **Why not done:** there are two defensible readings. An edit can be a
  re-issue, which should re-state, or a correction, which should not. That is a
  billing-policy decision, not a code defect to fix by choosing one.
* **Status:** OPEN. Policy question; not a condition of this release.

### S3-14 — web retail POS accepted another shop's payment account — **FIXED locally, `9f633e1`**

* **What went wrong.** `POST /pos/sell` (retail) validated
  `payments.*.payment_method_id` only as an integer and wrote it to the
  append-only `invoice_payments` row. Every sibling path checks the account's
  shop; this one did not.
* **Class:** demonstrated. Shop A's sale was accepted (200) with shop B's UPI
  account on a ₹10,300 payment row. Shop B then **could not delete its own
  account**: the foreign key's `ON DELETE SET NULL` must update A's row, and
  the append-only guard refuses ("Append-only: invoice_payments rows cannot
  be updated"). A's row can never be corrected, for the same reason.
* **Repair:** `Rule::exists('shop_payment_methods','id')->where('shop_id', …)`.
* **Release check (read-only, not run on any server; syntax checked on
  `jewelflow_testing`):** whether production already holds such rows —
  `select ip.id, ip.shop_id, spm.shop_id as account_shop_id from invoice_payments ip
  join shop_payment_methods spm on spm.id = ip.payment_method_id where ip.shop_id <> spm.shop_id`.
  Any row found is append-only and needs its own decision; nothing here
  corrects it.

### S3-15 — karigar invoice accepted another shop's job order — **FIXED locally, `9f633e1`**

* **What went wrong.** `POST /karigar-invoices` validated `job_order_id` only
  as an integer and stored it.
* **Class:** demonstrated (stored). No confidentiality or cross-shop write
  path was found: every reader goes through the scoped `jobOrder` relation.
* **Repair:** the same shop-constrained `exists` rule.

### S3-16 — scheduled loyalty expiry expires nothing — **mechanism REPAIRED locally (`5efc66f`), inactive; activation OPEN (decision, §0d Part 5)**

*Superseded by §0d Part 5; the original entry follows as the record.*


* **What goes wrong.** `loyalty:expire` (daily) calls a shop-scoped service
  with no tenant context: every query is `AND 1 = 0`, nothing expires, and the
  command reports success. No cross-shop effect — the scope fails closed.
* **Why it is not repaired here.** Entering each shop's context was tried: the
  service's `update(['expired' => true])` is then refused by
  `loyalty_transactions_append_only_trigger`, which is constitutionally
  protected (Article IX.A #9) and post-dates the expiry code. A repair needs an
  append-only representation of expiry, and its first run would remove every
  overdue balance at once — a product and accounting decision.
* **Evidence:** `TenantBackgroundExecutionTest` pins the current behaviour
  (characterization, to be inverted when decided).

### S3-17 — every queued export fails after writing its file — **REPAIRED locally, `cfda2a0`** (§0d Part 4)

*The decision this entry asked for was taken by the requirement: the frozen
plan requires the in-app notification, so the table is created and the
channel kept. The original entry follows as the record.*


* **What goes wrong.** `ExportReadyNotification` uses the `database` channel;
  no migration creates a `notifications` table. The job stores the file, marks
  the export done, then the notification insert fails, the export is marked
  **failed**, and the user is never told. Reporting reliability, not isolation.
* **Evidence:** the real worker harness, every run (`relation "notifications"
  does not exist`).
* **Decision needed:** create the table (a migration) or change the channel.
  Whether production has such a table was **not observed**. *Corrected
  (review of `592d864`): the table's presence alone decides neither whether
  S3-18 was reachable in the past nor whether anything was exposed — R10
  keeps configuration, history, logs and disclosure apart.*

### S3-18 — two shops' queued exports could share one file — **FIXED locally, `11e91c3`**

* **What went wrong.** Files were stored at
  `reporting-exports/{report}-{Ymd-His}.{ext}`: no shop, no export id. Two
  exports of the same report and format finishing in the same second — any
  shops — got one path; the second overwrote the first, and the first shop's
  download served the second shop's data. The expiry sweep deletes by path, so
  it could delete the other shop's file too.
* **Class:** demonstrated twice — by a real queue worker (shops 5 and 6, one
  second, one path, shop 5's file holding only shop 6's customer), and in
  `QueuedExportIsolationTest` with the clock frozen, where shop A's owner
  followed the signed link from its own ready-notification and received 200
  with shop B's customer as the only row.
* **Repair:** one directory per export, `reporting-exports/{shop}/{export}/`;
  the file name users see is unchanged. Flat files already stored are left.
* **Existing exports — contained, `b2b6e77` (§0d Part 1):** no file outside
  its export's own directory is served (410); nothing is moved, deleted or
  reassigned.
* **Release check — corrected (§0d Part 1).** The query that stood here
  grouped by `file_path` alone and called every group "a collision that
  already happened". Wrong on three counts: two disk names can mean one place
  and one path on two disks is two files; a group of one shop's rows is not a
  cross-shop question; and shared metadata shows neither the bytes served nor
  a disclosure. Replaced by `reporting:audit-export-files` and the four
  questions of §0d Part 1.

### S3-02 — karigar invoice attachments on the public web path

* **What goes wrong.** Karigar invoice files uploaded by the deployed baseline
  sit under `storage/app/public`. Anyone holding a URL can fetch them, with no
  login, because nginx serves that tree.
* **Class:** inspection, confirmed by the files' location. The last read-only
  production inventory counted **one** karigar attachment.
* **Impact:** confidential supplier documents readable by anyone holding a URL.
* **Repair, local:** complete. New uploads go to the private disk; the
  authenticated route reads each row's recorded disk (`KarigarInvoiceAttachmentTest`);
  `karigar-invoices:relocate-attachments` moves existing files with digest
  verification (`KarigarInvoiceRelocationTest`).
* **Status:** OPEN until run. **Release condition R4** closes it: deploy (R3),
  relocate, verify, then a separately approved purge.

### S3-03 — purchase invoice images on the public web path

* **What goes wrong.** The same as S3-02, for stock-purchase invoice images.
* **Class:** inspection, confirmed by the files' location. The production count
  of purchase images is **not known here** — it is inside the "13 across all
  confidential classes" figure.
* **Repair, local:** complete as of this round. New uploads go to the private
  disk and the authenticated route was already on the branch.
  **`purchases:relocate-invoice-images` (`9d48331`) is new**: before it,
  existing purchase images had no remediation path.
  `PurchaseInvoiceImageRelocationTest` includes the consumer check that the
  route serves identical bytes before and after.
* **Status:** OPEN until run. **Release condition R5.**

### S3-04b — the settings page could not show a newly uploaded signature — **REPAIRED locally, `51ca987`**

* **What went wrong.** Found this round. Since S3-04 moved new signatures to the
  private disk, the settings page still pointed its preview at `/storage/…`, so
  an owner who uploaded a signature saw a broken image.
* **Class:** demonstrated (RED through the real upload route). A regression from
  this branch's own S3-04 change.
* **Repair:** the preview uses the printed bill's hardened read and embeds the
  image inline. A settings-only user still gets the page; the mutation that
  borrows the print path's gate fails with 403.

### S3-06b — turning on the shopfront publishes every in-stock item

* **What happens.** Consent is per shop, publication is per item. Enabling the
  public shopfront lists every in-stock item — design, price, photo — with no
  way to leave one out.
* **Class:** policy (characterized by C-03). The code does what it was built to
  do.
* **Impact:** a shop may publish more than it intended.
* **Smallest repair:** a product decision first. A per-item flag is the
  obvious shape, but **no per-item control is invented here** to settle a
  classification question.
* **Status:** OPEN policy question. Not a condition of this release: baseline
  behaviour, unchanged by the branch.

### S3-06c — item photos stay reachable after the shopfront is turned off

* **What happens.** Turning the shopfront off makes its pages 404 but moves no
  files. A photo URL that was ever shared, logged or screenshotted keeps working
  (`/storage/items/<ULID>.webp`). The names carry 80 random bits, so they
  cannot be guessed.
* **Class:** policy, with a measured behaviour (C-07).
* **Impact:** photos outlive the owner's decision to unpublish.
* **Smallest repair, if the decision is to unpublish:** serve item images through
  a route that checks the shopfront gate, and move them off the public disk,
  like S3-02/S3-03. That is a feature-sized change with its own consumers — the
  POS and inventory views read item images today.
* **Status:** OPEN policy question. Not a condition of this release: baseline
  behaviour, unchanged by the branch.

### The three routes with no service-layer duplicate guard — not a separate defect

The routes are `POST /api/mobile/v1/cashbook/drawer-check`,
`POST /api/mobile/v1/job-orders` and `POST /api/mobile/v1/uploads/intent`.

**Required protection:** a retry of the same operator intent must not repeat
the effect. A genuinely new intent — a second real drawer count — must be
allowed.

**Does the middleware provide it?** Yes, for any client that keeps one key per
intent:

* The repaired `EnsureIdempotency` stakes a claim before the controller, so a
  same-key retry is replayed or refused, never re-run. This is measured under
  real concurrency on the same shared code (§7c-3).
* Every 4xx on these routes happens before any write (§7c-6), so releasing the
  key on a 4xx cannot repeat an effect.

A service-layer guard could not do better. The server cannot tell an
accidental resubmit from a genuine second count except by the key — the key
*is* the intent identifier.

**Clients.** Only `drawer-check` has a consumer in the mobile app at
`2cad553`. It keeps the key in a `useRef` and rotates it only on success
(`app/cashbook/drawer-check.tsx:85-91`), pinned by "createDrawerCheck — key
ownership" in `src/api/cashbook.test.ts`. `job-orders` and `uploads/intent`
have **no consumer** in the mobile repository, so no client can currently
break key discipline on them.

**Residual, stated as a contract rather than a defect:** a future client that
mints a key per tap would duplicate on these routes with no server backstop.
That is exactly how S3-09c happened on cashbook. The worst case is
`job-orders`, where the same gold would be issued twice. Any new consumer must
hold one key per intent.

---

## 1. Three statuses, kept apart

These are routinely collapsed into one another, and collapsing them is how "we
wrote tests" becomes "it is fixed in production".

| Finding | Finding status | Local fix status | Deployed remediation |
|---|---|---|---|
| S3-01 KYC documents publicly readable | OPEN | Containment package written, not executed | **OPEN — nothing applied** |
| S3-01c legacy KYC rows | OPEN | Route fix committed (`4b6e5f3`) | **PENDING — not applicable is wrong; it needs the deploy** |
| S3-02 karigar attachment public | OPEN | Authenticated route + relocation command | **OPEN** |
| S3-02b karigar CHECK lacks the allowed-disk term | OPEN (logged, deliberately not fixed) | None by design | N/A |
| S3-03 purchase attachment public | OPEN | Authenticated route committed; relocation tooling `purchases:relocate-invoice-images` (`9d48331`) | **OPEN** — until relocation runs (§0a, R5) |
| S3-04 signature public + mutable | OPEN | Option B implemented; immutability proven end to end. S3-04b (settings preview broken for private signatures, a regression from this change) FIXED `51ca987` | **OPEN** |
| S3-05 finalized invoice reprints with today's settings | **FIXED for the asserted fields below and, since XR-06 (`d31ab16`), the parties' identity; the seller's contact line stays live pending a decision (D-17).** **Correction:** this cell said "every ASSERTED field" — identity had been missed (§0b). Tax (`igst_mode`, HSN), tax identity (`show_gstin`, `gst_number`), terms, and all payment instructions now come from the bill's own snapshot. RENDERING fields (theme, font, paper, subtitle, tagline, other `show_*`) stay live **on purpose**; D-12 pins that bound. Quick bills carry less and the gap is named (§7d) | Fix `b216b80` (tax half) + `306aae1` (asserted half) + `e8e039e` (original-reprint path, 3 tests / 21 assertions); drift file 16 tests / 89 assertions then, 20 / 129 at `d31ab16`; 3 mutations run | **OPEN** — local only, not deployed |
| S3-06 catalog tenant context survives a throw | **CLOSED as a code defect** — no cross-tenant read demonstrated | Fix committed (`721c06d`), 5 tests | **OPEN — needs the deploy** |
| S3-06b enabling a shopfront publishes every in-stock item | OPEN — product-consent gap, not a tenant break | Characterized (C-03), deliberately not repaired | N/A — feature decision, not an audit repair |
| S3-06c published item images outlive the shopfront toggle | OPEN | None — recorded limitation | **OPEN** |
| S3-07 mobile payment cache-hit path was unauthorized | **CLOSED as a code defect** — no exploit against shipped code; the binding blocked it | Guard committed (`0296431`), 9 tests | **OPEN — needs the deploy** |
| S3-07b payment retry integrity (cache/commit not coordinated) | **REPAIRED LOCALLY** — both defects closed; two more found by real concurrency and fixed; **rollback to the deployed baseline is measured UNSAFE** | Characterized `893a49b` (3 tests) → repaired `2dd0875` → claim scope `0cdd794` → claim staked before validation + P-14 `7d20e08` → rollback evidence, P-06 relabel, P-15 `b1a52f0`. 15 tests, 250 in band | **OPEN — needs the deploy, under the constraints in `payment-idempotency-rollback-constraints.md`** |
| S3-08 static memoization across a long-lived worker | **CLOSED — examined, not a tenant break** | None needed; one inaccurate docblock noted | N/A |
| S3-09 `EnsureIdempotency` records completion AFTER the controller, outside any transaction | **REPAIR IMPLEMENTED; VERIFICATION INCOMPLETE.** Supported conclusion is narrow: pre-staking blocks same-key automatic re-execution *while the claim is retained*. It does NOT by itself establish atomic business completion, recoverable successful replay, or that key retention survives pruning | Characterized `8cddbc3` → repaired `b8673db`. 17 tests / 89 assertions; 9 contract tests still green; 273 passed (1028 assertions) across `Feature/Security` + `Feature/Mobile`, no regressions. Concurrency is now **REAL multi-process** (§7c-3): pre-repair blob `6a8c4cc` produced 4×201 / 4 cash rows / sum 10000 under one key; repaired produces 1×201 + 3×409 / 1 cash row. Recoverable successful replay is now measured too | **OPEN. Closed sub-items: 4xx-release safety (§7c-2), real concurrency (§7c-3), mobile consumer handling (§7c-4). Still open: retained-claim disposal (§7c-1 — the pruning *code* defect is fixed in `e68eb31`; what remains open is an operator decision, since a retained unresolved claim has no supported clearing path and growth is slow but unbounded), 12 routes now REVIEWED (§7c-6) — no write-then-4xx on any of the 16, so the release-on-4xx policy is supported rather than assumed; three routes (`drawer-check`, `job-orders`, `uploads/intent`) have no duplicate guard and inherit S3-09c; two NEW defects found by testing: S3-09e since REPAIRED locally (`b79f182`), S3-12 characterized here, since REPAIRED locally (`69b4439`, XR-05; §0b). New limit found — payload-conflict detection is sequential-path only; a concurrent different-payload loser gets `idempotency_in_flight`, not `idempotency_key_conflict`** |

### Finding IDs — old → new, because they drifted

IDs changed meaning between reports, which is a defect in the tracker rather
than in the code. The mapping below is authoritative; **nothing is retired by
renumbering.**

| ID as used NOW | Meaning | Previously used for | Where that older item lives now |
|---|---|---|---|
| S3-05 | Finalized reprints drift to today's settings | Signature **relocation** | Relocation is §7, tracked under S3-04's local fix status |
| S3-07 | Mobile payment idempotency cache | **File repairs** (purchase/karigar) | Repairs remain S3-02 / S3-03, still OPEN in the matrix above |
| S3-07b | Payment retry integrity | *(new)* | — |
| S3-08 | Static memoization sweep | *(new)* | — |
| S3-09 | `EnsureIdempotency` middleware retry integrity | *(new — ID confirmed unused before assignment)* | — |
| S3-09b | Pruning must not delete unresolved claims | *(new)* | §7c-1 |
| S3-09d | Payload-conflict detection is sequential-path only — a concurrent different-payload loser is refused as `idempotency_in_flight`, never `idempotency_key_conflict` | *(new — ID confirmed unused before assignment)* | §7c-3 |
| S3-09c | Mobile consumer handling for an uncertain outcome | *(new)* | §7c-4 |
| S3-10 | `CashBookController::store` / `storeDrawerCheck` write subject and audit non-atomically | *(new — ID confirmed unused before assignment)* | §7c-5, FIXED |
| S3-09e | Replayed responses drop every header, wedging the two `PATCH` routes whose contract needs `ETag`. **Predates S3-09** — pre-repair blob `6a8c4cc` behaves identically | *(new — ID confirmed unused before assignment)* | §7c-6; **REPAIRED locally `b79f182`** (§0a) |
| S3-11 | `ReturnService::approveReturn` cancels the pending header, then can fail | *(new — ID confirmed unused before assignment)* | §7c-2, FIXED |
| S3-12 | `If-Match` validator is one-second granular (`updated_at` is `timestamp(0)`), so a concurrent write is silently lost | *(new — ID confirmed unused before assignment)* | §7c-6, characterized; since REPAIRED locally, `69b4439` (XR-05, §0b) |
| S3-13 | Editing a quick bill re-captures `shop_snapshot` on the shared save path, so an edit to an unrelated field re-states the supply type on a bill that keeps its number. **Presentation only** — bill number and every stored figure verified unchanged | *(new — ID confirmed unused before assignment)* | §7d-1, CHARACTERIZED, NOT REPAIRED |
| S3-04b | The settings page previews the current signature from `/storage/…`, broken for every signature stored on the private disk since S3-04 — a regression from this branch | *(new — ID confirmed unused before assignment)* | §0a, **REPAIRED locally `51ca987`** |

Still visible and unclosed, listed explicitly so renumbering cannot bury them:
repairs (S3-02, S3-03), uploads (`uploads/` at zero files, no publication
decision recorded), the tracked backup repair, relocation preparation, and
publication classification for item images (S3-06b/S3-06c).

### Corrections to my own earlier reports, restated here so they are not lost

* **S3-02 counts.** The read-only production metadata inventory reports **one**
  karigar attachment. Thirteen was the total across all confidential classes,
  not the karigar figure. I previously reported the larger number against the
  smaller finding.
* **"12 files is small enough to relocate by hand" is WITHDRAWN.** The count was
  never the risk. A manual copy gives no digest verification, no record of which
  rows were flipped, no resumption point, and no way afterwards to distinguish a
  file that was missed from one that was truncated. Superseded by
  `signatures:relocate`.
* **`uploads/` is explicitly tracked at zero files.** Zero today is not an
  argument that the directory is out of scope; it is a directory with no
  published-content decision recorded against it.
* **"~25 live billing reads in the print view" was wrong.** The count is **45**
  (`resources/views/invoice_print.blade.php`, 2026-09-21).
* **The signature work does not make historical rendering immutable.** It makes
  the *signature* immutable. See S3-05.
* **My own immutability tests were resting on their fixtures.** Detailed in §3.
* **Item images were mis-filed.** An earlier note listed
  `storage/app/public/items` beside `signatures/` and `kyc/` as
  "candidate-public assets pending classification", as though the three were
  alike. They are not. Signatures and KYC documents have **no feature that
  publishes them** — their public location is an accident of a default disk.
  Item images have a deliberate publishing feature: `routes/web.php` 94-100
  serves an unauthenticated shopfront at `/s/{slug}` that renders them by public
  URL on purpose, gated by `catalog_website_settings.is_enabled`, which is
  `default(false)` (`2026_04_01_000002_…:14`). This is the directive's "folder
  names do not establish publication consent" answered from the other side: the
  folder name did not establish consent here either — the **feature and its
  opt-in default** did. Verified by test, not by reading the route table:
  `PublicCatalogExposureTest` C-01/C-02 pin both halves.

---

## 1a. The earlier review requests, accounted for one by one

Four check families were asked for before this release could be called ready.
Status is given against the check as asked, not against the nearest thing that
happened to be done.

| # | Check as asked | Status | Evidence |
|---|---|---|---|
| A1 | Signature relocation identity — the right file follows the right row | **DONE** | G-25 follows a recorded, digest-verified relocation; G-26 refuses one whose digest no longer matches; G-29 pins re-recording as idempotent; R-17 records a digest-matched relocation in the ledger |
| A2 | Conflicting files with the same name across disks | **DONE** | G-24 a public reference does not follow an unrecorded private file; G-27 a private reference never falls back to the public tree; G-28 another shop's relocation does not vouch for this shop's reference; G-31 a legacy flat path does not follow another shop's relocation |
| A3 | Interrupted relocation runs | **DONE** | R-01 the default run is dry and moves nothing; R-08 running twice is a no-op; R-06 a row whose source is missing keeps its recorded disk; R-09 `--shop` bounds the blast radius; R-18 purge refuses an original with no recorded relocation; R-12 refuses wholesale when any private copy is missing |
| A4 | Silent substitution prevented | **DONE** | G-12 untrusted disk name refused rather than read; G-17 traversal path refused on a trusted disk; G-30 a path outside the shop's signature directory refused; G-20 a signature absent from every allowed disk is *reported* missing rather than replaced |
| B1 | Baseline application behaviour against the proposed migrations | **DONE** | T-01/T-03/T-05 reject baseline-shaped writes once the contract phase exists; T-02 is the expand-only positive control; T-07 pins the reconciliation sweep; T-06 asserts `convalidated` |
| B2 | Signature creation / replacement / removal across the window | **DONE** | T-01 creation, T-04 path-only update over an existing disk, T-03 removal — the deployed remove branch nulls the path and strands the disk, which violates the CHECK from the other direction |
| B3 | Application rollback after private uploads | **DONE as analysis, NOT as a drill** | §6 states the asymmetry: phase-3 `down()` is freely reversible; phase-1 rollback drops the only record of which files went private or were relocated, so it is one-way after any private upload. **No rollback was rehearsed against a populated database.** |
| C1 | Finalize → A → B → disable/remove → reprint, **invoices** | **DONE** | E-02 (A survives B's replacement *and* signatures being disabled), E-03 (a later invoice gets B), E-04 (finalized-while-disabled stays unsigned), G-09 (…and then removed), G-10 (replacing does not delete the previous file), G-16 (replacement through the real settings route) |
| C2 | The same chain for **quick bills** | **DONE** | E-05 (A survives replacement + disable), E-06 (a later bill gets B), G-18 (quick-bill print embeds and emits no storage URL) |
| C3 | Relocated-then-purged signature still prints | **DONE** | E-07, R-14 |
| D1 | Vite rendering failures | **RESOLVED — environmental** | 63 failures, all `Vite manifest not found`, 212 occurrences, no second cause. `.gitignore:20` excludes `/public/build` and `git worktree add` materialises only tracked files. Fixed by building assets in the worktree. Not caused by, and did not mask, any branch change. |
| D2 | The three skipped tests | **EXPLAINED — below** | all three in `ConstitutionalInvariantsTest`, one root cause |
| D3 | Two-copy vs three-copy | **RESOLVED — there is no three-copy path** | below |

### D2 — the three skipped tests, named

The earlier "3 skipped" was a count that was never broken out. Under
`--display-skipped`:

| Test | Skip message | What goes unverified |
|---|---|---|
| `invoice_items_finalized_guard_blocks_update` | "No invoice_items rows available to test the guard trigger against." | whether the Art. IX.A trigger *fires* |
| `enabled_metals_for_shop_returns_tier_1` | "No shops exist to test enabledMetalsForShop against." | `MetalRegistry` tier resolution |
| `stone_snapshot_guard…` | "No snapshotted stone_components row available." | whether the stone-snapshot guard *fires* |

One root cause for all three: each does `SELECT … LIMIT 1` against the **ambient**
database instead of building its own fixture, and in `jewelflow_testing` those
tables are empty. Measured: `invoice_items` 0, `stone_components` 0,
`credit_notes` 0, `shops` 0.

**Not this branch's doing.** `git diff 018b3d8..HEAD -- tests/Feature/ConstitutionalInvariantsTest.php`
is empty; the file was last touched in `defb62c` (2026-05-28).

**Positive control, so the gap is stated at its real size.** The skips hide
whether the triggers fire, not whether they exist. From `pg_trigger`:

```
credit_notes        credit_notes_accounting_guard_trigger      enabled=O
credit_notes        credit_notes_numbering_event_trigger       enabled=O
invoice_items       invoice_items_finalized_guard_trigger      enabled=O
stone_components    stone_components_snapshot_guard_trigger    enabled=O
```

All present, all `tgenabled = 'O'`. The firing behaviour of two
constitutionally-protected triggers is nevertheless unverified on an empty
database, and a data-dependent skip is a coverage hole that reports itself as a
pass. The fix is to give those three tests fixtures. Out of scope for this
branch; logged so it is not lost.

### D3 — two copies versus three

There is no three-copy path anywhere in the code. Five layers agree on a maximum
of two, independently:

| Layer | Value |
|---|---|
| Column | `unsignedTinyInteger copy_count` default 1 (`2026_03_25_200000:30`) |
| Settings UI | `<select>` offers only `1` and `2` (`settings.blade.php:2917-2919`) |
| Validation | `'copy_count' => 'nullable\|integer\|in:1,2'` (`SettingsController:498`) |
| Invoice template | `max(1, min(2, $copyCount))` (`invoice_print.blade.php:51`) |
| Quick-bill template | `max(1, min(2, $copyCount))` (`quick-bills/print.blade.php:49`) |

The column type would accept 255; validation plus both clamps make that
unreachable through the application. **The discrepancy was in my reporting, not
in the code** — "three copies" appears in no template, controller, migration or
test. G-14 measures the payload at the real supported maximum, two.

### The "43 live-setting reads" — classified, and the count corrected

The directive asked for these to be classified against the snapshot contract,
and for "cosmetic" not to be asserted without checking effects. Doing that
invalidated the number as well as the label.

**The count.** Measured on `invoice_print.blade.php`, not carried forward:
`$billing?->` appears 44 times over 26 distinct fields; `$shop?->` 26 times over
13 distinct fields. The old "43" counted only `$billing?->`, was off by one
against even that, and omitted shop identity entirely.
`quick-bills/print.blade.php` adds 14 more occurrences over 12 distinct fields.

**The classification**, by what the printed document *asserts* with the field:

| Class | Fields | Drifts? | Consequence | Already in the snapshot? |
|---|---|---|---|---|
| **Statutory / transaction** | `igst_mode`, HSN map | **No — repaired** | would re-characterize an issued supply | yes, and read |
| **Printed business identity** | shop `name`, `gst_number`, `address`, `address_line1/2`, `city`, `state`, `state_code`, `pincode`, `phone`, `shop_whatsapp`, `shop_email`, `shop_registration_number`; `shop_subtitle`, `custom_tagline`, `show_gstin` | **Yes** | a reprint shows a GSTIN other than the one the supply was made under — a required particular of a tax invoice | **yes, all of them; nothing reads them** |
| **Payment instructions** | `upi_id`, `bank_name`, `bank_account_holder`, `bank_account_number`, `bank_ifsc`, `bank_account_type`, `bank_branch`, `bank_details` | **Yes** | a customer settling from a reprint is given account details that were never the ones issued | **yes; nothing reads them** |
| **Terms** | `terms_and_conditions` | **Yes** | the document cannot evidence its own terms in a dispute | **yes; nothing reads it** |
| **Layout preference** | `theme_color`, `font_size`, `paper_size`, `show_huid`, `show_stone_columns`, `show_purity`, `show_customer_address`, `show_customer_id_pan`, `show_mode`, `show_time`, `show_bis_logo`, `copy_count`, `invoice_copy_label`, `second_signature_label` | Yes | none — the bill asserts the same facts | mostly |

Only the last row is cosmetic. Three of the other rows were **measured**, not
inferred: each test was first written asserting the desired behaviour and run.
The recorded failures are

```
D-09  Not to contain: 99990000                             (reprint carries the replacement A/C)
D-10  Not to contain: No exchange under any circumstances.  (reprint carries the replacement terms)
D-11  Not to contain: 29BBBBB9999B1Z5                       (reprint carries the replacement GSTIN)
```

then inverted to characterization and committed green (`2a6c7ce`,
`FinalizedInvoiceSettingsDriftTest` D-09…D-12 — file was 12 passed, 48
assertions at that point). **Those three have since flipped back to asserting
the desired behaviour, because the repair landed — see §7d.** The failures
above are retained as the measured pre-repair evidence they were derived from.
D-12 is the bound: theme colour drifts by the identical mechanism
and genuinely is cosmetic. Without it this reads as "live reads are bad", which
is the overreach the directive warned against; with it the finding is the
narrower one — the drift is uniform, the consequence is not.

**One observation recorded without being reported as a finding.** Both print
templates read `auth()->user()->shop`, i.e. the *viewer's* shop, not
`$invoice->shop`. Tenant scoping makes the two coincide, so this is **not** a
cross-tenant issue and is not claimed as one. It is noted only because the
correct source is one hop away and is already snapshotted.

**Size of the outstanding repair.** Smaller than the finding sounds.
`InvoiceRenderSnapshotService:91-132` already persists every field in the
identity, payment-instruction and terms rows at finalization; the templates
simply never read them. That is a template change, not a schema change. Not done
here — it alters what every reprint in the system prints, and belongs in its own
reviewed commit.

---

## 2. KYC containment — awaiting explicit approval, not executed

Full package: `docs/runbooks/kyc-public-exposure-containment.md`.

Impact is stated as **"no identified first-party viewing regression"**. External
consumers remain unverified; that is a gap in the evidence, not a clean bill.

**Serving hostnames, named rather than counted.** Production vhost
`/etc/nginx/sites-available/jewelflow` line 24 serves `jewelflows.com`,
`www.jewelflows.com` and `dhiran.jewelflows.com`. Staging is a separate vhost
and a separate document root (`/var/www/jewelflow-staging/public`) and is
untouched by this package. `dhiran.jewelflows.com` is in scope **only** as a
third name resolving to the same document root — no part of this expands the
unrelated Dhiran business audit.

**Corrected Cloudflare expression.** `matches` requires Business or Enterprise,
so it is not available here:

```
(starts_with(http.request.uri.path, "/storage/kyc/") and http.host in {"jewelflows.com" "www.jewelflows.com" "dhiran.jewelflows.com"})
```

**Purge entries are hostname-qualified.** A bare `/storage/kyc/` is incomplete —
Cloudflare keys the cache per hostname:

```
jewelflows.com/storage/kyc/
www.jewelflows.com/storage/kyc/
dhiran.jewelflows.com/storage/kyc/
```

Sequence remains **edge block → origin deny → purge**, subject to the ordering,
normalization, alias, HTTP/HTTPS and direct-origin checks recorded in the
runbook. Rollback is written so it **cannot silently restore public document
access**: reverting the edge rule alone leaves the origin `deny all` in place.

**BLOCKED dependency, named exactly:** no Cloudflare dashboard or API credential
is available to this session. The edge rule cannot be created or verified by me.
The origin-only option (§4a of the runbook) is independently approvable and does
not depend on it.

Cloudflare can convert a cacheable HEAD into an origin GET and cache the full
response, so verification uses a nonexistent probe path, not a real document.
**Edge verification, corrected:** a 403 at the edge does not show the edge rule
works — once the origin deny exists, Cloudflare passes the origin's 403 through.
The decisive check is a probe path unique to the run with **no line for it in
the origin access log**, beside a positive control showing that log does record
a probe that reached the origin (runbook §6).
No real KYC file has been repeatedly probed. Any production canary must be part
of the separately approved procedure.

---

## 3. S3-04 — what changed this session, and the defect it corrects in my own work

### The defect

`invoice_render_snapshots` had a reader (`InvoiceSignatureRenderer`) and a writer
(`InvoiceRenderSnapshotService::captureForInvoice`). The writer's **only caller
was the `BackfillAccountingSnapshots` console command.**
`InvoiceAccountingService::finalizeDraft()` captured a *compliance* snapshot and
no render snapshot at all.

`InvoiceSignatureEmbeddingTest` G-09 and G-16 assert the immutability property
and pass. Both fixtures call `captureForInvoice()` by hand. They prove the
renderer *reads* a snapshot correctly; they never proved one *exists*. I reported
that they did.

What kept it invisible is the renderer's fallback: with no snapshot row it drops
to live settings and still renders a signature — the wrong one, with no error.

### The fix

One call in `finalizeDraft()`, after the compliance block (which
`buildInvoiceSnapshot()` reads). Deliberately **not** wrapped in try/catch,
unlike the compliance snapshot above it: every caller runs `finalizeDraft` inside
a transaction, so on PostgreSQL a caught failure leaves an aborted transaction
and the following statements fail anyway. "Log and continue" there would be a
comment the database does not honour. *(That applies to the existing compliance
block too. Recorded, not widened into this change.)*

### The decisive regression, driven entirely through real routes

`tests/Feature/Security/SignatureImmutabilityEndToEndTest.php` — nothing captured
by hand, asserted against the HTML an operator would print.

| | |
|---|---|
| E-01 | finalizing via `PUT invoices.update` records a render snapshot |
| E-02 | **finalize under A → replace with B → disable signatures entirely → the invoice still prints A and never prints B** |
| E-03 | positive control: an invoice finalized under B prints B |
| E-04 | the enabled/disabled state is snapshotted too |
| E-05 | the same regression on the quick-bill path, via `POST quick-bills.store` |
| E-06 | positive control for E-05 |
| E-07 | relocate + purge, then the invoice still **prints** the bytes it was signed with |

E-01, E-02 and E-04 were red before the fix and green after.

### A fixture error worth recording, because it masqueraded as the finding

The first draft compared against the bytes it **uploaded**. It could never pass:
`SettingsController` → `SignatureStore` → `ImageOptimizer::optimizeAndStore`
re-encodes every raster upload to WebP under a fresh ULID, so the uploaded bytes
are never on disk. It also distinguished A from B by a trailing byte, which sits
outside the image data and is dropped by GD — both "different" signatures would
have encoded identically. A and B are now told apart by **dimensions**
(ImageOptimizer downscales only), and assertions are against the **stored** file.

This is exactly the failure the evidence discipline exists to catch: E-03, the
positive control, was failing, which is the only reason I did not read E-02's
red as the finding reproducing.

### Attribution by mutation, not by counting

| Mutation | Result |
|---|---|
| Remove the three signature keys from `QuickBillService::shopSnapshot()` | E-05 **killed**. E-06 **survived** |
| Replace the ledger lookup in `InvoiceSignatureRenderer` with `null` | E-07 **killed** |

E-06's survival is legitimate and is the reason it exists. It issues a bill under
B and expects B; with no snapshot the renderer falls back to live settings, which
still hold B. A positive control is *supposed* to pass under the broken
implementation — if it died too it would be duplicating E-05, not bounding it.

Restoration in both cases was confirmed by `git diff` returning empty on the
mutated file **plus** a green rerun of the affected tests. Not by searching for
the absence of a marker word.

---

## 4. Protecting the embedded data

Base64 is an encoding, not access control. What protects it:

* `InvoiceSignatureRenderer::authorize()` runs **before any storage read** —
  active shop must match the bill's shop, and `Gate::denies('sales.view')`
  aborts. Duplicated from the route middleware on purpose: four callers render
  these views, and a fifth must not be able to inline bytes by forgetting a gate.
* Trusted references only: disk restricted to `['public','local']`, traversal and
  absolute paths refused, and the path must be under this shop's signature
  directory. No request input reaches any of it.
* Content validated by `getimagesizefromstring`, not by extension; size checked
  from metadata **before** the bytes are loaded; dimensions bounded at 2000 px.
* Read and encoded once per render, memoized per bill.
* `NoCache` middleware on both web print routes and both mobile template routes
  (G-21, G-22). The framework default already sends an unqualified `private`,
  which forbids *shared*-cache storage; `no-store` adds the private caches that
  permits — the till browser's disk cache and the mobile WebView cache. **No CDN
  has been observed honouring these headers. Do not read G-21/G-22 as edge
  verification.**
* Diagnostics never log the path. `unavailable()` logs a reason and a truncated
  SHA-256 of the path only.

**Measured payload growth at the supported maximum** (G-14, `copy_count` server
max is `in:1,2`; upload max 512 KB):

```
file=524,287 B  base64=699,052 B
html without signature =    69,444 B
html with signature    = 1,467,710 B
growth = 1,398,266 B  (2.00x the encoded length, i.e. once per copy)
```

That is a ~21x document. It is the **bound**, not the typical case: G-14 writes
the maximum-size file directly to disk, bypassing `ImageOptimizer`, which in the
real upload path re-encodes to WebP. `ponytail:` the data URI is repeated once
per copy. Ceiling named — if a real shop's signature ever makes this hurt, the
upgrade path is to emit the base64 once in a `<style>` block as a CSS custom
property and reference it from each copy, which makes growth independent of
`copy_count`. Not done speculatively.

---

## 5. Mobile — verified, and explicitly NOT RUN

Installed printing dependencies (`package.json`): `expo-print ~15.0.8`,
`expo-sharing ~14.0.7`, `react-native-webview 13.15.0`.

Actual call sites — all four, with no others:

```
app/invoice/[id].tsx:95      Print.printToFileAsync({ html })
app/invoice/[id].tsx:117     Print.printAsync({ html })
app/quick-bill/[id].tsx:121  Print.printToFileAsync({ html })
app/quick-bill/[id].tsx:143  Print.printAsync({ html })
```

**`useMarkupFormatter` is passed nowhere in the repository** (grep across all
`.ts`/`.tsx` outside `node_modules`: zero matches). That matters because that
option does not display images. Its absence means the default WebView renderer is
used, which does render `<img src="data:...">`. This is **feasibility evidence
from the real call sites** — stronger than the Expo documentation alone, and
still not proof that it works on a given handset.

**Cache review — a verified negative.** React Query is persisted to AsyncStorage
(`PersistQueryClientProvider`, `src/lib/query-client.ts`), so it was worth
checking whether signature bytes land in cleartext on a shop tablet. They do not:
`shouldDehydrateQuery` persists only an allowlist —
`categories`, `catalog-template`, `catalog-categories`, `material-registry`. The
HTML-bearing query key is `['invoice-template', invoiceId]`, which is not in it.
The quick-bill template is not in the query cache at all; it is fetched
imperatively at `app/quick-bill/[id].tsx:96`. Residual: the invoice template stays
in the **in-memory** cache for up to `gcTime` (24 h). Memory, not disk.

### NOT RUN — exact remaining checks

| Check | Status | Exactly what remains |
|---|---|---|
| Android print preview renders the inline signature | **NOT RUN** | Build a dev client, open a finalized invoice, tap Print, confirm the signature appears in the Android print preview and in the shared PDF |
| iOS print/share renders the inline signature | **NOT RUN** | Same, on a physical iOS device — no simulator, `Print.printAsync` behaviour differs |
| Multiple copies on device | **NOT RUN** | Set `copy_count` = 2, confirm both copies carry the image and the PDF is not rejected for size |
| Desktop browser print dialog | **NOT RUN** | `GET /invoices/{id}/print` in Chrome and Firefox, confirm the image survives into the print preview |
| CSP behaviour for `data:` images | **NOT RUN** | No `Content-Security-Policy` header is currently emitted on the print routes; if one is added, `img-src` must include `data:` |
| Mobile "Signature unavailable" warning | **DONE in mobile `ffcd034`; device render NOT RUN** | Both consumers (`app/invoice/[id].tsx`, `app/quick-bill/[id].tsx`) warn after print/share when a signature was expected and unavailable, and stay silent when the shop has signatures off. Unit-tested (`src/lib/signature-warning.test.ts`, inside the 275 at `2cad553`). This row read NOT DONE until that commit |

---

## 6. Migrations — dependency order

Full reasoning: `docs/runbooks/signature-migration-release-order.md`. **Status:
PROPOSED. Nothing executed anywhere but `jewelflow_testing`.**

| Phase | Migrations | Safe while baseline serves? |
|---|---|---|
| 1 EXPAND | `2026_09_15_120000` karigar disk, `2026_09_15_140000` purchase disk, `2026_09_16_120000` signature disk, `2026_09_20_120000` `signature_relocations` | Yes — nullable columns and a new table |
| 2 APPLICATION | deploy the new code to **every** serving node | — |
| 3 CONTRACT | `2026_09_20_130000` all three both-or-neither CHECKs | **No.** Must not run before Phase 2 completes |

`invoice_payment_claims` and `response_headers` run in Phase 1 too, one file per
command. Each phase has its own drift check, D0–D3 (runbook § Drift checks).

The correction this split exists for: I called the original three migrations
"additive" and treated that as safe-while-serving. The column and backfill are
additive. **The constraint is not** — it forbids a row with a path and no disk,
and that is exactly what baseline `018b3d8` writes on every upload
(`SettingsController:526-527`, `StockPurchaseController:140,354`,
`KarigarInvoiceService:49,114`). Demonstrated by `DiskColumnReleaseOrderTest`
T-01/T-03/T-05; T-02 is the expand-only positive control.

Phase 3 reconciles **immediately before** enforcing, in both directions, because
the Phase 1 backfill ran before the window it authorises. It adds each constraint
`NOT VALID` then `VALIDATE`s it, with explicit per-table transaction boundaries
since XR-04: `ACCESS EXCLUSIVE` only for the catalogue change, released before
`VALIDATE` scans under `SHARE UPDATE EXCLUSIVE`. **Correction:** until XR-04 the
whole migration ran inside the migrator's transaction, so those locks lasted to
its end and blocked reads and writes on every finished table — measured by
`tests/Rehearsal/contract_migration_locks.php`, see §0b. T-06 asserts
`pg_constraint.convalidated`, which is the only signal separating a validated
constraint from one that reports a successful deploy while permanently exempting
every expand-window row. T-07 pins the reconciliation.

`signature_relocations` (`2026_09_20_120000`) is required by
`SignatureRelocationLedger`, which `InvoiceSignatureRenderer` and
`RelocateShopSignatures` both depend on. No ordering relationship to Phase 3.

`invoice_payment_claims` (`2026_09_21_120000`, S3-07b) sits **outside this
sequence entirely** and has no ordering relationship to any phase above. It is a
new table with no FK pointing *into* it, and baseline `018b3d8` neither reads nor
writes it, so applying it early is a no-op — which is the property the runbook
asks of a migration that must land ahead of its application change. It must not
run *after* the S3-07b code, however. **Order is table-then-code, and the gap
between them is safe in only that direction.**

Checked rather than assumed — what code-before-table actually does:
`InvoiceController:234` performs the claim lookup as the first act of a keyed
payment, *before* any money moves. A missing table throws, `:487` catches it and
`:493` aborts **503**. So the wrong order **refuses keyed payments, it does not
double-charge them** — the failure is loud, safe and confined to requests that
send `X-Idempotency-Key`. Unkeyed payments are untouched, since the whole block
is inside `if ($idempotencyKey)`. Still an outage for mobile clients, so the
order stands; but the consequence of getting it wrong is refusal, not loss.

`down()` drops the table. The only loss is replay evidence for keys minted inside
the window, which degrades to the pre-existing legacy cache behaviour rather than
to nothing.

**Rollback is not symmetric.** Phase 3 `down()` is freely reversible. Phase 1
rollback drops the disk columns — which is the only record of which files the new
code put on the private disk, and of which have been relocated. Phase 1 rollback
is **never a recovery step**. Recovery keeps the schema and reverts forward on
top of the release (runbook § Recovery). **Correction:** this paragraph used to
say "revert the code and leave the columns". Serving `018b3d8` on the retained
schema is prohibited while traffic flows: its payment route is cache-only
(RB-1), and it rewrites file paths without their disk columns. A row already
labelled `local` then keeps that label beside a file stored publicly, and
`reconcile()` does not repair it.

---

## 6a. Migration and rollback rehearsal on a populated database — MEASURED (`01f37a6`)

B3 above was "DONE as analysis, NOT as a drill". This is the drill.
`php tests/Rehearsal/migration_rollback_rehearsal.php` applies the branch's
seven migrations to a **populated baseline schema**, rolls them back
newest-first **on the same data**, and re-applies them. Each step is its own
statement, outside any test transaction. It refuses any database not named
`jewelflow_testing` and ends with `migrate:fresh`.

* **Baseline schema:** built from exactly the 373 migration files in `018b3d8`,
  checked against `git ls-tree` (the branch adds seven and modifies none).
  Baseline *application* code is not run; its writes are reproduced by their
  SQL shape.
* **Data:** 3 shops; 1,200 karigar invoices, half of them paid and therefore
  frozen by `karigar_invoices_finalized_guard`; 1,200 stock purchases; 3
  billing settings; 3,000 idempotency claims.
* **Not done, deliberately:** no code rollback. The cache-only payment
  controller is never run — traffic-serving rollback to it is measured as
  double-charging (`b1a52f0`) and stays prohibited. The
  `invoice_payment_claims` down() is a schema step, valid only after the code
  that needs the table is gone.

| Step | Result |
|---|---|
| Expand (4) + claims + headers on populated data | each 5–22 ms; every file-bearing row labelled `public` (801 karigar), none invented; **paid karigar invoices backfilled past the finalized guard**; signatures 2 of 2 |
| `response_headers` added | **no table rewrite** (`relfilenode` unchanged); no headers invented for existing claims |
| Expand window, then contract | 5 + 5 baseline-shaped rows labelled, 1 stranded disk cleared, private labels untouched; all three constraints **VALIDATED**; a baseline-shaped write is then **refused** — hence code before contract |
| New code, full schema | S3-09e replay carries the original ETag |
| Contract down | constraints gone; baseline-shaped writes accepted again |
| Headers down **under new code** | claim still resolves (status 200); replay loses only the headers — the degradation designed in `b79f182`, measured with the column really dropped |
| Claims down | table dropped (schema step only) |
| Expand down, then re-apply | **Phase-1 rollback is one-way, measured:** 11 private-disk labels before, 0 after re-apply — every private file relabelled `public` — and the relocation ledger is empty |
| Everything | nothing pending at the end; **no business row lost** across every up and down |

Result: 26 checks passed and 17 timings recorded; full output in §8.
**Correction:** the commit message of `01f37a6` says "all 30 checks pass". The
run shows 26 `PASS` lines. I wrote the number without counting it.

All three failures in the first run were in the script, not the product. They
are recorded because they looked like findings:

* The pending-migrations check parsed `migrate:status` output. The script now
  compares the `migrations` table to the files instead.
* Both S3-09e checks — the replay, and the headers-down case — made in-process
  requests without tenant context. Route binding 404s under the CLI exactly as
  it does under PHPUnit, so both failed for the wrong reason.

**Limits:** synthetic data at modest volume, one PostgreSQL instance, no
connection pooler, no concurrent traffic during DDL. Lock behaviour under
production load is not measured.

## 7. File repairs and relocation — still OPEN

`signatures:relocate` and `karigar:relocate-attachments` are both dry-run by
default, bounded by `--shop`/`--limit`, copy-and-rehash at the destination before
any metadata moves, write a JSONL manifest, and keep originals until a separately
gated `--purge-originals`. The purge refuses any original with no ledger row
(R-18), and refuses wholesale when any private copy is missing (R-12).

Signatures differ from karigar attachments in a way that drives the design: a
karigar attachment is named by exactly one row, so flipping that row describes
the move completely. A signature is named by the mutable settings row **and** by
every `invoice_render_snapshots` row and `quick_bills.shop_snapshot` captured
while it was current — immutable, unbounded, and explicitly not rewritable. The
command updates the first and never touches the second; the relocation ledger is
what makes that safe, and E-07 proves it on the printed page.

**Still open, and not to be closed by this branch:**

* Public copies remain exposed until containment or an approved purge. Relocation
  copies; it does not remove the public original until the gated purge runs.
* Purchase and karigar repairs stay OPEN until their access paths and **web and
  mobile consumers** are each addressed. Authorized viewing of existing files is
  preserved by the authenticated routes; that is verified locally only.
* Item images and other candidate-public assets stay under review. A folder named
  `public` records no publication decision, and a handful of absent grep matches
  does not prove a dynamically built URL has no consumer.
  **Item images are now classified** (see §7a) — they have a real publishing
  feature, so they are NOT relocation candidates. The remaining public-disk
  destinations are not: `products`, `shop-logos`, `catalog-heroes`,
  `UploadIntentService:110,251` and `Api\Mobile\ItemController:226,495` are still
  unclassified and stay under review. Enumerated by grep over
  `Storage::disk('public')` writes on 2026-09-21; that enumerates *writers*, not
  consumers, and a consumer is what decides publication.
* **Superseded signature versions stay public after R6.** They are named by no
  settings row — only by snapshots. The default pass does not move them, because
  moving a file that no row names would strip the only pointer to it, and the
  purge deletes only originals whose private copy verifies. So after the
  relocation and the purge, every superseded version is still reachable at its
  `/storage/signatures/…` URL. `signatures:relocate --orphans` counts them,
  read-only; the production count is not known here.
  **Option SIG-ORIGIN — independently approvable, not executed, PARTIAL.** Add
  `location ^~ /storage/signatures/ { deny all; }` beside the KYC deny, in the
  same server block and form as KYC §4.2. Why it is safe for first-party use,
  checked at `751acc4`: every signature the application renders is read on the
  server and inlined as a `data:` URI (`InvoiceSignatureRenderer:359`), including
  the settings preview since S3-04b; no view or API builds a `/storage/signatures/`
  URL — the literal prefix appears only in two comments
  (`invoice_print.blade.php:163`, `quick-bills/print.blade.php:122`), and of
  every `digital_signature_path` reader and every public `->url()` builder,
  none takes a signature path; the mobile app has no signature consumer. Reprints of superseded versions read the file through the
  filesystem, which an nginx deny does not affect. Constraints:
  * **after Phase 2 only.** `018b3d8` prints every signature through that URL
    (`invoice_print.blade.php:845`, `quick-bills/print.blade.php:565`,
    `settings.blade.php:2790`), so a deny under the baseline blanks every printed
    signature;
  * **external consumers unverified** — for example an emailed bill that
    references the URL. The access-log check in KYC §6 applies unchanged;
  * **PARTIAL for the same reason as KYC ORIGIN-ONLY:** copies already cached at
    the edge stay reachable until an edge rule and a purge run, and those need
    the Cloudflare access this session does not have.
* **If an image was already overwritten or deleted by the baseline's
  `Storage::delete()` calls, it is gone. It cannot be reconstructed.** Nothing
  here claims otherwise.

---

## 7a. S3-06 — the public catalog, and the one defect in it

**How this came up.** Directive item 7 says folder names do not establish
publication consent. Classifying `storage/app/public/items` meant finding what
actually publishes item images, and that search found a route group I had not
audited: `routes/web.php` 94-100 serves `/s/{slug}` — a whole shopfront, product
list and category pages — with **no authentication at all**.

**The gate, verified rather than assumed.** `ResolveCatalogShop` requires all
three of: a shop whose `catalog_slug` matches, that shop being `active()`, and
`catalog_website_settings.is_enabled`. That column is `default(false)`, so a shop
publishes nothing until someone switches it on. C-01 proves an enabled shopfront
serves a logged-out visitor; C-02 proves a shop that never opted in gets a 404
and leaks no sentinel. "It is opt-in" is worth exactly what the test proving it
is worth.

**The defect (S3-06).** The middleware set tenant context from the anonymous
visitor's URL segment and cleared it only after `$next($request)` returned. Any
throw in between skipped the clear. `EnsureTenantUser:31-32` has used
`try/finally` all along; the asymmetry was the finding. Fixed in `721c06d`.

*Bounded honestly:* no cross-tenant read is demonstrated, and `composer.json` has
no Octane, so no worker carries stale context into a different visitor's request.
What it was, was a missing guard on the one route group whose tenant comes from
an unauthenticated URL rather than a session.

**Checked for siblings rather than fixing only the instance I tripped over.**
`TenantContext::set()` has exactly two call sites in `app/` —
`ResolveCatalogShop:35` and `EnsureTenantUser:28` — and both now release through
`finally`. The third entry point, `TenantContext::runFor()`, was already correct
and is in fact stricter than either: it restores the **previous** shop id rather
than clearing to null, so it nests safely. Neither middleware needs that, being
top-of-request, but the asymmetry is worth knowing if either is ever called from
inside an existing context.

**Two things this does NOT fix, recorded as findings rather than repaired:**

* **S3-06b — consent is per SHOP, publication is per ITEM.**
  `PublicCatalogWebsiteController::products()` lists `Item::where('status',
  'in_stock')` with no per-item opt-out, so enabling the shopfront publishes
  every in-stock piece — barcode, design, category, price, photo — not a chosen
  subset. C-03 characterizes this. If someone later adds a per-item flag, C-03
  **should** fail; that failure is the feature landing. This is a
  product-consent question, not a tenant-isolation break, and adding the flag is
  a feature decision rather than an audit repair.
* **S3-06c — the image files outlive the gate.** Turning the shopfront off makes
  the pages 404. It moves no bytes: photos stay readable at
  `/storage/items/<ULID>.webp` to anyone holding the URL. Filenames are
  `Str::ulid()->toBase32()` (`ImageOptimizer:72,80`), whose low 80 bits are
  random, so they are not enumerable by guessing — but a URL that was shared,
  screenshotted or logged keeps working. A limitation, not a repair.

**Cross-shop isolation on this route group gets its own test (C-04)** because
every other isolation test in the suite has a logged-in principal whose
`shop_id` the scope keys off. Here there is none — the tenant is chosen by an
anonymous URL segment, which makes it the one place a missing scope would expose
another shop's inventory to the open internet.

---

## 7b. S3-07 — the cache investigation, and what mutation changed about it

Directive item 5 asks whether the HTML/JSON carrying signature bytes can leak
through a shared cache. Auditing every `Cache::` call in `app/` answered that
**no**: the only response-caching call sites are idempotency caches holding
payment totals, not rendered documents. Keys are otherwise shop-scoped
(`shop:{id}:…`, `reorder_alerts_{id}`, `pos_sell_idempotency:{shop}:{user}:{key}`).

One exception, and it is not a signature path:
`Api\Mobile\InvoiceController::storePayment` keys its cache
`invoice_payment_idempotency:{$invoice->id}:{$key}` — no shop, no user — and
reads it at :171, **before** `$shopId` is read at :176.

**The mutation changed the finding, not just confirmed it.** My first
conclusion was "route-model binding blocks it, so the key shape is harmless."
Making the binding unscoped killed only I-05. I-02 survived — because a
*second* guard holds the write path: the `lockForUpdate()->firstOrFail()` at
:181 is still tenant-scoped. But the cache-hit path returns before that lock is
reached, and shop B received **HTTP 200 carrying shop A's payment totals**.

So the accurate statement is: **two independent guards protect the write path,
one protects the cache-hit path.** That is S3-07. Had I graded the mutation by
kill-count alone I would have "strengthened" I-02 and destroyed the evidence —
the directive's "a surviving mutation may mean another legitimate guard still
protects access", met in the wild.

**How this evidence is classified — corrected.** An earlier revision of this
section was loose about it. The **unchanged** route binding blocks the
cross-shop request I-05 sends. No exploit has been demonstrated against
unchanged code. What the mutation demonstrates is a **dependency on that single
guard**. The repair below is therefore defence in depth, not an incident fix.

**The key is still NOT changed — but the cache-hit path is now authorized**
(`0296431`). Adding `{shop}:{user}` to the key makes every in-flight key miss
across the deploy window, and a retried *partial* payment would then be
recorded twice — the overpayment guard at :194 only catches duplicates that
push past `outstanding`, so 3,000 paid twice on a 10,000 invoice lands twice,
silently. Key format, 24h TTL and replay semantics are all preserved and no
idempotency record is flushed. The missing check went where the gap actually
was — between `Cache::get` and the return:

```php
$tenantShopId = TenantContext::get();
abort_if($tenantShopId === null, 403, 'No active shop context.');   // fail closed
abort_if((int) $invoice->shop_id !== (int) $tenantShopId, 404);     // as the scoped binding answers
abort_unless($user?->can('sales.create') && $user->can('view', $invoice), 403);
```

404 rather than 403 on the tenant mismatch, so the guard is not an existence
oracle. `InvoicePolicy::view` is reused rather than reimplemented, and
`sales.create` is re-checked in the controller so the cache-hit path does not
depend on route middleware configuration staying correct.

Evidence: I-07, I-08 and I-09 were watched failing first, each returning **HTTP
200 carrying shop A's `7777`**. I-06 (authorized replay, no second payment row)
was green before and after — it characterizes the behaviour the repair had to
leave alone. A further mutation deleting *only* the fail-closed line killed I-09
and nothing else, so that branch is separately load-bearing. Restoration
verified by `md5sum -c` plus an empty `diff`, not by searching for the word
MUTATION.

### S3-07b — retry integrity, a separate question from access control

Every caller below is fully authorized. Both were leads in the last report and
are now **demonstrated** (`893a49b`, characterization, stated as such):

* **R-01 — the commit and the cache write are not coordinated.** `DB::transaction`
  is durable; `Cache::put` is a later best-effort write to a different store.
  With the entry absent for an already-processed key, the retry is reprocessed:
  **one key, two payments, 6,000 recorded against a 3,000 collection.** The
  overpayment guard cannot fire — a partial payment leaves headroom by
  definition. Limitation stated: PHPUnit cannot schedule two real workers, so
  the test models the *consequence* (a miss on a processed key); the concurrent
  double-miss is one documented way to reach it, not something observed here.
* **R-02 — needs no race at all.** The same key sent with a **different amount**
  is never compared against the original payload: HTTP 200, the first receipt
  replayed, the new payment silently dropped, the operator shown success.
  `EnsureIdempotency` — already in this repo — answers **409** for exactly this
  case and says so in its own docblock. This is a divergence from an in-repo
  standard, not a design preference.
* **R-03** is the control: two distinct keys must still record two distinct
  payments, so a future fix cannot pass by refusing part-payments.

#### REPAIRED — `2dd0875788e21d31270c746f45bd8cf34382a750`

**The repair proposal that stood here was wrong and is withdrawn.** It read:
*"move this route onto the existing `idempotency_keys` mechanism."* Reading that
mechanism rather than assuming it disqualified it twice over:

| Disqualifying reason | Evidence |
|---|---|
| **Transaction boundaries.** `EnsureIdempotency` records its key *after* `$next($request)` returns, in a statement outside the controller's transaction — the same uncoordinated shape R-01 is about. | `app/Http/Middleware/EnsureIdempotency.php:140` (`$response = $next($request);`), `:149` (`IdempotencyKey::create`), `:166-177` fails soft, conceding in its own comment that a retry "will simply re-run (not ideal…)". |
| **Identity is WIDER.** `UNIQUE (shop_id, user_id, key)` vs the legacy key's `(invoice_id, key)`. Adopting it would let two users in one shop retry one key against one invoice and charge **twice** — a regression introduced by the repair. Collapsing `user_id` to NULL does not recover it: PostgreSQL treats NULLs as DISTINCT in a UNIQUE index, so every row stays unique. | `database/migrations/2026_05_29_010000_create_idempotency_keys_table.php` — `unique(['shop_id','user_id','key'])`, `user_id` nullable. |

**What was built instead.** `invoice_payment_claims`, UNIQUE on
`(invoice_id, key)` — the legacy key's own identity, preserved rather than
reinterpreted — with the claim written **inside** the payment transaction. The
evidence commits with the money or not at all. Placed at the *end* of the
transaction because the existing `lockForUpdate` on the invoice already
serializes concurrent same-invoice requests, so a loser collides immediately and
its whole transaction — payment row included — rolls back before replaying the
winner.

**What was deliberately NOT touched:** the legacy cache key format, its 24h TTL,
and every already-cached receipt. The cache is retained as a read accelerator
*ahead of* the claim table. Changing either would make every in-flight legacy key
miss across the deploy window — precisely the condition that double-charges. No
payment was created and no record was flushed by this change.

**`InvoicePaymentClaim` omits `BelongsToShop`, and that is the safety decision,
not an oversight.** Fail-closed is right for what you will *show*; on a
*deduplication* lookup it answers "no rows", which the caller cannot distinguish
from "never seen this key", and the response to that is to take the payment
again. A fail-closed scope here converts a missing tenant context into a double
charge. Safety comes instead from the tenant-scoped route binding on `$invoice`,
an explicit `shop_id` assertion before any claim body is returned, and a **503
refusal — never a fall-through** — if the lookup itself throws.

**Replay keeps status 200, not the stored 201.** Replaying the stored 201 broke
`I-06`, which encodes the contract clients in the field already depend on. The
existing test was treated as authoritative and the code changed to match it. The
signal travels on `X-Idempotent-Replay: true`, so a replay is
status-indistinguishable whether the durable claim or a legacy cache entry
answered it.

**Compatibility limit — stated, not engineered around.** A legacy cache entry
holds a response body and *no request hash*; the original payload was never
hashed. So for keys minted before this deploy a **changed payload cannot be
detected** and R-02's 409 is unavailable — those replay as before. Deriving a
hash from the replayed body would manufacture the missing evidence rather than
recover it, so it was not done. The gap is bounded by the unchanged 24h TTL and
is covered explicitly by **P-11**.

**Constitutional position.** No money column, no balance, no accounting path
reads this table. Article I does not reach it; no protected trigger is added,
altered or disabled. Additive, with no FK pointing *into* it, so it migrates
ahead of the application change with no behavioural effect.

##### Measured — RED before, GREEN after

| Run | Command | Result |
|---|---|---|
| **RED** (repair absent) | `php artisan test tests/Feature/Security/InvoicePaymentRetryRepairTest.php` | **8 failed, 3 passed** (27 assertions) |
| **GREEN** (repair applied) | same | **11 passed** (50 assertions) |
| Characterization → regression | `…/InvoicePaymentRetryIntegrityTest.php` | **3 passed** (16 assertions) |
| Regression band | `php artisan test tests/Feature/Security tests/Feature/Mobile` | **246 passed, 879 assertions, 0 failed** |

P-04, P-07 and P-10 passed on the RED run and are recorded as **controls** for
guards that already existed — they are not credited to this repair.

R-01 and R-02 failed on the repaired code exactly as their own docblock had
predicted they would (`Failed asserting that 1 is identical to 2.` and
`Failed asserting that 409 is identical to 200.`), and were then **inverted into
regression tests** with their original assertions quoted inline and their finding
IDs preserved. R-03 was untouched and held throughout, which is what establishes
that part-payment collection still works rather than having been refused into
compliance.

##### Scenario coverage, with mechanisms kept distinct

| # | Scenario | Mechanism | Result |
|---|---|---|---|
| P-01 | Authorized same-key replay after the cache record is gone | `[SIMULATED CACHE LOSS]` | one payment; `payments`, `cash_transactions`, `audit_logs` all asserted |
| P-02 | Replay returns the original receipt | — | 200 + `X-Idempotent-Replay` |
| P-03 | Same key, changed amount | — | **409**, nothing recorded |
| P-04 | Two valid payments, distinct keys | control | both recorded |
| P-05 | Failure **before** commit | `[INJECTED FAILURE]` | key not burned; retry succeeds |
| P-06 | Failure **after** payment commit, before the response/cache write | `[INJECTED FAILURE]` | claim survives; retry replays, no second charge |
| P-07 | Foreign-shop caller | control | refused; **no receipt data in the body** |
| P-08 | Duplicate claim insert | `[OBSERVED CONSTRAINT]` | `UniqueConstraintViolationException` |
| P-09 | Unauthorized / inactive staff, same key | — | refused |
| P-10 | Missing tenant context | control | refused (403/404/422/503 — *not* 500) |
| P-11 | Legacy cache entry with no hash | — | replays; 409 **impossible**, documented above |
| — | **Two concurrent first requests, separate processes** | **NOT RUN** | `RefreshDatabase` wraps each test body in one uncommitted transaction, so a second connection cannot see the fixtures. P-08 exercises the constraint the race would hit; it does not schedule the race. |

**P-08 recorded a false pass in my own test and it is worth keeping.** The first
draft expected `QueryException` and **passed on the RED run** — because the table
did not yet exist and *"relation does not exist"* is also a `QueryException`. The
assertion was being satisfied by the absence of the very thing it verifies. It
now expects `UniqueConstraintViolationException` specifically.

### S3-08 — the cache sweep, corrected for coverage

My earlier claim that the application-cache investigation was complete rested on
a `Cache::` grep. **That establishes the coverage of that search and nothing
more.** Re-run by category on 2026-09-21:

| Category | Occurrences in `app/` | Finding |
|---|---|---|
| A. `Cache::` facade | 45 | As described above; keys otherwise shop-scoped |
| B. `cache()` helper | 0 | Absent — named explicitly, not assumed |
| C. Injected `Cache\Repository` / `CacheManager` | 0 | Absent |
| D. Direct `Redis::` / `RedisManager` | 0 | Absent |
| E. Static memoization | 3 holders | Examined below |

Category E is the one the facade grep could not see, and it is the only
long-lived cross-request state in `app/`:

* `MetalRegistry::$shopEnabledCache` — **keyed by `$shopId`**, and every accessor
  takes an explicit `int $shopId`. Shop A's entry cannot be returned for shop B.
  Its docblock claims "Reset between requests", which is **inaccurate in a
  long-lived `queue:work` process** where statics persist across jobs; the
  keying makes that a staleness nit rather than an isolation break. Recorded,
  not repaired.
* `HistoricalLifecycle::$unlocked` — a privilege gate, but it saves `$previous`
  and restores it in a `finally`, so it nests correctly and cannot leak an
  unlocked state into the next job.
* `AppServiceProvider` `static $tableBooleanColumns` — schema shape, no tenant
  data.

A first pass of this grep reported category E as **0**, because the pattern
`static \$\w+` does not match `private static ?int $shopId` — the type sits
between. The zero was a broken search, not a clean result, and is recorded
because a wrong zero is exactly the failure mode the directive warns about.

**Middleware nesting, now verified rather than assumed.** `TenantContext::runFor`
restores `$previous`; both middlewares `clear()` to null instead, which is
correct only while they are outermost. `catalog.shop` is registered at exactly
one place (`bootstrap/app.php:107`) and applied to exactly one route group
(`routes/web.php:94`), which carries **no `tenant` middleware** — so
`ResolveCatalogShop` and `EnsureTenantUser` never nest and clear-to-null is
equivalent to restore there today. A route group combining them would break that
equivalence, which is the condition to re-check if one is ever added.

Incidental confirmation from I-06: under PHPUnit the *second* request of a test
404s at the binding unless the context is re-armed, because `EnsureTenantUser`
clears it in its `finally` at the end of request one. The clearing behaviour is
observed, not inferred.

**CDN and browser caching remain separate scope** and are handled in the KYC
containment runbook, not here.

**Console-specific test adjustment, declared.** `actAs()` sets `TenantContext`
as well as authenticating. `BelongsToShop::resolveTenantShopId()` returns null
under `runningInConsole()` and the scope falls to `whereRaw('1 = 0')`, and route
binding runs before the `tenant` middleware — so without it all five tests 404,
positive control included. The first run did exactly that: I-02 and I-04 "passed"
against a route nobody could reach. The context always follows the acting
principal; pointing it at the target shop would invert I-02 into a demonstration
that shop B *can* reach shop A's invoice.

---

## 7c. S3-09 — `EnsureIdempotency`, tracked separately from S3-07b

**The invoice-payment repair does not cover these routes.** S3-07b was fixed
inside `InvoiceController::storePayment` — a durable claim staked inside the
payment transaction. The `POST /invoices/{invoice}/payments` route does **not**
use the `EnsureIdempotency` middleware, and none of the 16 routes below go
through `storePayment`. They are disjoint. Nothing about the S3-07b fix reaches
them.

### The defect, from the source

`app/Http/Middleware/EnsureIdempotency.php`, as it stood:

* line 140 — `$response = $next($request);` the controller runs, commits its own
  transaction, and returns.
* line 149 — `IdempotencyKey::create([...])` the completion record is written
  **afterwards**, in a separate statement, **outside any transaction**.

That is the same uncoordinated-commit shape S3-07b had, in shared middleware.
Anything that kills the process between those two lines leaves the business
effect durable and no record that the key was used, so the retry re-runs it.

Two further soft-failure paths widened it:

* the unique-collision `catch (QueryException)` logged
  `EnsureIdempotency: concurrent insert collided on unique key` and returned the
  response — it did not replay the winner's;
* the outer `catch (Throwable)` failed soft, with a comment that already conceded
  *"a future retry with the same key will simply re-run (not ideal…)"*.

The uniqueness is on `(shop_id, user_id, key)`. A **read** failure against the
table returns 503, so the read side was fail-closed; it was the **write** side
that was not.

A second consequence, derived after the routes were inspected: because nothing
was staked before `$next()`, **two concurrent same-key requests both passed the
lookup and both reached the controller**. The unique index only decided which of
the two got to record a response — both had already moved money.

### The repair — `b8673db`

The claim is staked **before** the controller, with `response_status = 0` as an
in-flight sentinel, so the unique index admits exactly one request. A same-key
request meeting an in-flight claim gets `409 idempotency_in_flight`.

A sentinel was chosen over making the column nullable specifically to avoid
adding a migration against a populated production table to a risk register that
already tracks one.

**The 4xx/5xx split is a deliberate trade and is the one judgement call here.**
A 4xx releases the claim and stays retryable — it is a controller refusal with
nothing written, which preserves existing behaviour. A 5xx does **not** release
it. Laravel's pipeline converts a controller exception into a response, so the
middleware cannot distinguish "died before writing" from "wrote, then died", and
releasing the key makes the second case double-charge. The cost is that a
transient 5xx which wrote nothing burns that one key and the client must surface
*"we could not confirm this — check before re-entering it"*.

**Correction to my own earlier wording here.** The first version of this
paragraph said the burn was *"bounded, not permanent"* because
`PruneIdempotencyKeys` would reap the row at 48h. That was wrong twice over and
is withdrawn:

* It treated the pruner as a **financial-safety mechanism**. It is not. It
  bounds table size. Nothing in it decides that an uncertain operation has
  become safe to re-run, and nothing in it could — only an operator who has
  reconciled the underlying record can know that.
* It conflated the **eligibility threshold** with the **deletion time**.
  `--hours=48` is the threshold; `Schedule::command(...)->daily()`
  (`routes/console.php:147`) runs at midnight Asia/Kolkata, so a row eligible
  at 00:30 waits until the next midnight — real deletion age runs from 48h to
  roughly 72h. And all of that assumes cron is actually invoking
  `schedule:run`, which is **NOT VERIFIED** and is not verifiable from the
  test suite.

As of `e68eb31` the premise is gone anyway: the pruner no longer deletes
unresolved claims at all. See §7c-1.

Fail-soft now survives in exactly one place — recording a completed claim —
where the mutation has already succeeded and the row already holds the key, so a
failure degrades a retry to a refusal and never to a re-run. Staking failures
fail **closed** (503), because nothing has run yet and nothing is lost by
refusing.

### Affected state-changing routes — 16, enumerated from `route:list`, not grep

All 16 share the repaired middleware, so the crash window and the concurrency
window are closed for every one of them. What is **not** uniform, and what the
last two columns track, is each route's own service-layer duplicate behaviour.

That is the part that still matters, and an earlier version of this paragraph
gave the wrong reason for it — it said service-layer dedup decides "what happens
once the middleware's 48h retention lapses", which leaned on the pruner framing
withdrawn in §7c. The accurate reasons are two, and neither is a timer:

1. **The middleware only ever protected one key.** A duplicate submitted under a
   *different* key is, to the middleware, a different request — it stakes a
   fresh claim and runs. S3-09c was exactly that: a client minting a new key per
   resubmit, so the server's protection never engaged. Only a service-layer
   guard sees through to "this is the same business intent".
2. **Resolved claims are pruned; unresolved ones are not.** A claim that
   recorded a real 2xx becomes eligible for deletion at `--hours=48` and is
   removed on the next scheduled run, after which a same-key retry is once again
   a first-time request. That is correct and intended — the outcome is known and
   the operator can see it. It is only unresolved claims that are retained
   indefinitely (§7c-1), and their retention is precisely why no timer here may
   decide an unknown outcome has become safe to repeat.

`Read` = controller/service actually read for internal dedup.
`Tested` = a test asserts the money/metal/stock consequence, not just a status.

| Route | What a duplicate does | Read | Tested | Own service-layer guard |
|---|---|---|---|---|
| `POST /cashbook` | **duplicate cash movement** — money in/out recorded twice | yes | yes | **NONE** against duplicates; the middleware is the only guard. Atomicity was ALSO missing and is now fixed (S3-10, §7c-5) — the cash/audit write pair is wrapped. The two are independent: the transaction stops a half-written entry, it does nothing about a second entry. |
| `POST /installments/{plan}/pay` | **duplicate installment payment** — money recorded twice | yes | yes | Partial. `InstallmentService::recordPayment` is transactional and locks the plan, but its `status === 'active'` check is not an idempotency guard — `active` is the state a non-final EMI *leaves* the plan in. Only the final EMI is guarded. |
| `POST /job-orders/{jobOrder}/receipt` | **duplicate karigar receipt** — metal received twice | yes | yes | Partial, same shape. `JobOrderService::receive` locks and guards on status, but permits `ISSUED` and `PARTIAL_RETURN`, and a partial receipt leaves `PARTIAL_RETURN`. Only the final receipt is guarded. Worst blast radius of the four: a replay mints a fresh `items` row marked in-stock and a fresh `manufacture` metal movement. |
| `POST /returns` | **duplicate return order** — stock returned twice | yes | yes | **Full, and independent of the middleware.** `ReturnService` carries two durable guards: the invoice's own status, and the per-line `invoice_items.returned_at` stamp. This route was never part of the finding. |
| `POST /returns/{returnOrder}/approve` | second approval on an approved return; credit-note risk | partial | no | `ReturnController::approve` guards `status === STATUS_PENDING_APPROVAL`, which *is* terminal — approval moves the order off that status. Read only; **NOT RUN.** |
| `POST /uploads/intent` | extra pending upload record; benign, storage only | no | no | NOT RUN |
| `POST /cashbook/drawer-check` | duplicate drawer reconciliation entry; corrupts the count trail | yes | yes | **NONE** against duplicates. Row was stale — it read "no / no", but S3-10 read this route and `CashbookWriteAtomicityTest` tests it. Atomicity is fixed (§7c-5); duplicate protection is still middleware-only. No unique index on `(shop_id, business_date)`, and the append-only trigger makes a duplicate row **unremovable**. |
| `POST /sessions/lock` | second lock on an already-locked session | no | no | NOT RUN |
| `POST /sessions/unlock` | second unlock; re-opens a session an operator closed | no | no | NOT RUN |
| `DELETE /sessions` | second bulk revoke; idempotent in effect, audit noise | no | no | NOT RUN |
| `DELETE /sessions/{session}` | as above, single session | no | no | NOT RUN |
| `PATCH /items/{item}` | re-applies an update; last-write-wins, may clobber an edit made between the two attempts | no | no | NOT RUN |
| `PATCH /customers/{customer}` | as above, customer record | no | no | NOT RUN |
| `POST /job-orders` | duplicate job order issued to a karigar | no | no | NOT RUN |
| `POST /installments/finalize` | duplicate plan finalization | no | no | NOT RUN |
| `POST /installments/discard-draft` | second discard; benign | no | no | NOT RUN |

Four of these move money or metal: `cashbook`, `returns`,
`job-orders/{jobOrder}/receipt`, `installments/{plan}/pay`. Those four were the
ones inspected, and they did **not** turn out to be uniform — which is why no
blanket transaction wrapper was applied.

**Status: repaired at the middleware (`b8673db`), with regression evidence on
four routes. Twelve routes are NOT RUN at the service layer.** "NOT RUN" here
means exactly that — not "confirmed absent", and not "confirmed safe".

### Evidence — `tests/Feature/Security/MobileIdempotencyRetryIntegrityTest.php`

17 passed, 89 assertions, together with the 9 pre-existing
`IdempotencyMiddlewareTest` contract tests (all still green — the repair changes
no documented behaviour). No regressions in `tests/Feature/Mobile` (98 passed)
or `tests/Feature/Security` (160 passed).

The crash window is exercised by injecting a real failure, not by mocking:
`AuditLog::creating` throws for the cashbook case, `IdempotencyKey::updating`
throws for the rest — the latter lands exactly in the window the repair leaves
behind (staked, committed, outcome never recorded). Each test asserts the
injection actually fired, so a green run cannot be the accidental result of the
first request never having written anything.

Two findings about the tests themselves are recorded in the file and are worth
carrying forward:

* **A false pass was caught and fixed.** The job-order test initially passed for
  the wrong reason: the retry 404'd on route-model binding because the second
  request had no `TenantContext`, so it never reached the controller and of
  course nothing duplicated. This is a test-environment characteristic, **not a
  production bug** — `BelongsToShop::resolveTenantShopId()` checks
  `runningInConsole()` before the `Auth::user()->shop_id` fallback, which is true
  under PHPUnit and false under FPM. The tests now assert the exact refusal
  (`409 idempotency_in_flight`) rather than merely "not a duplicate".
* **The returns control retries with a *different* key**, deliberately. Post-
  repair a same-key retry is answered by the middleware and never reaches
  `ReturnService`, so a same-key control would prove nothing about the service.
  The control also records which of the two `ReturnService` guards it actually
  reaches — the invoice-status one — and marks the per-line `returned_at` guard
  as **NOT RUN**, because the single-line fixture cannot reach it.

The concurrency window is proven by driving the code path rather than the
timing: a listener raw-inserts a conflicting claim from inside `creating`, which
is after the lookup found nothing and before the middleware's own insert lands —
precisely where a losing sibling sits. Its central assertion is a spy proving
the controller never executed, paired with a positive control proving the spy
can fire. Row counts are unusable there: `RefreshDatabase` runs the test in one
transaction and the unique violation aborts it. That is a harness artefact, not
a production concern — the middleware runs outside any transaction, so
PostgreSQL rolls back the failed statement alone.

### §7c-1 — the pruning contract, and the defect in it — `e68eb31`

The repair in `b8673db` only holds while the claim row survives. It did not.
`PruneIdempotencyKeys` deleted by age alone:

```php
IdempotencyKey::where('created_at', '<', $cutoff)->delete();
```

**Demonstrated, not argued**, in
`tests/Feature/Security/MobileIdempotencyRetentionTest.php`. The sequence is
driven through the real `POST /api/mobile/v1/cashbook` route, not assembled by
hand, so what the pruner deletes is genuinely the row protecting a committed
cash movement:

1. A cashbook entry is posted and the cash row **commits**.
2. `IdempotencyKey::updating` is made to throw, so the outcome is never
   recorded — the claim is left at `response_status = 0`. This is the exact
   window the repair leaves behind, reached by real failure injection.
3. The claim is aged past the threshold and the command is run.
4. The same key is retried.

Before the fix, step 3 deleted the claim and step 4 **booked the cash entry a
second time**. The test asserts the cash row count, not merely the response.

The correction is **retain + report**, and deliberately not a timer:
unresolved claims are excluded from the delete and counted into a warning.
Two positive controls pin it from the other side so it cannot quietly degrade
into "never delete anything" — a resolved claim past the threshold is still
pruned, and a recent resolved claim is left alone.

Also fixed here: the replay path now range-checks the stored status
(`< 100 || > 599`) instead of comparing to the sentinel. A corrupted value must
not be reported to a client as fact, and `response()->json($body, 0)` would
throw a 500 from inside the one component whose job is to answer safely.

**What this does NOT settle.** There is still no supported way to clear a
retained unresolved claim, and growth — one row per crashed mutation — is slow
but unbounded. No purge flag is offered, because deleting such a row is exactly
the dangerous act and needs a reconciliation procedure rather than a flag.
**This is an open decision for the operator, not a closed item.**

**Since then (`8d5a066`): a procedure and a tool, both PROPOSED.**
`docs/runbooks/idempotency-unresolved-claims.md` sets out the evidence required
per claim (`request_hash` verifies a candidate payload; which ledger table to
search for each route), two-person approval, and the audit query.
`mobile:idempotency-claims` lists unresolved claims read-only, and reconciles
ONE claim with an outcome, evidence and an approver. It writes nothing without
`--confirm`, and writes the change and an `audit_logs` row in one transaction.
It has no age option and no bulk option. `not-committed` releases the key;
`committed` keeps it refused with a definite
`409 idempotency_outcome_reconciled`. A wrong `committed` fails closed; a wrong
`not-committed` can duplicate, so the procedure demands the stronger evidence
for it. Tested through the real cashbook route
(`IdempotencyClaimReconciliationTest`, 6).

### §7c-2 — is releasing a key after a 4xx safe? — S3-11

The release-on-4xx rule was inherited, not verified. It rests on the claim that
*a 4xx means the controller refused and wrote nothing*. Keeping the old
behaviour does not establish that, so it was checked rather than retained on
faith. The 4xx responses on these routes split into three classes:

| Class | Where the 4xx comes from | Is a claim released? | Safe? |
|---|---|---|---|
| **A** | `EnsureIdempotency` itself — missing/invalid key (422), no user (401), payload conflict or in-flight (409) | No claim exists yet; the refusal happens *before* staking | Safe by construction — release is unreachable |
| **B** | After staking, before any business write: route-level `can:` gates, route-model-binding 404s, `abort_if` shop-scope checks, `$request->validate()` 422s | Yes | Safe — nothing was written, and the release is exactly what lets a corrected resubmit run |
| **C** | Controller ran, **persisted**, then returned 4xx | Yes | **Not safe — found once** |

Class B is larger than it looks, and worth stating because it is easy to get
backwards: the `can:` gates are **route-level**, and route middleware runs
*after* the group's `mobile.idempotency` (`routes/mobile_v1.php:169-229`). A
403 therefore does reach the release path.

**Class C was looked for, not assumed, and it exists — once.** Three
controllers convert a service `LogicException` into a 422:
`ReturnController::store`, `ReturnController::approve`,
`JobOrderController::receipt`. That shape is only dangerous if the service is
non-atomic, so each was checked:

* `JobOrderService::receive` — **safe.** `return DB::transaction(...)` wraps
  the entire body (`JobOrderService.php:574`), so every throw rolls back.
* `ReturnService::createPendingApproval` — **safe, and this corrects a standing
  investigation lead.** It has the suspicious shape (no transaction,
  `forceFill()->save()`) that earlier notes flagged. But the shape is not the
  defect: its guard and both assertions throw *before* the single `save()`, and
  nothing follows that save. One statement is atomic on its own. **Shape
  present, consequence absent** — the lead is closed, not by assertion but
  because the failure it predicted cannot occur here.
* `ReturnService::approveReturn` — **DEFECTIVE. This is S3-11.**

**S3-11, demonstrated.** `approveReturn` committed the pending header's
cancellation with a bare `DB::table(...)->update(...)`
(`ReturnService.php:582`) and *then* called `createPartialReturn`, which throws
at six sites before its own transaction opens. Nothing wrapped the pair.

The trigger needs no injected fault. A `pending_approval` return stores the
settlement mode chosen at **creation** and replays it at **approval**, so an
owner tightening `return_settlement_mode` in between is sufficient — a
legitimate settings change.

**The consequence is not a duplicate, and reporting it as one would be wrong.**
Releasing the key here cannot double anything: the retry re-enters
`approveReturn`, finds the header `cancelled` rather than `pending_approval`,
and is refused by the service's own guard. The damage is the opposite failure —
the customer's pending return is **destroyed**. Not settled, no credit note, no
restock, and not approvable by any route. The test captures the server saying
so: *"Return is in 'cancelled' state. Only pending_approval returns can be
approved."*

**So the fix is the service's atomicity, not the middleware's 4xx policy.** The
middleware is deliberately unchanged. Broadening it to retain keys on 4xx would
break class B — a corrected resubmit after a validation failure — in order to
work around one non-atomic service. `approveReturn` is now wrapped in
`DB::transaction`; the nested transactions in `createPartialReturn` /
`createFullReturn` become savepoints, so every accounting write and every
constitutional trigger runs exactly as before.

Evidence: `tests/Feature/Security/ReturnApprovalAtomicityTest.php`, **4 passed
(23 assertions)**, red before the fix and green after. It carries a positive
control (an unobstructed approval still settles and stamps the line) and a
separate assertion that the 422 **does** release the claim — recorded directly
rather than inferred from the retry succeeding, so the class-B classification
and the S3-11 finding cannot merge into one fact. Regression: `--filter=Return`
146 passed / 1 skipped (672 assertions); `Feature/Security` + `Feature/Mobile`
**269 passed (1016 assertions)**.

### §7c-3 — real multi-process concurrency — S3-09 — MEASURED

**This is the first REAL concurrency evidence in this audit.** Everything prior
under S3-09 was simulated: a `creating` hook or a pre-seeded row standing in for
a competing transaction. This section is separate PROCESSES, separate database
connections, separate transactions, racing on a wall-clock start.

**Correcting the harness premise.** The directive said to reuse "the existing
local multi-process harness". There wasn't one. `pcntl_fork|proc_open|curl_multi`
matched nothing in the repo, and handoff lines 781/1577 already recorded real
concurrency as NOT RUN — those two facts agree. What existed was the P-01..P-11
*scenario structure*, which is reused; the process spawning is new.

**Why PHPUnit could not have produced this.** `RefreshDatabase` wraps each test
in a single uncommitted transaction. A second connection cannot see the
fixtures, so a genuine competing process has nothing to race against. That
limitation is structural, not an oversight — it is exactly why the earlier
coverage was simulated.

**Harness.** `tests/Concurrency/idempotency_race.php`. Each child boots the HTTP
kernel and dispatches a real `Request` through the real middleware stack to
`POST /api/mobile/v1/cashbook`. No web server: real OS processes, real
connections, real constitutional triggers. The parent `proc_open`s every child
first, hands each the same future wall-clock start (`microtime(true) + 2.0`),
and only then collects — so the children overlap rather than queue.

#### Before / after, same harness, same machine

Baseline is the repair's parent. `git rev-parse 8cddbc3:app/.../EnsureIdempotency.php`
and `2cc4b4c:...` are the **same blob `6a8c4cc`**, so the pre-repair file used
here is byte-identical to what `b8673db` replaced.

| Scenario | Pre-repair `6a8c4cc` | Repaired `fb351f4` |
|---|---|---|
| **A** — 4 concurrent, same key, same payload | `{"201":4}` — **4 cash rows, sum 10000** | `{"201":1,"409 idempotency_in_flight":3}` — **1 cash row, sum 2500** |
| **B** — 2 concurrent, same key, different payload | `{"201":2}` — **2 cash rows, sum 12499** | `{"201":1,"409 idempotency_in_flight":1}` — **1 cash row** |
| **C** — positive control, 4 distinct keys | *(not run)* | `{"201":4}` — 4 cash rows, sum 10000 |
| **D** — DB-error control, missing database | *(not run)* | `{"500":1}` — 0 cash rows |

**A is the finding, measured rather than argued.** Four concurrent requests
carrying one idempotency key produced four cash movements against an immutable
ledger. The unique index admits exactly one claim row (`claim rows: 1`), and
after the repair that single claim is staked *before* the controller, so the
three losers never reach it.

**C is load-bearing.** Without it, the repair could satisfy A and B by refusing
concurrency generally. C shows four genuinely distinct operations still run
concurrently to completion.

**D is honest about its own result.** A database-wide outage surfaces as **500**,
*not* the middleware's 503 `idempotency_unavailable`. `auth:sanctum` touches the
database before `mobile.idempotency` runs, so for a total outage the middleware
never executes. The 503 path is therefore reachable only for failures that spare
authentication and hit the claim write — narrower than the code alone suggests.

#### Two new behavioural facts

**1. Payload-conflict detection is a SEQUENTIAL-path guarantee only.** In B the
different-payload loser received `idempotency_in_flight`, **not**
`idempotency_key_conflict`. It collides on the unique index before any
payload-hash comparison happens. Still a refusal, still no second write — but
the 409-conflict contract documented elsewhere describes the sequential path,
and should not be quoted as concurrent behaviour.

**2. Both refusal paths fire inside a single real race.** Shop 295, scenario A,
three losers, five log lines:

```
concurrent request lost the claim race    ×2
refused a retry against an in-flight claim ×3
```

**Correcting my own earlier reading of this:** I first reported it as "one lost
the unique-index insert, two read the staked claim at lookup", counting one line
per request. That is wrong. `EnsureIdempotency.php:202` calls
`inFlightResponse()` immediately after logging the lost race, and
`inFlightResponse()` (line 319) *always* logs. So an insert-loser emits **both**
lines and a lookup-finder emits **one**: 2 insert-losers + 1 lookup-finder = 5
lines, 3 losers. Verified by reading the two call sites, not by inference.

**The race is genuinely nondeterministic.** Scenario B's winner changed between
runs — sum 9999 on one, 2500 on another. The ordering is not fixed by the
harness.

#### What A2 closes

Line 44 listed **recoverable successful replay** as explicitly NOT established.
A2 replays the same key sequentially after the race settles: **200 with
`X-Idempotent-Replay: 'true'`, the original row returned, still exactly one cash
row**. That sub-claim is now measured. It does not upgrade the rest of S3-09.

#### Scope limits — what this does NOT establish

- One machine, one PostgreSQL instance. **No connection pooler** (PgBouncer in
  transaction mode could change claim visibility), **no multi-node**.
- 4 concurrent requests is contention, not production load.
- One route (`POST /cashbook`). The middleware is shared, but the other 15 routes
  are not covered by this section — see §7c for their individual status.
- Rows are retained: ledger/audit `DELETE` is refused by constitutional trigger,
  so each run provisions a fresh shop rather than cleaning up.

**Status.** S3-09's concurrency sub-item moves from NOT RUN to **MEASURED**. The
finding as a whole stays open — pruning (§7c-1) and the 12 untested routes (§7c)
are unchanged by this.

### §7c-5 — the cashbook write pair — S3-10 — FIXED

**Defect.** `CashBookController::store` wrote `CashTransaction::record` and then
`AuditLog::create` as two unwrapped statements. `storeDrawerCheck` has the
identical shape with `CashDrawerCheck::record`. Either pair could half-complete.

**Correction to my own earlier wording here.** The 16-route table previously
described this as "Measured." That overstated the evidence in a specific way
worth naming: the measurement was an **injected** `AuditLog::creating` hook, not
an observation of a real interruption, and the row did not say so.

**Is it input-driven? No — checked, not assumed.** I looked for anything the
validator accepts that could make the *second* write fail after the first
succeeded, and found nothing:

| Checked | Result |
|---|---|
| `audit_logs.description` type | `text` — no length ceiling, so the 100-char `source_type` feeding it cannot overflow |
| `action`, `model_type` | varchar(255) holding fixed literals (`cash_in`, `CashTransaction`) |
| CHECK constraints on `audit_logs` | none |
| Unique indexes | only `audit_logs_pkey`; **no** unique index on `prev_hash`/`row_hash`, so concurrent inserts cannot collide there |
| INSERT triggers | only `audit_logs_hash_trigger`, which computes a hash chain and never raises |
| FKs | `shop_id`, `user_id` — same values the cash write already accepted |

So this is **not** in the same class as S3-11, where a routine owner settings
change fired the defect. There is no user action that reaches it. The trigger is
process-level interruption between the two statements — dropped connection, PHP
fatal or `max_execution_time`, OOM kill, deploy restart.

**Why it was still worth fixing.** Because the result is *permanent*, in the two
tables the constitution protects most strongly:

* `cash_transactions` carries `prevent_ledger_mutation` plus an append-only
  guard, and `cash_drawer_checks` an append-only guard. The orphaned row cannot
  be edited or deleted — only offset by a compensating entry, which leaves two
  rows describing one operator action.
* `audit_logs` is append-only **and** hash-chained over `prev_hash`. The missing
  entry cannot be slotted back into its original position; a late insert lands
  at the chain tip, permanently out of order.

A half-written pair is therefore not a transient inconsistency that a retry or a
reconciliation pass can settle.

**Repair.** `DB::transaction` around each write pair. Bounded to the two methods
that demonstrate the defect — **not** applied across the 16 routes.
Deliberately left OUTSIDE the transaction: `assertShopWritable` and the
`$expected` ledger read, both of which run before any write, so including them
would lengthen the transaction without adding atomicity. The
`$expected`-then-insert gap in `storeDrawerCheck` is a separate read-write race
and is **NOT** addressed here.

**Evidence — `tests/Feature/Security/CashbookWriteAtomicityTest`, 4 passed, 12
assertions.** RED first on both defect tests (`Failed asserting that 1 is
identical to 0` — the orphan survived), green after.

**Evidence class: SIMULATED INTERRUPTION.** The failure is injected at the real
seam, through the real route, but it stands in for a process death rather than
reproducing one. It does **not** establish that any such interruption has
occurred in this system. What it establishes is the atomicity property: given a
failure at that seam, no money row survives it.

Two positive controls are included so the fix cannot pass by writing *less*:
an unobstructed entry and an unobstructed drawer check must each still produce
**both** their subject row and their audit row.

**A test-harness defect found and fixed in the process.** My first reporter
closure was `fn () => $fired`. PHP arrow functions capture by value at creation
time and have no by-reference form, so it captured `false` and kept reporting
`false` even though the injection had fired and the route had returned the
injected 500. The `assertTrue($didFire())` guard is what caught it — which is
precisely the reason that guard exists, and a case where the "assert the
injection actually fired" discipline paid for itself.

**Knock-on: one existing test changed expectations, honestly.**
`MobileIdempotencyRetryIntegrityTest::test_s309a_...` asserted `cash_transactions`
held **1** row immediately after the injected failure, commented "the money
write survived the failure". That assertion *was* S3-10, recorded as a
precondition. With the pair wrapped, the interrupted attempt leaves nothing, so
the counts moved 1 → 0. The idempotency behaviour under test did not change.

The post-retry assertion is now **stronger** rather than weaker: the injection is
one-shot, so a retry that reached the controller would succeed and leave 1 row.
Asserting 0 after the retry proves the controller was never re-entered. The old
version asserted 1 both before and after, which could not distinguish "the retry
was refused" from "the retry ran and was deduplicated somewhere".

**Scope note.** This fixes atomicity only. `POST /cashbook` still has **no**
service-layer duplicate guard — a second call is a valid second entry as far as
the service is concerned, and the middleware remains the only thing standing
between that route and a duplicate.


### §7c-6 — the remaining 12 routes, reviewed against the repaired middleware

The other four (`/cashbook`, `/installments/{plan}/pay`,
`/job-orders/{jobOrder}/receipt`, `/returns`) were already classified above and
were not re-read. Route set re-derived from `route:list --json` filtered on the
middleware — **16, matching the table**, so the enumeration is measured rather
than inherited.

#### The question that mattered most, and its answer

§7c-2 established that releasing a claim on 4xx is safe *for `/returns/approve`*
because that service is atomic. The open risk was that some **other** route
persists a business effect and then returns 4xx — the release would then hand a
retry the chance to duplicate that effect.

**Checked all 12. None has that shape.** On every route, each 4xx path is
reached strictly BEFORE any write: authorization, `validate()`, route-model
binding 404s, ETag preconditions, and status guards all precede the first
persist. The routes that catch a `LogicException` after calling a service
(`/returns/approve`, `/job-orders`, `/installments/discard-draft`) each call a
service whose writes are wrapped in `DB::transaction`, so the throw rolls back
before the 4xx is rendered.

**So the "release on 4xx" policy is now supported across all 16 routes, not
assumed.** Recording the method because the conclusion is only as good as it:
this is a READ of every 4xx path, not a behavioural test of each one. It is
inspection-grade evidence, and the four atomicity-relevant services additionally
have tests.

#### Duplicate behaviour, per route

| Route | Duplicate effect | Naturally idempotent | Guard that makes it so |
|---|---|---|---|
| `POST /returns/{returnOrder}/approve` | none — 409 | yes | status guard, `ReturnController:238` |
| `POST /uploads/intent` | a second `pending_uploads` row | no | none; benign, expires in 15 min |
| `POST /cashbook/drawer-check` | **second drawer check + audit row, unremovable** | **no** | **none** |
| `POST /sessions/lock` | `locked_at` overwritten | yes | same row |
| `POST /sessions/unlock` | `locked_at` nulled again | yes | same row |
| `DELETE /sessions` | extra audit row, `sessions_revoked: 0` | no (audit noise) | session set already empty |
| `DELETE /sessions/{session}` | none — 409 | yes | `logged_out_at` guard, `:202` |
| `PATCH /items/{item}` | last-write-wins on one row | yes | — see S3-12 below |
| `PATCH /customers/{customer}` | last-write-wins on one row | yes | — see S3-12 below |
| `POST /job-orders` | **second job order, second metal draw, second advance** | **no** | **none** |
| `POST /installments/finalize` | none — 422 | yes | plan-exists + draft-status under `lockForUpdate` |
| `POST /installments/discard-draft` | none — 422 | yes | status guard, `InstallmentService:370` |

**Three routes have no duplicate guard**: `drawer-check`, `job-orders`, and
`uploads/intent`. For all three the middleware is the only protection, which
means they inherit S3-09c exactly — a client that mints a **fresh key** per
resubmit is not protected by anything. `job-orders` has the worst blast radius
(the same physical gold recorded as issued to a karigar twice); `uploads/intent`
is benign.

**No new tests were written for those three.** The risk is not new and not
route-specific: it is S3-09c, already characterized in §7c-4, and a per-route
test would restate it 3 times without adding a fact. Recording that as a
deliberate choice, per "tests for concrete uncovered risks, not to increase
counts."

#### Two NEW defects found during this review

Both were found by testing, not by reading, and both are **characterized and
deliberately NOT repaired**. Their tests pass against current code and are
written as regression locks whose failure messages read "appears repaired".

**S3-09e — a replayed response drops its headers.**
`completeClaim` persists only `response_status` and `response_body`; the replay
path rebuilds with `response()->json($body, $status)`. Every header is lost.
For 14 routes that is cosmetic. For the two `PATCH` routes it is not, because
`If-Match` is **mandatory** (428 if absent) and the client's only source for the
next tag is the `ETag` response header. Measured: the live PATCH carries a tag,
the replay's is `null`, and a client following the replay gets **428**. A
control shows the same client following the *live* response proceeds fine, so
the replay is the cause rather than the route.

**Not a regression from S3-09.** Verified: pre-repair blob `6a8c4cc` stores the
same two columns and rebuilds the same way. The gap predates the repair.
Not repaired because the fix means persisting headers in `idempotency_keys` —
the table under the open S3-09 rollback constraints — to cure a defect that
self-heals on the client's next GET.
Evidence: `tests/Feature/Security/MobileIdempotencyReplayFidelityTest.php`,
5 passed.

**S3-12 — the `If-Match` validator has one-second resolution, so writes can be
silently lost.** Found by accident: a precondition asserting "a successful write
moves the ETag" failed. **My first hypothesis was that the PATCH had no-op'd,
and it was wrong** — a probe showed status 200 with `selling_price` 1000 → 2500
genuinely persisted and the tag unchanged either side.

Root cause is the schema, not the format string:

```sql
select datetime_precision from information_schema.columns
 where table_name = 'items' and column_name = 'updated_at';   -- 0
```

`entityTagFor` hashes `(id | updated_at ATOM | class)`, and the column is
`timestamp(0)`. Widening the format would read sub-second data that Postgres
never stored. Consequence, demonstrated end to end: operator A reads tag T,
operator B writes in the same second, A writes with `If-Match: T`, the
precondition **passes**, and B's value is clobbered. Both report 200.

Honest severity: not a tenancy break, not a ledger break — a silent
data-integrity defect on catalogue and customer rows, needing two operators in
the same one-second window. Controls in the test show a plainly wrong tag still
gets 412 and a missing one still gets 428, so the failure is specifically one of
resolution, not a broken guard.

**Since repaired (`69b4439`, XR-05, §0b)** — on the row version, with the
check and write made atomic; the text below is the reasoning as it stood then.
Not repaired: both candidate fixes (migrate to `timestamp(6)`, or re-base the
validator on row content) change a client contract that mobile clients already
hold. Operator decision.
Evidence: `tests/Feature/Security/EntityTagResolutionTest.php`, 5 passed.

**Regression after both additions:** `Feature/Security` + `Feature/Mobile`
**283 passed (1052 assertions)**, up from 273/1028.

### §7c-4 — the mobile consumer — mobile `2cad553`

Inspected on the recorded mobile SHA `ffcd034`, and one piece of earlier
documentation was **checked rather than trusted**.

**Correction: the claim that "4xx responses rotate retry keys" is not what the
code does.** `src/api/transport/request.ts` never retries a 4xx at all —
`isRetryable()` returns true only for network faults, 408, 429 and 5xx — so
there was no rotation-on-4xx behaviour to rely on. The key was stable across
the transport's *own* retries (`buildHeaders` sets the same
`X-Idempotency-Key` on every attempt, verified at `request.ts:112-114`). The
rotation happened somewhere else entirely, and that was the defect.

**The verified chain to a duplicate cash entry:**

| Step | Where | Behaviour |
|---|---|---|
| 1 | `transport/request.ts` | 5xx → auto-retry, **same** key |
| 2 | `EnsureIdempotency` | claim unresolved → `409 idempotency_in_flight` |
| 3 | `utils/mutation-error.ts` | no case for that code → `default:` → `status === 409` → `conflict` |
| 4 | `utils/mutation-error-alert.ts` | flat alert titled **"Already changed"** |
| 5 | `app/cashbook/add.tsx` | mutation failed → Save re-enables |
| 6 | `api/cashbook.ts` | operator taps Save → `newIdempotencyKey()` called **inside** `createCashbookEntry` → **new key** |
| 7 | server | different key = different operation → **second cash row** |

Step 4 is not merely cosmetic: it tells the operator someone else changed the
record, which actively invites the retap at step 5.

**Correction to a sub-agent's conclusion, recorded because I relied on it
briefly.** An earlier exploration reported *"the client is SAFE by accident: it
doesn't retry 409 at all."* That is **wrong**. Automatic retry is not the only
path to a resubmit — the operator is, and the button re-enables for them.

**The repair, and what it is keyed on.** The root cause is key *ownership*:
the key's lifetime was one function call when it needed to be one operator
intent. Fixed by moving the key to a caller parameter, reusing the shape
`approveReturn(returnOrderId, idempotencyKey)` already established in
`src/api/returns.ts:115-123`, with both screens holding it in a `useRef` and
rotating **only on success**. Rotation-on-success matters more on
`drawer-check.tsx`, which stays mounted and clears its form — without it a
second genuine count would replay the first.

A `pending` kind now carries `idempotency_in_flight`, titled *"Outcome
unknown"*, with a body directing the operator to check the record. The generic
*"Please try again"* fallback is suppressed on that path specifically, because
trying again is the act that doubles the money. Payload conflict
(`idempotency_key_conflict`) and definite validation failure keep their
existing kinds, and the unlabelled-409 fallback is unchanged — pinned by test,
since `session_already_ended` legitimately relies on it.

`src/api/sessions.ts` was inspected and **deliberately left unchanged**: its
four operations assign absolute values (`locked_at = now()` / `= null`) or
carry their own terminal-state guards inside `DB::transaction`, verified
against `SessionController`. None move money. That classification is recorded
in the file so it is not "fixed" later by pattern-matching.

Evidence: 15 new tests across `src/api/cashbook.test.ts`,
`src/utils/mutation-error.test.ts`, `src/utils/mutation-error-alert.test.ts`.
Suite at mobile `2cad553`: **40 suites / 275 tests pass, `tsc --noEmit`
clean.** One of the four alert tests passed *before* the fix and is labelled in
the file as a regression lock rather than as evidence of repair.

**NOT RUN:** no device or emulator run. This is unit-level evidence about the
classifier, the presenter and the key on the wire. It does not establish
end-to-end behaviour on a handset.

## 7d. S3-05 second half — the asserted/rendering split — FIXED, narrowed by XR-06

The first half (`b216b80`) pinned `igst_mode` and the HSN map. This completes
the finding for every remaining field that **the document asserts about the
transaction**, and deliberately closes it no further than that.

**Correction (XR-06).** That claim was wrong when written. The parties'
identity — seller name, address, state (and so the place-of-supply line),
registration number; recipient name, mobile, address, ID and PAN — was
captured in the snapshot but still printed from the live shop and customer.
Repaired in `d31ab16` (§0b). The seller's phone, WhatsApp and email stay live
pending a product decision (D-17), so S3-05 is not claimed complete.

**The line drawn, and why it is not "pin everything".** A reprint carries two
kinds of field and they want opposite treatment:

| | fields | source after this change |
|---|---|---|
| **ASSERTED** — what the bill *claims* about the sale | `igst_mode`, `hsn_map`, `show_gstin`, `shop.gst_number`, `terms_and_conditions`, `upi_id`, `bank_name`, `bank_account_holder`, `bank_account_number`, `bank_ifsc`, `bank_account_type`, `bank_branch`, `bank_details` | the bill's own snapshot |
| **RENDERING** — how it is physically produced *today* | theme colour, font tier, paper size, subtitle, tagline, copy label + count, and the ten `show_*` column toggles other than `show_gstin` | **stays live, deliberately** |

Freezing paper size to a 2024 setting would be a defect, not a repair. **D-12
is the standing proof the rendering half was left alone** — it still asserts
theme colour drifting, and still passes.

`show_gstin` is the one `show_*` flag on the asserted side. Its neighbours
choose which *columns* appear; it chooses whether a statutory particular of a
tax invoice is on the page.

**No migration. Nothing new is captured.** Every one of these fields was
*already* written by `InvoiceRenderSnapshotService` (lines 91–132) and simply
went unread — the templates re-resolved them live. This is purely a read-path
change. The two snapshot-writer services in the diff are **comment-only**
edits, verified by reading the diff.

**Files.** `app/Services/BillTaxPresentation.php` → `BillPresentation.php`;
`resources/views/invoice_print.blade.php`;
`resources/views/quick-bills/print.blade.php`. Confirmed by grep that the only
`$billing?->` reads left in the invoice template are the sixteen rendering
fields, and that `gst_number` no longer appears outside the resolver call.

**Legacy rows resolve per KEY, not per section.** A snapshot written before a
key existed has no record of it → live settings. A snapshot that recorded the
key as `NULL` is recording that the bill printed *nothing* there → it must keep
printing nothing. `array_key_exists()` separates those; `??` collapses them.
Snapshots are never rewritten; all defaulting is at read time, so a mixed
estate resolves correctly at every intermediate state.

### Evidence, and one coverage claim that was wrong

`tests/Feature/Security/FinalizedInvoiceSettingsDriftTest.php` — **16 passed,
89 assertions.** Full band `tests/Feature/Security tests/Feature/Mobile` —
**287 passed, 1093 assertions.** Both re-run by me, not taken on report.

D-09, D-10 and D-11 were written in `2a6c7ce` as *characterization* tests
asserting the broken behaviour, with "when the remaining half is repaired,
these must flip" recorded in the file. **They are inverted here, and that flip
is the fix landing, not a test being weakened.** D-11b, D-13, D-13b and D-13c
are new.

Two mutations were actually applied and run, rather than reasoned about:

| mutation | killed | survived |
|---|---|---|
| `resolve()` ignores the asserted keys, always using live settings | D-09, D-10, D-11, D-11b | the rest, **incl. D-12 and D-13** |
| `resolve()` uses `??` in place of `array_key_exists()` | **D-13c only** | the rest, **incl. D-13** |

**CORRECTION — a claimed mutation result that measurement contradicted.** The
second row was first written as killing *D-13*, inferred from what D-13 is for.
When the mutation was actually applied, **all fifteen tests stayed green.** The
per-key fallback — the behaviour the resolver's docblock spends a paragraph
justifying — was described but bound by nothing. `??` and `array_key_exists()`
agree on an *absent* key, which is the only shape D-13 creates; they diverge
only on a key recorded as `NULL`. D-13c was written against the live mutation,
watched fail on the right assertion (`Not to contain: 77770000`), and passes
once reverted. The scenario it now pins: a shop invoices for months with no
bank account on the bill, then opens one — under `??` every already-issued bill
reprints carrying an account that was never on it, invisible because the
reprint looks *more* complete than the original rather than less.

The fixture's precondition assertion is load-bearing: it proves the key is
present-and-`NULL` before asserting anything, so the test cannot pass by
silently degrading into the missing-key case D-13 already covers.

`stripSnapshotKeys` updates `invoice_render_snapshots` directly. Checked
against `pg_trigger`: that table carries **no user triggers**, so the fixture
bypasses no constitutional guard. It is also deletion-only — it never writes a
fabricated value, which is the thing this whole finding argues against.

**CORRECTION — the rename does NOT follow in history, and `306aae1`'s own
commit message says it does.** The message claims "`git mv`, so history
follows". Checked after committing, and it is false in effect. `git mv` stages
a rename but Git *stores* none — it re-detects renames at read time by content
similarity, default threshold 50%. The file went 154 → 269 lines in the same
commit, so similarity fell below that and the rename decomposed into an
add plus a delete:

```
$ for t in 50 40 30 20 10; do git log --follow --find-renames=${t}% \
      --oneline -- app/Services/BillPresentation.php | wc -l; done
  -M50%: 1   -M40%: 1   -M30%: 1   -M20%: 2   -M10%: 2
```

`git log --follow` therefore stops at `306aae1`; it only reconnects to
`b216b80` at `--find-renames=20%`. Renaming and substantially rewriting in one
commit forfeits history-following — two commits would have kept it. The commit
message is not amended, because the wrong claim being visible next to its
correction is worth more than a tidy log. **Practical impact is contained:**
`build-security-review-packet.sh` lists *both* paths in its pathspec, so the
exported resolver diff spans the rename regardless.

### Stated limitations — NOT repaired

* **Quick bills carry less.** `QuickBillService::shopSnapshot` writes a FLAT
  payload holding `gst_number`, `terms_and_conditions`, `bank_details`,
  `upi_id` and `igst_mode`. It has **never** carried `show_gstin` or the
  itemised `bank_name`/`ifsc`/`branch` group, and this change does not start
  capturing them. The template's reads of those keys are left exactly as they
  were — routing them through the resolver would make a quick bill begin
  printing today's bank fields. Quick bills print no GSTIN at all.
* **Invoices finalized before these keys were captured** have no record of
  their presentation and fall back to live settings (D-06, D-13). A stated
  limitation, not a repair. Nothing can reconstruct a presentation never
  recorded.
* **NOT RUN:** no visual/PDF comparison and no device run. The evidence is
  rendered-HTML assertions through the real print route.

### §7d-1 — the ORIGINAL-reprint path, and S3-13 found while covering it

`quick-bills.print-original` had **no test coverage of any kind** — a grep for
`printOriginal` across `tests/` returned nothing before this session. It is the
one route whose entire purpose is to reproduce an as-issued document, so
claiming S3-05 repaired while leaving it unexercised would have been claiming
the repair on a path never run.

`tests/Feature/Security/QuickBillOriginalReprintTest.php` — **3 passed, 21
assertions.** Band after adding it: **290 passed, 1114 assertions.**

**Q-01 — the repair holds on the original path.** Issue intra-state, flip the
shop to inter-state and change the terms, edit the bill, then print both:

| | live bill | frozen original |
|---|---|---|
| tax | IGST | **CGST** |
| terms | today's | **as issued** |

Both halves are asserted in one test on purpose. "The original prints CGST"
alone would be satisfied by a system that never re-resolved anything; the live
bill printing IGST beside it is what shows the two documents genuinely resolve
from different snapshots. **Control:** the route 404s for a bill that was never
edited, so Q-01 cannot be passing against a route that serves the as-issued
document unconditionally.

**S3-13 — CHARACTERIZED, NOT REPAIRED.** `QuickBillService` writes
`'shop_snapshot' => $this->shopSnapshot($shop)` on the **shared** save path
used by both `create` and `update` (line 313), with no branch on whether the
bill already has one. So **every edit overwrites it with today's settings.**
Measured sequence: bill issued intra-state as `QB-1`; shop later switches to
inter-state for unrelated reasons; an operator corrects a typo in the
**customer name**; `QB-1` now prints IGST instead of CGST/SGST, and nothing in
the edit screen mentions tax.

*Two readings, and the test picks neither.* **Defensible:** an edit is a
re-issue — `edited_at` is set, the UI marks the bill edited, and the as-issued
original stays frozen and printable, which Q-01 proves. **Troubling:** the
re-characterization is a silent side effect of editing an unrelated field, on a
document whose number does not change. Which governs is a business decision
about what a quick-bill edit *means*, and it is the operator's, not an audit
repair to slip in.

*Severity bounded by measurement, not asserted.* Across the edit the bill
number and **every** stored figure are unchanged:

```
bill_number QB-1  cgst 1080.00  sgst 1080.00  igst 0.00
taxable 72000.00  total 74160.00        (identical before and after)
```

`igst_amount` is hard-coded to `0` on that save path and the template derives
the IGST line from the CGST/SGST pair, so total tax is identical under either
presentation. This is a **characterization** change, not an arithmetic one —
the same shape as S3-05 itself. It is not a tenancy finding and not a money
finding.

**CORRECTION — a guard I claimed was covered, and is not.** The test docblock
first said Q-01 holds `BillPresentation::forQuickBill`'s `spl_object_id` memo
key in place. It does not. I replaced the key with `$quickBill->id` and re-ran:
**all three tests stayed green.** The guard is unreachable today, for two
compounding reasons, both checked rather than reasoned:

* `BillPresentation` has **no container binding** (`grep` over `app/Providers/`
  returns nothing), so `app()` hands back a fresh instance per resolve and the
  memo dies with the render.
* Each template resolves it **once** and renders **one** bill; `print` and
  `print-original` are separate requests.

The memo therefore never holds two quick bills at once and the key cannot
collide. `spl_object_id` is the right defensive choice — it stays correct if
the class is ever bound as a singleton or a view ever renders both — but no
test can bind it without fabricating a scenario that does not exist, and
fabricating one would be writing a test to raise a count. **Recorded as
unbound, in both the test and the resolver, rather than implied to be covered.**

## 7e. Tenant isolation — cross-shop queries, relationships, jobs, exports

### This round (§0d Part 2) — what changed in the table below

* **Raw queries, scope removals:** now read per expression with the value of
  every shop filter traced (§0d Part 2): 179 tenant-facing rows read, each with
  a keyed verdict (`tests/Inventory/reviewed.tsv`); **35 admin and 122 console
  rows listed UNREAD**, visible in the sweep's output.
* **Request-supplied keys:** plus 37 reads outside validation, all read.
* **Relationships and joins:** every stored cross-shop reference is now
  countable, read-only: `tenant:audit-foreign-references`.
* **Queued jobs:** S3-17 repaired; the worker clears context between jobs.
* **Scheduled work:** S3-16's mechanism repaired, inactive by default.
* **Context lifecycle:** the recommendation below is implemented (on
  `Looping`, not `Queue::before` — §0d Part 3).
* **Files and downloads:** exports stored before S3-18's fix are refused.

### Completion round — every category inventoried (code at `11e91c3`, tests at `f3b1eba`)

The table below replaces the "Remaining gaps" column of the earlier table
further down, which is kept as the record of what was open. Status words:
**behavioural** — a test or real-process harness exercises it; **inspected** —
read, not exercised; **absent** — the category has no such path; **NOT RUN** —
named and not exercised. Nothing here declares the application secure: it
covers the categories and paths named.

Tools, re-runnable, read-only: `tests/Inventory/tenant_query_sweep.php`
(every query the scope does not govern, classified **per expression** — the
earlier 12/18-line window is withdrawn) and `tests/Inventory/request_id_sweep.php`
(every id-bearing validation key and how ownership is enforced at validation).

| Category | Entry points | Tenant authority | Enforcement | Evidence | Unresolved |
|---|---|---|---|---|---|
| Scoped models (95 with `BelongsToShop`) | route binding and queries in requests; datasets | production: `Auth::user()->shop_id` **during binding** (it runs after Authenticate, before `EnsureTenantUser`), then `TenantContext` | global scope, fail-closed on null | **behavioural**: 143 existing cross-shop tests in 84 files (reused); `TenantScopeFailClosedTest` (reused); **new** `production_binding_path.php` — the non-console path PHPUnit never takes: own 200, foreign 404, foreign PATCH changed nothing (7 cases) | the scope trusts any non-null context — see "context lifecycle" |
| Models with `shop_id`, no trait (15) | tenant-facing bindings: `User` (staff ×4, device revoke), `PlatformInvoice` (billing), `StockPurchaseItem` (vault) | explicit | `guardStaffTarget`, shop checks in `destroyAllForUser`, `BillingController@show`; purchase-identity guard | **inspected** (all three read); **behavioural**: vault lines (reused `StockPurchaseLineOwnershipTest`), staff edit/terminate/reactivate/role and device disconnect across shops (5 existing tests in `StaffManagementTest`, `StaffTerminateRecoverTest`, reused), and — **new** — another shop's platform billing invoice is refused (403, number not shown) while the shop's own opens (`TenantForeignReferenceTest`) | `IdempotencyKey`, `InvoicePaymentClaim`, `ScanSession`, `PendingUpload`, `MetalRate`, `ShopCounter`: explicit shop filters, inspected only; 7 platform models behind the platform guard |
| Ownership through a parent (8 tables without `shop_id`) | `invoice_items`, `catalog_collection_items`, `scan_events`, `role_permission`, `mobile_change_requests`, `platform_announcement_dismissals`, `personal_access_tokens`, `otps` | the parent row | web returns: `Rule::exists(...)->where('invoice_id')`; mobile returns: service loads lines through the caller's invoice; catalog: `validateItemsBelongToShop`; scan: token + shop (authenticated) or token only (public pairing, 48 chars); roles: scoped binding + global permission ids; the rest: own user/session | **behavioural (new)**: a mobile return naming another shop's line on the caller's own invoice is refused, nothing moves in either shop (`ReturnsApiTest`); **inspected**: the rest | `otps` carry no shop and are keyed by phone — by design |
| Raw queries (`DB::table`, raw SQL) | services, controllers, reports | explicit | `shop_id` in the same expression, or keyed by an owned record's id | **inspected**, per expression: tenant-facing writes without `shop_id` in the expression are keyed by an owned instance (`where('id', $owned->id)`), the caller's own user/session, or a payload whose `shop_id` comes from an owned record; no defect | expressions under `Admin/` and `Console/` were **not read individually** (counts in §8) |
| Scope removals | `withoutTenant()`, `withoutGlobalScope('shop')`, `withoutGlobalScopes()` | explicit | same-expression `shop_id`, token lookups (public catalog), or existence checks keyed by own ids | **inspected**, per expression; Dhiran's 3 scheduled commands enumerate shops then `runFor` each | Admin/Console as above |
| Request-supplied foreign keys | 168 id-bearing validation keys | the caller's shop | 61 `exists`+shop, 3 `exists`+parent, `*ExistsRule($shopId)` factories, or a scoped lookup afterwards | **behavioural**: all shape-only tenant-facing keys traced to their use; **two defects found and fixed — S3-14, S3-15** (`TenantForeignReferenceTest`); 3 `exists`-only rules are existence oracles whose value is unused or looked up through a scope (low) | — |
| Relationships and joins | eager loads, `whereHas`, raw joins | the parent's scope | related models' own scopes | **inspected**; S3-15 showed the edge: a stored foreign id was harmless only because every reader used the scoped relation | a future raw join on such a column would not be |
| Queued jobs (10) and listeners (none queued) | database queue (config default; `.env.example` says `sync`) | payload or the record itself | `runFor` in every tenant job; platform jobs carry explicit ids | **behavioural**: `queue_worker_tenant_reuse.php`, one real `queue:work` process — shop A, a job throwing inside A's context, a payload naming B's export under A, shop B: context absent before and after every job, each export names only its own shop, the mismatched payload writes nothing; `QueuedExportIsolationTest` (mismatch). **Found S3-18 (fixed) and S3-17 (open)** | production queue driver **not observed** |
| Scheduled and console work (19 scheduled) | `routes/console.php` | per-shop `runFor`, or explicit shop filters, or platform-level | — | **inspected** all 19; **behavioural**: `loyalty:expire` — **S3-16**, characterized | operator-run console commands not read individually |
| Tenant-context lifecycle | `EnsureTenantUser` (set, clear in `finally`), `ResolveCatalogShop`, `runFor` (restore in `finally`), `PaymentRaceHarness` | — | — | **behavioural**: the worker harness measured that **the worker does not clear context between jobs** — a job that sets context and returns leaves it for the next job. Safe today only because no application job does that | recommendation, not implemented: clear context in `Queue::before`. Octane not installed, **NOT RUN** |
| Files and downloads (13 serving actions) | attachments, KYC, imports, exports, backup | binding scope plus explicit shop checks | per action | **inspected** all 13; **behavioural**: reused suites for KYC, karigar/purchase attachments, export downloads; **new**: S3-18 and the backup workbook (`BackupWorkbookIsolationTest`, own rows present, nothing of the other shop; staff without `reports.export` 403; one mutation confirms the test can fail) | — |
| Cache and memoized state | 44 cache call sites; static memo | key content | every shop-specific key carries the shop id or a globally unique id; `MetalRegistry` memo keyed by shop; `HistoricalLifecycle` and the idempotency pin restored in `finally` | **inspected** | `MetalRegistry`'s per-shop memo goes stale in a long-lived worker until restart — correctness, not isolation |

**Defects found in this round** (details in §0a):

| ID | What | Class | Status |
|---|---|---|---|
| S3-14 | Web retail POS stored another shop's payment account on an append-only payment row; that shop could then not delete its own account | cross-shop write | **FIXED** `9f633e1` |
| S3-15 | Karigar invoice stored another shop's job order id | cross-shop reference | **FIXED** `9f633e1` |
| S3-16 | Scheduled `loyalty:expire` expires nothing: no tenant context; with context, a protected append-only trigger refuses its update | background execution | **OPEN** — characterized; needs a product/accounting decision |
| S3-17 | Every queued export fails after writing its file: the `database` notification channel has no `notifications` table | reporting reliability, not isolation | **OPEN** — needs a decision |
| S3-18 | Queued export files named by report and second: two shops' exports in one second shared a file; one shop's download served the other shop's data | **cross-shop exposure** | **FIXED** `11e91c3` |

**Also observed, not defects:** `MobileSessionSeatService` deletes every
mobile token idle for more than 24 hours, across all shops, whenever any shop's
user logs in on mobile — global housekeeping that another shop's action
triggers; whether idle devices should be logged out after 24 hours is a policy
question. `App\Exports\FullShopExport` and its sheets are referenced nowhere.

**NOT RUN:** Octane; a transaction-mode pooler; the production queue driver
and whether production has a `notifications` table (current configuration
only; the history is the separate evidence of R10); web mutations on the production binding path
(CSRF needs a session; GETs only there); admin and console expressions
individually; any device run.

### Earlier state of this section (kept as the record)

**Conclusion, at the strength the evidence supports: no defect found in the
inspected candidate set; missing-context fail-closed behaviour now has
regression coverage.** That is a statement about the candidates listed below,
not about the application. Every category has named gaps, and several were not
inventoried at all.

### Corrections to the first version of this section (`4877ae1`, `10a525c`)

Stated here rather than overwritten; the commits keep the original wording.

1. **"The real surface is `withoutTenant()`" is withdrawn.** It said the audit
   collapses to the places that drop the scope on purpose. My own inventory
   contradicted it in the same section: 227 raw-SQL sites the global scope never
   sees, and 13 models with a `shop_id` column and no `BelongsToShop`. It also
   ignored `withoutGlobalScope('shop')` / `withoutGlobalScopes()` (not covered by
   a `withoutTenant(` grep), tenant ownership that runs through a parent record,
   request-supplied related ids, and WRONG non-null context — which fail-closed
   does nothing about. Those are categories below, each with its own status.
2. **"No test bound the fail-closed branch" was false.** Run under the
   `whereRaw('1 = 0')` → `return;` mutation, `tests/Feature/Security` fails
   `InvoicePaymentRetryRepairTest::test_p10_missing_tenant_context_refuses_rather_than_reprocessing`
   (S3-07b) as well as the two new denial tests. P-10 binds the branch
   indirectly, through one route. The new test is direct coverage of the scope,
   not the first coverage. The test docblock made the same claim plus "leaves
   the rest of the band green"; corrected in `06ec0e0`.
3. **The route-binding check in `10a525c` was broken.** The grep pattern was
   `"$M \$"`, and a trailing `$` is an end-of-line anchor, so it matched nothing
   and reported nothing. Re-run as a fixed string: `StockPurchaseItem` is
   route-bound in `vaultLineForm` and `vaultLine` (`StockPurchaseController:183,200`).
   Both were missed; both are now tested.
4. **"The single site in the sweep with no second layer behind it" is
   withdrawn as a framing.** A correctly placed single guard is not a weakness
   for lacking a second one, and no production check was added to satisfy that
   label. There are three such sites, not one (item 3), and all three now have
   behavioural evidence.
5. **`[192/779 before this file]` in the §7e run figures was subtraction, not a
   run.** Removed. Selections are now reported separately with their commands.

### What `10a525c` covers, exactly

Documentation only — no test, no code. Inspection-grade:

| Covered by `10a525c` | Not covered by `10a525c` |
|---|---|
| Relationship **definitions** (reads): one `hasManyThrough`, two `belongsToMany`, zero relationships keyed on `shop_id` from a non-`Shop` model | Any behavioural evidence |
| Classification of the 13 unscoped models; how 5 tenant-facing ones are reached **by static call** | Route-model binding (its binding grep was broken, correction 3) |
| One request-supplied related-id **write**, `syncLines:609`, by reading | Foreign related-id assignment in general (other controllers, `exists:` rules, `find($request->…)`) |
| — | Writes in general; joins and eager loads onto unscoped models |

### Categories — inspected, behavioural, excluded, remaining

"Inspected" means read. "Behavioural" means a test exercises it. The 18 and 31
candidate sets are **inspected, not individually exercised.**

| Category | Inspected | Behavioural evidence | Explicit exclusions | Remaining gaps |
|---|---|---|---|---|
| **Missing context** (null) on `BelongsToShop` models | Trait source; `resolveTenantShopId` order | `TenantScopeFailClosedTest` 4 tests, mutation kills both denial tests; P-10 (indirect, one route) also fails under it | Models without the trait; raw SQL | Full suite not run under the mutation |
| **Wrong or stale non-null context** | Every `set`/`clear` in `app/`: `EnsureTenantUser` (set `:28`, clear in `finally` `:32`), `ResolveCatalogShop` (`:35`/`:65`), `PaymentRaceHarness` (console harness, one shop per process); 22 `runFor` sites (restore in `finally`); `GenerateQueuedExportJob` looks its row up inside the payload's context, so a mismatched id finds nothing | `ProductionTenantResolutionTest::test_tenant_context_does_not_persist_across_sequential_requests`; S3-06 (`721c06d`, context released on throw); `runFor` restore (`TenantScopeFailClosedTest` test 4); S3-08 examined and closed | — | **Fail-closed gives no protection here** — the scope trusts any non-null id. No test hands a job a mismatched shop id; no test runs two tenants' jobs in one long-lived worker |
| **Scope removal: `withoutTenant()`** | 144 sites / 58 files; 18 with no shop/key constraint within 12 lines, each read, 0 defects | Not individually exercised (the two public-catalog sites are covered by `PublicCatalogExposureTest`) | `app/Console/` (16 sites, operator-run) | 110 sites (144 − 16 − 18, derived) were **filtered out by the regex, not read**; a constraint within 12 lines was taken as sufficient |
| **Scope removal: `withoutGlobalScope('shop')` / `withoutGlobalScopes()`** | Not in the original sweep. Enumerated now: 15 code sites outside the trait — 9 platform-admin, 4 Dhiran, 2 tenant-facing. Both tenant-facing ones read: `ScanSessionController:232` and `SettingsController:1018` (audit-log CSV, streamed after context is cleared) carry an explicit `shop_id` | None | 9 platform-admin (cross-shop is the feature); 4 Dhiran (separate business audit, §2) | The 13 excluded sites were not read |
| **Raw SQL** (`DB::table`, `DB::select`) | 227 sites; in `Reporting`/`Services`/`Models`/`Http/Controllers` less `Admin/`, 31 with no `shop_id` within 18 lines, each read, 0 defects | Not individually exercised | `Admin/`, `Console/` | Sites with a nearby `shop_id` were not read; `DB::statement`/`update`/`insert`/`delete` and `whereRaw` joins not enumerated |
| **Models with `shop_id`, no trait** (13) | All 13 classified; 5 tenant-facing traced, now including route binding | `StockPurchaseItem`: `StockPurchaseLineOwnershipTest` (below) | 7 platform models; `User` (global by necessity) | `ScanSession`, `PendingUpload`, `ShopCounter`, `MetalRate`: explicit shop filters read in source, no new tests |
| **Ownership through a parent record** (tables with no `shop_id`) | `ScanEvent`, reached only through a token-resolved session | None new | — | **Not inventoried.** The tables outside the 114-table `shop_id` list were not enumerated. A route resolving such a child by request-supplied id without a parent check would bypass every scope |
| **Request-supplied related ids** | `syncLines:609` (body), `vaultLineForm`/`vaultLine` (URL), `vendor_id` (`Vendor::activeOrCurrentExistsRule`, shop-scoped) | `StockPurchaseLineOwnershipTest` L01–L03, V01–V03 (new); `PurchaseAndVendorAccessTest::test_purchase_create_rejects_another_shops_vendor` (pre-existing) | — | No inventory of `exists:` rules or `find($request->…)` in other controllers |
| **Relationship definitions** (reads) | As `10a525c` | None | — | Eager loads and joins onto unscoped models not enumerated |
| **Writes / mass assignment** | `create/update/fill($request->all())`: 0; `'shop_id' => '…'` in a `validate()` rule set: 0 | `PurchaseAndVendorAccessTest::test_vendor_create_forces_auth_shop_id` (pre-existing). Context-free create on a trait model is refused by the column: every such `shop_id` is NOT NULL (the four nullable ones — `metal_rates`, `shop_subscriptions`, `subscription_events`, `users` — belong to models without the trait; schema query, not a test) | — | Foreign **related** ids inside validated payloads — see above |
| **Jobs** (9 `ShouldQueue`) | `GenerateQueuedExportJob` only | None new | — | The other 8 not read in this pass |
| **Exports** | `ExportDownloadController`; audit-log CSV (`SettingsController:1018`) | `ExportDownloadAuthzTest`, `UrlKnowledgeAuthorizationTest` (pre-existing) | — | `ExportController::exportAllWorkbook` and per-report dataset builders not read; no test located for the audit-log CSV |

### Stock-purchase line ids — the evidence asked for

`StockPurchaseItem` has `shop_id` but not `BelongsToShop`, so `find()` and
implicit binding resolve a line from any shop. Three paths take that id from
the request, each guarded by one check against a purchase that is itself
tenant-scoped by its own binding:

| Site | Source of the id | Guard | Refusal shape |
|---|---|---|---|
| `syncLines:609-613` | `lines.*.id`, validated only `nullable\|integer` | `$line->stock_purchase_id === $purchase->id` | **Not a rejected request.** The id is disregarded and the submitted attributes become a new line on the caller's own purchase — data the caller could write anyway. The foreign row is never modified. **This matches the existing upsert contract:** the edit form posts each line's own id and `''` for a new one, and since the module was added (`86cfcd8`) an id that does not name one of this purchase's lines has meant "new line". The legitimate way to send one is a stale form — the line was removed in another tab — which L04 pins (`32ca54f`). Refusing only foreign ids would tell a caller which ids belong to other shops. No behaviour change proposed |
| `vaultLineForm:186` | `{line}` route binding | same | 404 before anything else |
| `vaultLine:203` | `{line}` route binding | same | 404 before validation or any write |

The guard compares **purchase** identity, not only shop identity, so it also
covers a line from the caller's own *other* purchase — which the operation
requires, since an edit to one draft must not reach into a second.

`tests/Feature/Security/StockPurchaseLineOwnershipTest.php` — **7 passed,
46 assertions** (6 / 39 at `06ec0e0`; L04 added in `32ca54f`). Every foreign fixture satisfies every *other* precondition of
its route (`bullion_reserve`, no lot yet, confirmed purchase), so ownership is
the only thing that can produce the refusal.

| Test | Asserts |
|---|---|
| L01 (control) | own line id edited **in place** — same row id, new value |
| L02 | foreign-shop id: B's line row, B's purchase row and B's `items`/`metal_lots`/`metal_movements` counts byte-identical; A's footprint unchanged too; neither of B's values in A's PUT response or the following show page (and the page is proven to be the edited purchase); A gains one new line, not B's id |
| L03 | same shop, other purchase: that line unmoved and unedited |
| V01 (control) | own qualifying line opens the vault form (200) — so the 404s are ownership, not route, edition, `vault.manage` or fixture |
| V02 | foreign-shop line: 404 on GET and POST, no values leaked, no lot minted on either shop |
| V03 | same shop, other purchase: 404, no lot |

Targeted mutations, run on the tree that became `06ec0e0` (test code identical):

| Mutation | Result |
|---|---|
| `:610` guard → `if ($line)` | **L02, L03 fail; the other 4 pass** — `Failed asserting that two arrays are identical` (the foreign row changed) |
| both vault ownership `abort_unless` lines deleted | **V02, V03 fail; the other 4 pass** — V02 `200 is identical to 404` (the form rendered shop B's line for shop A); V03 `302` |

Reverted each time: `md5 d80737994849ad718ea9e39e5a3f3751`, empty `git diff`,
6 passed again. No production change was made: each guard is correctly placed,
and the evidence now shows it holds.

### Fail-closed scope — retained evidence

`tests/Feature/Security/TenantScopeFailClosedTest.php` — 4 passed, 7
assertions: a precondition (both shops hold a row, so zero cannot mean an empty
table), the denial, a positive control (explicit context reads exactly its own
shop), and `runFor` restoring the previous context.

| Mutation `BelongsToShop.php:23` `whereRaw('1 = 0')` → `return;` | Result |
|---|---|
| this file alone | **2 failed, 2 passed** — `Failed asserting that 2 is identical to 0`: the context-free query saw both shops |
| all of `tests/Feature/Security` (202 tests at the time) | **3 failed, 199 passed (824 assertions)** — the two above plus P-10 |

Reverted: `md5 ed2014819134cc65d58daedf29293ea8`, empty diff.

### Where the audit's own sweep was weakest

The regex filters decided which sites got read. A constraint appearing inside
the window was treated as sufficient without checking that it constrains the
right thing — that is where a subtle defect would survive this sweep. The
categories marked "not inventoried" are larger unknowns than anything in the
candidate sets.

## 7g. Backup — A implemented locally, C proposed

**A (`3f1bd85`) — archive an allowlist instead of walking `base_path()`.**
spatie/laravel-backup 9.3.6 (`Tasks/Backup/FileSelection.php`) walks every
included directory in full and applies `shouldExclude()` to what the walk
yields. So an unreadable directory anywhere under the root aborts the run even
when it is excluded — the known traversal failure. An included **file** is
yielded without consulting `shouldExclude()` at all. The include list is now
`App\Support\BackupScope::INCLUDE`:

| Archived | Why |
|---|---|
| `.env` | APP_KEY; the **only** env file — nothing else named `.env*` can be selected |
| `storage/app` | uploads; every local disk root is inside it (tested) |
| `app`, `bootstrap`, `config`, `database`, `lang`, `public`, `resources`, `routes`, `artisan`, composer and npm lockfiles, build configs | the application at the deployed commit |

Excluded inside those: the backup destination (current and legacy), and
`bootstrap/cache` — its compiled `config.php` is a plaintext copy of every env
secret. Everything else at the top level is classified `NOT_ARCHIVED` with a
reason. `ignore_unreadable_directories` stays `false`, and a test pins it.

**No longer archived:** tests, docs, `*.md`, `bin`, `deploy`, `.github`,
`phpunit.xml`, `storage/logs`, `storage/framework` (sessions, cache, views),
`bootstrap/cache`, `.env.example`, and any `.env.*` the old exclude list did not
name — `.env.backup`, for example, would have been archived before. None is
restore content under `backup-and-restore.md`.

**Detecting future additions:** a new tracked top-level path, or a new local
disk rooted outside the archive, fails `BackupSourceExclusionTest`.
`php artisan backup:scope-check [path]` (read-only, exit 1) reports server-only
top-level entries nobody has classified.

**Evidence.** The old test built selections from a hand-copied exclude list, so
it stayed green whatever `config/backup.php` said. It now evaluates the real
config against a synthetic tree with unreadable `.git/objects`, `.claude` and
`output` directories:

* RED against the old config: `AccessDeniedException`; `.env.backup` and logs
  selected.
* GREEN: 11 tests.
* A fixture control shows the old shape still throws on the same tree.

A real `backup:run --only-files` on this worktree produced 2,604 entries. Its
top level was exactly the allowlist, and `.env` was the only env file. The
archive was deleted afterwards.

**Measured property:** if `.env` is missing, the run fails loudly with
`DirectoryNotFoundException` rather than producing an archive that cannot
decrypt encrypted columns. The DB dump for that run is lost with it, because
the job fails as a whole.

**C (`2d0b5f5`) — procedure change, not executed.**
`docs/runbooks/deploy-claude-ownership.md` contains the rule (no deploy step
changes ownership under `.claude/`), the exact replacement for each command
shape, read-only before/after verification, and the rollback. **The current
step's text was not visible here.** It is in none of the tracked files, the
ignored `output/audit` scripts, or earlier transcripts, so the reviewer has to
locate it in the operator's procedure.

A and C are independent. A removes the backup's dependency on `.claude/`
permissions; C removes a privilege concern and needs its own approval.

## 8. Commands actually run, and their results

### Review of `592d864` (§0e) — sequential, nothing else using `jewelflow_testing`

```
php artisan test                                   # at 4b53fb3e98a7ec1deea9ee82b44e3a941a73ce0f
  -> 3411 passed, 7 skipped (the pre-existing seven), 0 failed; 334.92 s

tests/Feature/Security/ExportNotificationDeliveryTest.php --filter=phase_2
  -> before b2c1094: 2 failed (panel 500, download 500 — 42P01), 1 passed (the authorization/legacy lock)
  -> after: 10 passed (the file); injected read-state refusal: download 200; mutant without the guard: 500
tests/Feature/Loyalty/LoyaltyWriteBoundaryTest.php
  -> before 0069839: 4 failed (stale model accepted; 110 -> 80 without a ledger row; balance_after
     chain [100,110,60,65] not produced; the route answered 500); after: 4 passed
tests/Feature/Security/TenantQuerySweepTrustRulesTest.php
  -> committed scanner: failed (7 of 10 review fixtures trusted: lines 29, 34, 50, 55, 68, 75, 82); after: passed
tests/Feature/Security/ReportReferenceIntegrityTest.php
  -> before f6d3473: 2 failed (day book named shop B's customer; cash flow shop B's user); after: 2 passed
tests/Feature/Security/TenantForeignReferenceTest.php --filter=through
  -> committed command: failed ("invoice_items 2" for line 5002); after: passed
tests/Feature/Security/QueuedExportIsolationTest.php (storage resolution)
  -> committed command: 2 failed (exit 0 on overlapping roots/scoped disk; exit 1 on two S3 stores); after: 9 passed
reporting + dashboard + inventory suites after the join hardening: 286 passed

php tests/Concurrency/loyalty_redeem_expiry_race.php
  -> before 0069839: interleaving 302, balance -70, "redeem 80 -> 30"; race: 1 negative, 1 broken chain,
     24 x 500, 59 of 60 expired — UNSAFE
  -> after: interleaving refused against 10, nothing written; race: 0 negative, 0 mismatch; lock scenario:
     balance 10 — SAFE. Lock removed: race SAFE twice by chance; lock scenario -70, UNSAFE
php tests/Concurrency/<the other eight>.php            -> SAFE (idempotency_race read per scenario: A, A2, B, C, D as expected)
php tests/Rehearsal/migration_rollback_rehearsal.php  -> 44 PASS, 0 FAIL, 23 MEASURED — including section C2, the
                                                          application between Phase 1 and Phase 2b
php tests/Rehearsal/contract_migration_locks.php      -> all checks passed
php tests/Inventory/tenant_query_sweep.php            -> 871 expressions, parse failures 0; tenant 284 read, admin 61
                                                          and console 139 UNREAD
php artisan tenant:audit-foreign-references           -> 238 references, 0 crossing (READ ONLY)
```

### Review of `c9d30b0` (§0d) — sequential, nothing else using `jewelflow_testing`

```
php artisan test                                   # at 530e6344ed2ed721aa3c09930a338fc28cd8f054
  -> 3398 passed, 7 skipped (the pre-existing seven), 0 failed; 631.73 s
     (70681a4 changes no application file and no PHPUnit test)

php artisan test tests/Feature/Security/ExportNotificationDeliveryTest.php
  -> at b2b6e77 with the S3-17 code set aside: 4 failed, 0 assertions
     (relation "notifications" does not exist); with it: 6 passed, 44 assertions
     mutant, no re-run guard in the job   -> the re-run test fails ('not regenerated')
     mutant, no re-check under the lock   -> the re-run test fails (2 notifications)
php artisan test tests/Feature/Reporting tests/Feature/Security
  -> 536 passed (at cfda2a0, before the later Security additions)

php artisan test tests/Feature/Loyalty/LoyaltyExpiryTest.php
  -> before 5efc66f: 7 failed (column "expires_lot_id" does not exist)
     after: 7 passed, 47 assertions
     mutants — no tenant context: 7 failed; reversal ignoring expiry: 1 failed
     (cancellation); latest-expiry first: 1 failed (redemption); no balance
     check: 1 failed (drift)
php artisan test tests/Feature/Loyalty tests/Feature/Returns tests/Feature/Historical \
  tests/Feature/Security tests/Feature/ConstitutionalInvariantsTest.php tests/Feature/InvoiceFlowTest.php \
  tests/Feature/RefundPolicyMathTest.php tests/Feature/CustomerContextTest.php
  -> 787 passed, 7 skipped (at 5efc66f)

php artisan test tests/Feature/Security/TenantForeignReferenceTest.php    -> 9 passed, 39 assertions
php artisan test tests/Feature/Security/MobileLookupIsolationTest.php     -> 3 passed, 32 assertions
  mutant, the scope applies no shop filter -> lookups test fails; diagnostic:
  customers/search, items/search, vendors/lookup, karigars/lookup, vendors leak;
  stock, catalog/items, pos/bootstrap stay clean (explicit filters)

php artisan migrate:rollback --step=1   # expires_lot_id with 150 expiry rows present
  -> RuntimeException, column and rows kept

php tests/Concurrency/queue_worker_tenant_reuse.php   -> SAFE (every probe null; A and B done, each
                                                          notified to its own owner)
php tests/Concurrency/production_binding_path.php     -> SAFE
php tests/Concurrency/loyalty_expiry_race.php         -> SAFE: 3 runs overlapping 1.47 s, 150 lots expired
                                                          once, balances 10, ledger = balance
  control, customer lock removed                      -> UNSAFE: 2 of 3 runs failed on the unique index;
                                                          still one expiry per lot (the wrapper masked
                                                          exit codes in that run; fixed before the SAFE run)
php tests/Concurrency/idempotency_race.php            -> A one 201 + 3 in-flight, 1 row; A2 replay; B 1 row;
                                                          C 4 rows; D 500, 0 rows (after the 70681a4 fix;
                                                          before it D's child was refused, "no_output")
php tests/Concurrency/claim_release_race.php          -> SAFE, both scenarios
php tests/Concurrency/etag_race.php                   -> SAFE
php tests/Concurrency/etag_image_race.php             -> SAFE
php tests/Concurrency/relocation_race.php             -> SAFE, both scenarios
php tests/Rehearsal/migration_rollback_rehearsal.php  -> 34 PASS, 23 MEASURED, all ten migrations; populated
                                                          600 exports + 1200 loyalty rows; no row lost
php tests/Rehearsal/contract_migration_locks.php      -> all checks passed

DB_DATABASE=refusal_probe_nonexistent php <script>    -> "REFUSED" before any query, for:
  production_binding_path.php (parent, and the `request` child), idempotency_race.php (parent, and the
  `fire` child), queue_worker_tenant_reuse.php, loyalty_expiry_race.php, tenant_query_sweep.php,
  migration_rollback_rehearsal.php; DB_DATABASE=jewelflow_testing_absent (the parent) -> refused

php tests/Inventory/tenant_query_sweep.php   -> 871 expressions; parse failures 0; tenant 179 read,
                                                admin 35 and console 122 UNREAD
php tests/Inventory/request_id_sweep.php     -> 168 keys; 37 request reads, 28 to read (all read)
php tests/Inventory/test_family_map.php      -> 239 cross-shop files; 246 families; 75 with none
php artisan tenant:audit-foreign-references  -> 238 references, 0 crossing (READ ONLY transaction)

mobile 4f10a3b: npx jest src/utils/mutation-error*.test.ts -> before: 3 failed, 29 passed; after: all pass
                npx jest -> 280/280 in three consecutive runs (the run before them: 1 failed, name not
                captured, not reproduced); npx tsc --noEmit -> clean
```

```
php artisan test tests/Feature/Security/SignatureImmutabilityEndToEndTest.php
  -> 7 passed, 65 assertions

php artisan test tests/Feature/Security/FinalizedInvoiceSettingsDriftTest.php
  -> 8 passed, 31 assertions

php artisan test tests/Feature/Security/PublicCatalogExposureTest.php
  -> BEFORE the fix: 1 failed, 4 passed (15 assertions)
     C-05 "Failed asserting that 6 is null" -- 6 is the shop id, still
     pinned after the handler threw. RED for the intended reason.
  -> AFTER  the fix: 5 passed, 16 assertions

php artisan test --filter='Catalog|Tenant|Middleware|Share'
  -> 120 passed, 414 assertions   (S3-06 regression band)

php artisan test tests/Feature/Security/InvoicePaymentIdempotencyScopeTest.php
  -> 5 passed, 13 assertions
     Passed on the FIRST run -- existing behaviour was already correct, so
     these are regression tests, not TDD-first ones, and they prove less
     on their own. Mutation is what gives them teeth; see S3-07 in 7b.

php artisan test tests/Feature/Security tests/Feature/Mobile
  -> 221 passed, 766 assertions   (re-measured after S3-07)
     Was 216 / 753 after S3-06.
     Was 211 / 737 before PublicCatalogExposureTest existed. Arithmetic
     would have predicted 216 / 753 and would have been right -- it was
     re-run anyway, because three wrong diffstats earlier in this session
     all came from computing a figure instead of measuring one.

php artisan test --filter='Invoice|Sales|Exchange|Installment|Return|QuickBill|Repair|Gst|Tax|Snapshot|Setting'
  -> 599 passed, 3 skipped, 2324 assertions   (re-run after S3-06 + S3-07)
     Was 594 / 3 / 2311. The delta is exactly the 5 tests and 13 assertions
     of InvoicePaymentIdempotencyScopeTest, which this filter picks up on
     'Invoice'. No pre-existing test changed result.

--- re-measured at eca2e8b, both pinned commands re-run verbatim ---

php artisan test tests/Feature/Security tests/Feature/Mobile
  -> 228 passed, 801 assertions
     +7 tests / +35 assertions against the 221 / 766 pin. Accounted for
     exactly: InvoicePaymentIdempotencyScopeTest 5 -> 9 (+4 tests, +19
     assertions) and InvoicePaymentRetryIntegrityTest (+3, +16).

php artisan test --filter='Invoice|Sales|Exchange|Installment|Return|QuickBill|Repair|Gst|Tax|Snapshot|Setting' --display-skipped
  -> 606 passed, 3 skipped, 2359 assertions
     +7 / +35 against the 599 / 3 / 2324 pin, the same seven tests.
     Skipped count unchanged, and now broken out by name in section 1a D2.

--- re-measured at 25355ff, the candidate SHA, both pinned commands again ---

php artisan test tests/Feature/Security tests/Feature/Mobile
  -> 235 passed, 829 assertions
     +7 tests / +28 assertions against the 228 / 801 figure above.
     Accounted for exactly, and by subtraction from the per-file runs
     rather than by assuming: FinalizedInvoiceSettingsDriftTest 8 -> 12
     (+4 tests, +17 assertions, D-09..D-12) and PublicCatalogExposureTest
     5 -> 8 (+3, +11, C-06..C-08).

php artisan test --filter='Invoice|Sales|Exchange|Installment|Return|QuickBill|Repair|Gst|Tax|Snapshot|Setting' --display-skipped
  -> 610 passed, 3 skipped, 2376 assertions
     +4 / +17 against 606 / 3 / 2359 -- the D-09..D-12 four only.
     Skipped still 3, still all in ConstitutionalInvariantsTest.
```

### These two selections overlap and their totals are not comparable

**235 and 610 are not two measurements of one thing.** The first selects by
*path* (`tests/Feature/Security`, `tests/Feature/Mobile`); the second selects by
an eleven-term *name filter* that reaches across the whole suite. They overlap
partially and neither contains the other, so subtracting one total from the
other produces a number that means nothing. They are reported side by side and
never summed, differenced, or reconciled against each other.

**The "difference of three" is about newly added coverage only — not about
totals.** Seven tests were added since the `eca2e8b` figures:

| New tests | Picked up by path selection | Picked up by name filter |
|---|---|---|
| D-09…D-12 (`FinalizedInvoiceSettingsDriftTest`) | yes — 4 | yes — 4, via `Invoice` |
| C-06…C-08 (`PublicCatalogExposureTest`) | yes — 3 | **no** — filter has no `Catalog` term |
| **Total new tests seen** | **7** | **4** |

So the path selection grew by 7 and the filter grew by 4. **That gap of three is
C-06…C-08 being outside the filter's terms**, and it is a statement about which
new tests each selection can see — not a statement about 235 versus 610.

### Execution SHA vs package SHA — reported separately

There are now **two** execution SHAs, because a source repair landed after the
first set of figures was taken. They are listed separately rather than merged:

| | SHA | What was measured there |
|---|---|---|
| **Execution SHA — S3-05/S3-06/catalogue figures** | `25355ffee351bd8a2d9e5878ef2c0f83888fb87f` | every figure in this section *except* the S3-07b block below |
| **Execution SHA — S3-07b figures** | `2dd0875788e21d31270c746f45bd8cf34382a750` | the repair suite, the inverted regressions, and the 246-test band |
| **Package / documentation SHA** | *(pinned at export time; see the packet manifest)* | what the exported packet is pinned to |

**A tested tree that was not the committed tree, caught and corrected.** The
246-test band was first run *before* R-01 and R-02 were renamed, so the tree that
produced that number was not the tree that got committed. The rename is
cosmetic — method names only — but it touched test files, and "cosmetic" is a
judgement, not a measurement. The band was therefore **re-run at `2dd0875` with
`git status` showing only this handoff modified**, and reported from that run.
That is a different situation from the documentation-only gap below, where
re-running would have been theatre; here the tested artifact genuinely differed.

```
HEAD=2dd0875788e21d31270c746f45bd8cf34382a750
 M docs/runbooks/security-multi-tenant-audit-handoff.md     <- only diff

php artisan test tests/Feature/Security tests/Feature/Mobile
  -> Tests: 246 passed (879 assertions)

php artisan test tests/Feature/Security/InvoicePaymentRetryRepairTest.php \
                 tests/Feature/Security/InvoicePaymentRetryIntegrityTest.php
  -> Tests: 14 passed (66 assertions)   [P-01..P-11 + R-01..R-03]
```

The band moved from **245 passed / 1 failed** to **246 passed / 0 failed**. The
single failure was `I-06`, `Failed asserting that 201 is identical to 200` —
raised by my own repair replaying the stored 201. **The pre-existing test was
treated as authoritative and the new code was changed**, not the test: clients in
the field already depend on 200. That is recorded because the opposite choice
would have been invisible in a green suite.

**For the `25355ff` → `941ed74` pair only, the difference is documentation
only**, and it is this section. This claim is scoped deliberately: it does **not**
extend past `941ed74`, because `2dd0875` afterwards added source, a model, a
migration and two test files for S3-07b. Those carry their own execution SHA in
the table above and are not covered by the diff below.

```
git diff --stat 25355ff..941ed74
 docs/runbooks/security-multi-tenant-audit-handoff.md | 31 +++++++++++++++
 1 file changed, 31 insertions(+)

git diff --name-only 25355ff..941ed74 | grep -v '^docs/'
 (no output — no non-documentation path was touched)
```

No source, test, migration or config file differs between the commit the tests
ran at and the commit the packet ships. **The suites were deliberately not re-run
to make the two SHAs identical** — re-running a sixty-second suite so that a
label matches would be manufacturing the appearance of freshness, and the
verifiable claim ("the only diff is documentation, here it is") is stronger than
the cosmetic one.

**Why this block exists at all.** The review packet tells a reviewer that measured
results live in this section. Two commits after the `eca2e8b` re-measure added
tests, so the numbers above it no longer described the commit the packet ships.
Both commands were re-run verbatim rather than adjusted by arithmetic — the
deltas reconcile, but reconciliation is the *check*, not the source of the
figures.

**A discrepancy I raised against myself, and its resolution.** An interim report
quoted `--filter='Security|Mobile'` at 474 / 5208 and
`--filter='Invoice|Payment|QuickBill|Pos'` at 428 / 1 skipped / 1517, and noted
these did not match the 221 / 766 and 599 / 3 / 2324 pins above. **They were
never meant to.** Neither of those is the pinned command: the first pin selects
by *path* and the second uses an eleven-term filter, while the interim figures
came from two ad-hoc filters I had typed for other reasons. Re-running both
pinned commands verbatim, as above, reconciles to the test. **No test vanished
and none changed result** — the apparent gap was filter drift in my own
reporting. It is recorded rather than silently corrected because "the numbers
moved" was my claim, and the retraction should be as visible as the claim.

```
php artisan test tests/Feature/Security/FinalizedInvoiceSettingsDriftTest.php
  -> D-09/D-10/D-11 asserting the DESIRED behaviour: 4 failed (12 assertions)
     D-09  Not to contain: 99990000
     D-10  Not to contain: No exchange under any circumstances.
     D-11  Not to contain: 29BBBBB9999B1Z5
     D-12 failed separately on a TypeError -- null needle, because the
     fixture returns the DRAFT model and invoice_number is assigned during
     finalization. A fixture bug, easy to mistake for the template failing
     to print the number at all. Fixed by re-reading the row.
  -> inverted to characterization: 12 passed, 48 assertions
     File restored from a pre-mutation copy; md5sum identical and
     `git diff --stat` showed additions only.
```

**Those three flipped when the second half landed (§7d).** The runs:

```
php artisan test tests/Feature/Security/FinalizedInvoiceSettingsDriftTest.php
  -> after the repair, D-09/D-10/D-11 re-inverted to assert the AS-ISSUED
     value: 15 passed (80 assertions)

MUTATION 4 -- resolve() ignores the asserted keys, always using live settings
  -> 4 failed, 11 passed (75 assertions)
     killed:    D-09 D-10 D-11 D-11b
     survived:  D-12 (rendering left alone, as intended) and D-13

MUTATION 5 -- resolve() uses `??` in place of array_key_exists()
  -> 15 passed (80 assertions)   <-- KILLED NOTHING. The coverage claim
     written for this row said it killed D-13. Measurement contradicted it.

  D-13c added against the live mutation:
  -> RED: 1 failed (6 assertions), "Not to contain: 77770000"
          -- the 6 assertions confirm the present-and-NULL precondition ran
             first, so the failure is the resolver, not the fixture
  -> mutation reverted, md5sum verified against the pre-mutation copy
  -> GREEN: 16 passed (89 assertions)

php artisan test tests/Feature/Security tests/Feature/Mobile
  -> 287 passed (1093 assertions)
```

Every one of these was re-run directly rather than accepted from a report; the
band figure differs from the 286/1084 first reported to me by exactly D-13c
(+1 test, +9 assertions).

**The Vite failures, reported explicitly.** The second command first returned
**63 failed**. Every one of them was `Vite manifest not found` — 212 occurrences
across 63 tests, with no second cause. `.gitignore:20` excludes `/public/build`,
and `git worktree add` materialises only tracked files, so a fresh worktree has
no built assets and every test rendering a view that extends
`layouts/app.blade.php` dies in `@vite`. 63 was the number of tests that render a
layout, not the number of broken ones. Restored by symlinking `node_modules` from
the primary checkout and running `npm run build` in the worktree; the rerun above
is the result. **This was not caused by, and did not mask, any change in this
branch.**

### S3-09 run — at `b8673db`

```
HEAD=b8673dbbcf... (fix(S3-09): stake the idempotency claim before the controller)

php artisan test tests/Feature/Security/MobileIdempotencyRetryIntegrityTest.php \
                 tests/Feature/Mobile/V1/IdempotencyMiddlewareTest.php
  -> Tests: 17 passed (89 assertions)
     8 S3-09 evidence tests + the 9 pre-existing contract tests, all green.
     The contract tests were run BEFORE the repair too, and passed then as
     well — that is what establishes the repair changed no documented
     behaviour, rather than assuming it.

php artisan test tests/Feature/Mobile
  -> Tests: 98 passed (335 assertions)

php artisan test tests/Feature/Security
  -> Tests: 160 passed (619 assertions)

php artisan test tests/Feature/Masters/KarigarLifecycleTest.php \
                 tests/Feature/SubscriptionRecoveryCorrectionTest.php \
                 tests/Feature/SubscriptionRecoveryLifecycleTest.php
  -> Tests: 48 passed (156 assertions)
     The only tests outside the two suites above that touch /api/mobile/v1,
     found by grep rather than assumed absent. Run because the 4xx-release /
     5xx-hold policy changes behaviour for all 16 routes, not just the four
     inspected.
```

Intermediate states, recorded because they are the evidence the tests were
measuring something:

```
Before the repair:  4 failed, 11 passed  -- the 3 defect tests plus the returns
                    control failing as characterization, 9 contract tests green.
After the repair:   the same 4 failed, because the injections were now hitting
                    the new pre-controller stake (3x 503, 1x "1 is identical
                    to 0"). Injection retargeted from IdempotencyKey::creating
                    to ::updating -- the window the repair actually leaves.
Then:               1 failed -- the returns control, now answered 409 by the
                    middleware before ReturnService was reached. Fixed by
                    retrying with a DIFFERENT key, which is the only way to
                    isolate the service guard post-repair.
```

Scenario-family coverage, reported separately from the test count because a count
is not coverage:

| Family | Covered |
|---|---|
| Authorized positive control | yes |
| Another shop | yes |
| Same-shop staff lacking permission | yes (G-15) |
| Guest | yes (G-01) |
| Legacy storage compatibility | yes (G-11, G-19, E-07) |
| New/private storage | yes |
| Mixed-disk estate | yes |
| Missing / unreadable / invalid signature | yes (G-07, G-08, G-20) |
| Immutable historical rendering | yes (E-01…E-07) |
| Immutable tax characterization | yes (D-01…D-08) |
| Release ordering | yes (T-01…T-07) |
| Unauthenticated route, cross-shop isolation | yes (C-04) |
| Unauthenticated route, context release on throw | yes (C-02, C-05) |
| Publication consent gate, both halves | yes (C-01 enabled, C-02 not enabled) |
| Four principals on a mobile mutation route | yes (I-01…I-04) |
| Cached-response cross-tenant replay | yes (I-05, body-level assertion) |
| Direct asset URL carries no gate or shop identity | yes (C-06) |
| Shopfront disabled after publication | yes (C-07 — page 404s, file stays put) |
| Private-disk serve route, unsigned vs signed | yes (C-08, denial + positive control) |
| Live-setting drift, business identity | yes (D-09 bank, D-10 terms, D-11 GSTIN) |
| Live-setting drift, genuinely cosmetic | yes (D-12 — the bound on the above) |
| S3-09 crash window, money route (cash) | yes — cash rows and drawer total asserted |
| S3-09 crash window, money route (EMI) | yes — payment row, cash-in and `emis_paid` asserted |
| S3-09 crash window, metal route | yes — receipt, `items`, `metal_movements`, `returned_fine_weight` asserted |
| S3-09 concurrency, loser never reaches controller | yes — spy, with a positive control proving the spy can fire |
| S3-09 route with its own durable guard (control) | yes — returns, retried under a *different* key to exclude the middleware |
| S3-09 authorized success / distinct-key controls | yes — single entry succeeds; two distinct keys book two entries |
| **S3-09 service-layer dedup on the other 12 routes** | **NOT RUN — see the per-route table in §7c** |
| **S3-09 per-line `returned_at` guard on a partial return** | **NOT RUN — no multi-line finalized-invoice fixture on the mobile return path** |
| **S3-09 true wall-clock concurrency** | **NOT RUN — the code path is driven, the timing is not; single-process PHPUnit cannot** |
| **Per-item publication opt-out** | **none exists — characterized by C-03, not covered** |
| **Anonymous HTTP fetch of a public-disk file** | **NOT RUN — served by nginx, not Laravel; unmeasurable from the suite** |
| **On-device print** | **NOT RUN — see §5** |
| **Edge cache behaviour** | **NOT RUN — no Cloudflare access** |
| Tenant scope denies with no context (queue-worker state) | yes — mutation-killed, with a precondition proving the rows exist |
| Tenant scope reads its own shop under explicit context | yes — positive control, so the denial is not a dead scope |
| `runFor` restores the previous context on exit | yes |
| **Wrong or stale non-null tenant context** | **PARTIAL — sequential-request persistence and throw-release tested; mismatched job payload and multi-tenant long-lived worker NOT RUN** |
| Request-supplied stock-purchase line id, body and URL | yes — L01–L03, V01–V03; both guards mutation-killed |
| **The 18 `withoutTenant()` candidates** | **INSPECTED, NOT INDIVIDUALLY EXERCISED — §7e** |
| **The 31 tenant-facing raw-SQL candidates** | **INSPECTED, NOT INDIVIDUALLY EXERCISED — §7e** |
| **Tables owned through a parent record** | **NOT INVENTORIED — §7e** |
| **Foreign related ids outside stock purchases** | **NOT INVENTORIED — §7e** |
| **Context-free CREATE on a tenant model** | **NOT TESTED — refused by NOT NULL on every trait model's `shop_id` (schema query)** |

### §7e run figures

Execution SHA for everything in this block: **`06ec0e0bcdefbe2f952757fd3f4a0b408aede656`**,
`git status --porcelain` empty. The mutation runs were made on the working tree
that became that commit; the only difference is docblock text in
`TenantScopeFailClosedTest`.

**The two selections below are different selections and are not comparable
totals.** Selection A is one directory; B is two. Earlier figures are listed
with their own selection so none of them is read as a before/after of another.

```
# A — tests/Feature/Security only
php artisan test tests/Feature/Security/
  -> 202 passed (825 assertions)                     at 06ec0e0
     (earlier A-selection runs: 196/786 at 4877ae1's tree)

# B — tests/Feature/Security + tests/Feature/Mobile
php artisan test tests/Feature/Security tests/Feature/Mobile
  -> 300 passed (1160 assertions)                    at 06ec0e0
     (earlier B-selection runs, each recorded where it was made:
      290/1114 §7d-1, 287/1093 §7d, 283/1052 §7c-6, 273/1028 §1, 269/1016 §7c-2)

php artisan test tests/Feature/Security/StockPurchaseLineOwnershipTest.php
  -> 6 passed (39 assertions)
  mutation :610 guard -> `if ($line)`            -> 2 failed (L02, L03), 4 passed
  mutation vault ownership aborts deleted        -> 2 failed (V02, V03), 4 passed
  reverted: md5 d80737994849ad718ea9e39e5a3f3751, git diff empty, 6 passed

php artisan test tests/Feature/Security/TenantScopeFailClosedTest.php
  -> 4 passed (7 assertions)
  mutation BelongsToShop.php:23 -> `return;`     -> 2 failed, 2 passed
  same mutation, php artisan test tests/Feature/Security/
                                                 -> 3 failed, 199 passed (824 assertions)
                                                    failures: the 2 above + P-10
  reverted: md5 ed2014819134cc65d58daedf29293ea8, git diff empty

# Mobile — /home/himanshu/Desktop/jewelflowMobileApp
HEAD=2cad553b50e77890088488a43b72a2e7bd4f68ef
 M src/utils/storage.ts        <- pre-existing, unrelated, not part of the audit
npx jest --ci                  -> 40 suites, 275 tests passed
npx tsc --noEmit               -> exit 0
```

Scan figures, for reproduction:

```
grep -rn "withoutTenant(" app/            -> 144 occurrences, 58 files
  minus app/Console/ (16), minus a shop/key constraint within 12 lines
                                          -> 18 candidates read, 0 defects
grep -rn "withoutGlobalScope" app/        -> 15 code sites outside the trait
                                             (9 admin, 4 Dhiran, 2 tenant-facing read)
grep -rn "DB::table(|DB::select(" app/    -> 227 occurrences
  restricted to app/{Reporting,Services,Models,Http/Controllers} less Admin/
  and with no shop_id within 18 lines     -> 31 candidates read, 0 defects
grep -rnF "StockPurchaseItem \$" app/Http/Controllers/
                                          -> 2 route-bound sites (the earlier
                                             grep used a trailing `$` anchor
                                             and matched nothing)
grep for create/update/fill($request->all())        -> 0 (8 matches are Collection->all())
grep for 'shop_id' => '...' in a validate() ruleset -> 0
information_schema: shop_id nullable                -> 4 tables, none on a trait model
```

### Round-3 run figures — execution SHA `9d483314be5f402796681ac6aba5fffc6398f0f1`

Superseded as the latest full-suite measurement by the XR round below; kept as
the record of that SHA.

Every run below was made at `9d48331` with `git status` showing only `docs/`
files modified — the handoff, the packet builder, two runbooks. None of those
is read by any test. The per-file figures further down were measured when each
file was added, and the full-suite run supersedes them as the final
measurement.

**These are four different selections. Their totals are not comparable with
one another or with earlier rounds.**

```
# FULL — php artisan test (the whole suite)
php artisan test
  -> 3333 passed, 7 skipped, 0 failed (16222 assertions), 285.42 s
     skipped, all pre-existing: 6 data-dependent skips in ConstitutionalInvariantsTest
     (unchanged by the branch; see known-pre-existing-test-debt.md) and 1 browser-only
     contract ("typing triggered auto expansion is a browser only contract")

# A — tests/Feature/Security only
php artisan test tests/Feature/Security/
  -> 222 passed (940 assertions)

# B — tests/Feature/Security + tests/Feature/Mobile
php artisan test tests/Feature/Security tests/Feature/Mobile
  -> 320 passed (1275 assertions)

# Rehearsal — php tests/Rehearsal/migration_rollback_rehearsal.php
== A. Baseline schema from the 018b3d8 migration set ==
PASS      the branch adds exactly the seven migrations under test — 373 baseline files
PASS      no baseline migration was removed
MEASURED  baseline migrate:fresh — 7119 ms
PASS      with default paths, exactly the seven branch migrations are pending — 2026_09_15_120000_add_invoice_file_disk_to_karigar_invoices, 2026_09_15_140000_add_invoice_image_disk_to_stock_purchases
PASS      none of their objects exist yet
== B. Synthetic population at the baseline schema ==
MEASURED  populated — {"karigar_invoices":1200,"stock_purchases":1200,"idempotency_keys":3000,"shop_billing_settings":3}
== C. Expand phase and the independent tables, applied to populated data ==
MEASURED  up 2026_09_15_120000_add_invoice_file_disk_to_karigar_invoices — 24 ms
MEASURED  up 2026_09_15_140000_add_invoice_image_disk_to_stock_purchases — 21 ms
MEASURED  up 2026_09_16_120000_add_digital_signature_disk_to_billing_settings — 14 ms
MEASURED  up 2026_09_20_120000_create_signature_relocations_table — 25 ms
MEASURED  up 2026_09_21_120000_create_invoice_payment_claims_table — 19 ms
MEASURED  up 2026_09_23_120000_add_response_headers_to_idempotency_keys — 6 ms
PASS      karigar backfill: every row with a file labelled public — 801 rows
PASS      karigar backfill: no disk invented for a row without a file
PASS      paid (frozen) karigar invoices were backfilled past the finalized guard
PASS      purchase backfill complete
PASS      signature backfill: the two shops with a signature
PASS      relocation ledger and payment-claim tables created
PASS      response_headers added without rewriting idempotency_keys — relfilenode 10636891 unchanged
PASS      no headers invented for existing claims
== D. The window between expand and contract, then contract ==
[contract/130000] karigar_invoices: recorded 'public' for 5 expand-window attachment(s), cleared 0 stranded disk value(s).
[contract/130000] stock_purchases: recorded 'public' for 5 expand-window attachment(s), cleared 1 stranded disk value(s).
MEASURED  up 2026_09_20_130000_add_disk_column_check_constraints — 21 ms
PASS      all three constraints present and VALIDATED — [{"conname":"shop_billing_settings_digital_signature_disk_check","convalidated":true},{"conname":"karigar_invoices_attachment_disk_check","conva
PASS      window rows written by baseline code were labelled, not rejected
PASS      the stranded disk value was cleared
PASS      private labels untouched by the reconcile
PASS      a baseline-shaped write is refused once the contract phase exists — hence code before contract
== E. New code on the fully migrated schema ==
PASS      S3-09e: replay carries the original ETag
== F. Rollback, newest first, on the same data ==
MEASURED  down 2026_09_20_130000_add_disk_column_check_constraints — 12 ms
PASS      contract down: constraints gone
PASS      contract down: baseline-shaped writes accepted again
MEASURED  down 2026_09_23_120000_add_response_headers_to_idempotency_keys — 9 ms
PASS      headers column dropped under new code: claim still resolves, replay loses only the headers — claim status 200
MEASURED  down 2026_09_21_120000_create_invoice_payment_claims_table — 8 ms
PASS      claims down: table dropped (schema step only — see header)
MEASURED  down 2026_09_20_120000_create_signature_relocations_table — 9 ms
MEASURED  down 2026_09_16_120000_add_digital_signature_disk_to_billing_settings — 10 ms
MEASURED  down 2026_09_15_140000_add_invoice_image_disk_to_stock_purchases — 10 ms
MEASURED  down 2026_09_15_120000_add_invoice_file_disk_to_karigar_invoices — 9 ms
PASS      expand down: columns and relocation ledger gone
== G. Re-apply — and what the Phase-1 rollback cost ==
MEASURED  private-disk labels — before expand rollback: 11; after re-apply: 0
PASS      Phase-1 rollback is ONE-WAY: every private file is relabelled public on re-apply, and the relocation ledger is empty — relocations: 0
PASS      fully re-applied: nothing pending
PASS      no business row lost across every up and down — {"karigar_invoices":1210,"stock_purchases":1211,"idempotency_keys":3002,"shop_billing_settings":3}
(jewelflow_testing reset with migrate:fresh)
REHEARSAL: all checks passed
```

Measured when each was added (commit in brackets):

```
BackupSourceExclusionTest + BackupConfigurationTest + BackupScheduleTest
  -> 19 passed (70 assertions)                                     [3f1bd85]
  RED against the old config: AccessDeniedException; .env.backup and logs selected
  real `backup:run --only-files`: 2604 entries, top level = the allowlist, .env the only env file
MobileIdempotencyReplayFidelityTest -> 8 passed (25 assertions)    [b79f182]
  RED: replay ETag null; next write 428
  mutation, headers folded into the single update -> degradation test fails 409
StockPurchaseLineOwnershipTest -> 7 passed (46 assertions)         [32ca54f]
IdempotencyClaimReconciliationTest -> 6 passed (56 assertions)     [8d5a066]
  clean RED with the command removed: 6 failed on CommandNotFoundException
  after 25 fixture assertions passed
SettingsSignaturePreviewTest -> 5 passed (17 assertions)           [51ca987]
  RED: preview src http://localhost/storage/signatures/1/<ulid>.webp
  mutation, preview borrows the print gate -> settings-only test 403
  the four signature suites together -> 51 passed (199 assertions)
PurchaseInvoiceImageRelocationTest + KarigarInvoiceRelocationTest
  + PurchaseInvoiceAttachmentTest -> 29 passed (102 assertions)    [9d48331]
PostgreSQL 16.15, temp table (S3-12 claims)
  timestamp(0) -> timestamp(6): relfilenode unchanged (no rewrite)
  '2026-09-23 23:59:59.6'::timestamp(0) = 2026-09-24 00:00:00
```

**Evidence lost and re-run.** The first full-suite run and rehearsal at this
SHA wrote their output to a session scratch directory. That directory was wiped
by a session restart before the results were read. Both were re-run at the
same SHA, and the figures above are from the re-runs. No figure here was carried
over from a run whose output no longer exists.

### XR round — execution SHA `751acc4ca3b84f40a7ab619cd25462f4aa2bd787`

Run 2026-09-24T02:30:19+05:30 to 02:35:20+05:30, in this order, with
`git status` showing only `docs/` files modified. No test reads them.

```
# php tests/Concurrency/claim_release_race.php            (XR-02)
writer blocked inside its business transaction: yes; claim staked: id 1 (status 0)
cash rows visible to the operator: 0; reconciler exit 1; claim still present: yes
  reconciler said: Refused: the original request still holds this claim and may still commit. Nothing was changed.
writer finished: {"status":201,"replay":null}
same-key retry: 201 (replay: true); cash rows for this shop: 1
RESULT: SAFE — release refused while the writer could still commit; one cash row

# php tests/Concurrency/etag_race.php                      (XR-05)
workers waiting on the row: 2
responses: [{"status":200,"code":null,"price":"2500"},{"status":412,"code":"precondition_failed","price":"3100"}]
stored price: 2500.00
RESULT: SAFE — one edit won, the other was refused as stale

# php tests/Rehearsal/migration_rollback_rehearsal.php
  -> REHEARSAL: all checks passed — 26 PASS, 17 MEASURED, including
     "S3-09e: replay carries the original ETag" and
     "headers column dropped under new code: claim still resolves ... claim status 200"

# FULL — php artisan test
  -> 3355 passed, 7 skipped, 0 failed (16357 assertions), 290.97 s
     skipped: the same 7 as at 9d48331 — 6 data-dependent ConstitutionalInvariantsTest
     skips and 1 browser-only contract
```

**Reused, not re-run: the code they exercise has not changed since.**
`git diff --stat cb5f983 751acc4 -- app/Console tests/Concurrency/relocation_race.php`
and `git diff --stat 48981ff 751acc4 -- database tests/Rehearsal` are both
empty.

```
# php tests/Concurrency/relocation_race.php — 3 runs each   (XR-03)
old movers:  rows wrong 4 / 40 / 6; e.g. karigar-invoices/51/race-6.pdf: disk=local private=MISSING
             -> RESULT: UNSAFE, 3 of 3
at cb5f983:  rows wrong 0; temporaries left 0; conflicts/failed copies reported 0
             -> RESULT: SAFE, 3 of 3

# php tests/Rehearsal/contract_migration_locks.php         (XR-04, 3,000,000 karigar rows)
old migration:  A still holds karigar_invoices:AccessExclusiveLock, shop_billing_settings:AccessExclusiveLock;
                both read=BLOCKED write=BLOCKED. B AccessExclusiveLock during VALIDATE.
                C killed run left all three absent (one transaction); re-run completed
                -> RESULT: 3 check(s) FAILED
at 48981ff:     A holds only stock_purchases:RowExclusiveLock, ShareRowExclusiveLock; finished tables read=ok write=ok
                B karigar_invoices:ShareUpdateExclusiveLock only; read=ok write=ok; VALIDATE still running after the probe: yes
                C after kill: shop_billing_settings=validated, karigar_invoices=validated, stock_purchases=absent;
                  re-run exit 0, all three validated, recorded
                -> RESULT: all checks passed
```

Before-repair and mutation runs, recorded when each repair was made:

```
claim_release_race, before 9d673a7:   reconciler exit 0; claim deleted; retry 201 (replay: no); cash rows 2 -> UNSAFE
claim_release_race, writer lock removed: cash rows 2 -> UNSAFE
etag_race, before 69b4439:            workers waiting 2; [200, 200]; stored 2500.00 -> UNSAFE
etag_race, locked re-check removed:   [200, 200] -> UNSAFE
```

**Harness defects, recorded because they looked like results.** The first
before-run of `claim_release_race.php` read "writer blocked: NO", and the first
of `etag_race.php` read "workers waiting on the row: 0". Both probes used
`pg_stat_activity`, which a session sees as one snapshot per transaction, and
the probing session was inside one. Both now read `pg_locks`, which is live;
only runs made after that change are counted above. The first lock harness ran
on 1.5M rows and counted a probe that completed only after the migration
committed; it now opens its probe connection in advance, uses 3M rows, and
counts a probe only if VALIDATE was still running when it finished.

### Second-review round — execution SHA `9a3fec8d5c82e8e339cce3a97fe921f71cd1ed02`

Run 2026-09-24T07:43:22+05:30 to 07:48:38+05:30, in this order, with a clean
tree at that SHA.

```
# php tests/Concurrency/claim_release_race.php               (XR-02)
== 1. intact connection ==
cash rows visible to the operator: 0; reconciler exit 1; claim still present: yes
same-key retry: 201 (replay: true); cash rows for this shop: 1
RESULT: SAFE — release refused while the writer could still commit; one cash row
== 2. the lock-holding connection is lost after the claim is staked ==
writer paused after staking: yes, backend A = 124546; claim id 2 (status 0); advisory lock held by backend A: yes
backend A terminated; lock still held by anyone: no
reconciler exit 0; claim still present: NO
writer resumed: {"status":500,"replay":null}; cash rows it left: 0
same-key retry: 201 (replay: no); cash rows for this shop: 1
RESULT: SAFE — the writer could not continue without its lock; one cash row

# php tests/Concurrency/etag_image_race.php                  (XR-05)
items     GET   window: shown "3100.00"; value when the client's edit arrived 3100.00; edit -> 200 -> SAFE
items     PATCH window: response 200 shown "2600.00"; value when the next edit arrived 3200.00; edit -> 412 -> SAFE
customers GET   window: shown "competing"; value when the client's edit arrived competing; edit -> 200 -> SAFE
customers PATCH window: response 200 shown "client-edit-2"; value when the next edit arrived competing-2; edit -> 412 -> SAFE

# php tests/Concurrency/etag_race.php                        (XR-05, two writers, retained)
stored price: 3100.00 -> RESULT: SAFE — one edit won, the other was refused as stale

# FULL — php artisan test
  -> 3359 passed, 7 skipped, 0 failed (16381 assertions), 310.27 s
     skipped: the same 7 pre-existing skips as at 751acc4
```

Measured on the working tree before the commits, which differ from `9a3fec8`
only in comments and documentation:

```
tests/Feature/Security + tests/Feature/Mobile          -> 346 passed (1434 assertions)
php tests/Rehearsal/migration_rollback_rehearsal.php   -> all checks passed (26 PASS), including
   "headers column dropped under new code: claim still resolves ... claim status 200"   (XR-07, real 42703)
php tests/Concurrency/relocation_race.php, 3 runs     -> karigar SAFE 3/3; signatures (10 shops, two movers each) SAFE 3/3,
   one ledger row per shop, 0 conflicts, 0 temporaries                                   (XR-03)
RelocationNonDestructiveTest                           -> 10 passed (50 assertions)
MobileIdempotencyReplayFidelityTest                    -> 10 passed (33 assertions)
ETag + replay + replay-authorization suites            -> 27 passed (90 assertions)
```

Before the repairs, on the code of `9aaf1af`:

```
claim_release_race scenario 2:  writer resumed {"status":201}; cash rows it left: 1;
                                same-key retry 201 (replay: no); cash rows 2 -> UNSAFE
etag_image_race:                items GET shown "1000.00", overwrote 3100.00 -> 200;
                                items PATCH shown "2600.00", overwrote 3200.00 -> 200;
                                customers GET shown "v0", overwrote "competing" -> 200;
                                customers PATCH shown "client-edit-2", overwrote "competing-2" -> 200
                                -> RESULT: 4 UNSAFE window(s)
Replay fidelity, new XR-07 test: "Failed asserting that 200 is identical to 0" (claim completed without headers)
RelocationNonDestructiveTest, 3 new tests: exit 0 where 1 expected (x2); ledger row rewritten / re-dated
```

No extra mutation run: in each case the before and after differ by the repair
alone, so attribution needed none.

### Tenant-isolation round — execution SHA `1089ba0afd4891e5eb54a19350a6fb4b51aa28de`

Run 2026-09-24T12:06:40+05:30 to 12:12:50+05:30, in this order, alone on
`jewelflow_testing`, with only this document modified.

```
# php tests/Concurrency/queue_worker_tenant_reuse.php   (one real queue:work process)
worker exit 0; jobs left on the queue: 0
context seen by each probe: {"before-A":null,"after-A":null,"after-failing-A":null,"after-B":null,"after-leaky":9}
export A:  status failed; names AlphaOnlyXR: yes; names BravoOnlyXR: no
export A!: status failed (a job that threw inside shop A's context)
export B:  status failed; names BravoOnlyXR: yes; names AlphaOnlyXR: no
mismatched payload (shop A, B's export row): B's row status queued, file none
final status of exports A and B: failed, failed — S3-17: SQLSTATE[42P01]: Undefined table: 7 ERROR:  relation "notifications" does not ex
runtime property — after a job that set context and did not restore it, the next job saw: 9 (NOT cleared by the worker)
RESULT: SAFE — sequential shops in one worker, context restored after success and failure, each export only its own shop

# php tests/Concurrency/production_binding_path.php     (APP_RUNNING_IN_CONSOLE=false, no TenantContext)
mobile item, own             GET /api/mobile/v1/items/3                   -> 200 ok
mobile item, foreign         GET /api/mobile/v1/items/4                   -> 404 ok
mobile customer, own         GET /api/mobile/v1/customers/5               -> 200 ok
mobile customer, foreign     GET /api/mobile/v1/customers/6               -> 404 ok
web customer, own            GET /customers/5                             -> 200 ok
web customer, foreign        GET /customers/6                             -> 404 ok
mobile item PATCH, foreign   PATCH /api/mobile/v1/items/4                   -> 404 ok
shop B's item price before/after the foreign PATCH: 5000.00 / 5000.00
RESULT: SAFE — production binding path: own records 200, foreign refused, nothing changed

# php tests/Inventory/tenant_query_sweep.php            (expressions the scope does not govern)
  787 expressions: raw 233, scope-removed 150, unscoped-model 404
  tenant-owned (or unknown table) with no shop_id in the expression: 228
    tenant-facing 139 — each read; Admin/ 30 and Console/ 59 — not read individually

# php tests/Inventory/request_id_sweep.php              (id-bearing validation keys)
  168 keys: exists+shop 63 (61 before S3-14/S3-15), exists+parent 3, exists-only 7, shape-only 95

# FULL — php artisan test
  -> 3370 passed, 7 skipped, 0 failed (16432 assertions), 356.01 s
     skipped: the same 7 pre-existing skips
```

Before-repair runs, recorded when each defect was reproduced:

```
TenantForeignReferenceTest (S3-14): "Expected response status code [422] but received 200."
  consequence probe: the sale wrote invoice_payments id 1 (shop 2, Rs 10,300) naming shop 1's account;
  shop 1 deleting its own account -> QueryException "Append-only: invoice_payments rows cannot be updated (id=1)"
TenantForeignReferenceTest (S3-15): no job_order_id error; the invoice was created naming shop B's job order
queue_worker_tenant_reuse.php (S3-18, second run): exports of shops 5 and 6 both at
  reporting-exports/customers-20260924-082716.csv; shop 5's file named only shop 6's customer
QueuedExportIsolationTest (S3-18, clock frozen): shop A's signed download (200) had one data row —
  "BravoOnlyXR Customer", shop B's
TenantBackgroundExecutionTest (S3-16): after loyalty:expire, shop A's 100 expired points remained;
  with runFor added (reverted): "Append-only: loyalty_transactions rows cannot be updated (id=1)"
BackupWorkbookIsolationTest, one mutation (CustomersDataset list query -> withoutTenant()):
  fails on "nothing of shop B's is in shop A's backup"; reverted, tree clean
```

**A run discarded, recorded because it would otherwise be invisible.** The
first final run of this round (at `f3b1eba`) was stopped: I started a PHPUnit
run while it was executing, and both use `jewelflow_testing` —
`RefreshDatabase` runs `migrate:fresh`. Stopping `php artisan test` left its
PHPUnit child (`php8.4 … phpunit`) running; it was found by its open database
session and stopped too, and one orphaned test session was checked before
anything else ran. Its harness and sweep outputs had finished before the
overlap; everything above is from the clean re-run at `1089ba0`.

Harness artefacts found and fixed along the way, none of them results: a
probe that read `null` through `??` counted as missing; export status was at
first judged on the final status, which S3-17 always makes `failed`; the web
guard needed the request bound before a user was set on it; a 4,000-character
capture cut off the page body the check looked in.

## 9. Commits, diff, working tree

**Measured at `1089ba0`, not at HEAD — deliberately.** A diffstat recorded inside
a tracked file changes the diffstat, so "the figure at HEAD" has no fixed point,
and chasing it is exactly how the earlier revisions of this line came to be
wrong. Pinning it to a named commit makes it rerunnable:

```
$ git diff --shortstat 018b3d8..1089ba0
 119 files changed, 25989 insertions(+), 432 deletions(-)

$ git log --oneline 018b3d8..1089ba0 | wc -l
100

$ git diff --shortstat d37879b..1089ba0      # this round, since the reviewed candidate
 16 files changed, 1210 insertions(+), 27 deletions(-)
```

`1089ba0` is the last commit on the branch that touches anything outside
`docs/`; application code last changed at `11e91c3`. Re-pinned from `9a3fec8`
(108 files / +24,649 / −429, 91 commits), superseded by `c4730e2` (review
acceptance and two corrections), the fixes `9f633e1` and `11e91c3`, and the
evidence commits `7c242d7`, `4a4011a`, `7a9ffb0`, `f3b1eba`, `1089ba0`.
Before that, re-pinned from `751acc4` (105 files / +23,498 / −427, 84 commits),
superseded by three documentation commits (`96fb57d`, `603205f`, `9aaf1af`)
and the four second-review commits `5e1bf88`, `412c8ea`, `a3de74d`, `9a3fec8`.
Before that, re-pinned from `9d48331` (91 files / +21,371 / −398, 76 commits),
superseded by `7b1d709` and the seven XR commits `bf37898`, `9d673a7`,
`cb5f983`, `48981ff`, `69b4439`, `d31ab16`, `751acc4`. Earlier
re-pinned from `06ec0e0` (76 files / +19,303 / −225, 67 commits),
superseded by this round: `3f1bd85` backup A, `b79f182` S3-09e, `32ca54f`
stock-purchase contract, `8d5a066` claim procedure, `01f37a6` rehearsal,
`2d0b5f5` backup C, `51ca987` S3-04b, `9d48331` S3-03 relocation. Earlier pin,
`2dd0875`: 58 files / +11,868 / −100, 44 commits.

**Re-pinned from `1b6aeb4`, which this line named until `2dd0875` landed.** The
previous pin read `52 files / +9,318 / −95` over 30 commits and was correct when
written; the S3-07b repair made it stale rather than wrong. Superseded, not
corrected — the distinction the paragraph below insists on.

Earlier pins, superseded by later work rather than corrected: `e12c6c5` at
49 files / +8,641 / −69, and `721c06d` at 51 files / +8,920 / −95. Those two are
supersessions. The three revisions described below are different — they were
wrong when written.

**Three revisions of this one line were wrong, recorded rather than quietly
overwritten.** The first claimed "22 commits, 46 files, +7,813 / −54", carried
over from an earlier point in the branch. The second claimed "+8,642 / −77",
which I got by adding the size of my own edit to an earlier reading — arithmetic,
not evidence, and wrong by one insertion and eight deletions. The third pasted
real output but labelled it "at HEAD", which the act of pasting falsified. Any
number in this document not traceable to a named command at a named commit
should be treated as unverified.

This session added:

```
7fc27cf  Capture a render snapshot at finalization (S3-04)
0ae6b15  Drive the quick-bill signature regression through real routes (S3-04)
1eef5b2  Prove relocation preserves the signature on the printed page (S3-04)
c211264  Record the audit handoff: separated statuses and pending containment
b216b80  Print a finalized bill's tax as it was issued, not as today (S3-05)
721c06d  Release the catalog tenant context even when the handler throws (S3-06)
```

(plus, earlier in the same session: `a542745` migration split, `ad9babe` runbook
de-duplication, `2f26386` S3-05 characterization tests.)

Later in the branch, and the one that matters for the S3-07b claim:

```
eadabd9  Record the data-dependent skips hiding two trigger checks
25355ff  Add a regenerable review-packet builder
941ed74  Re-measure both pinned suites at the candidate SHA
9a9cb92  Pin one export SHA, add a scanned + zipped deliverable
2dd0875  fix(S3-07b): make mobile invoice payment retry durably idempotent
```

`2dd0875` is the **only** commit in that list that changes application
behaviour — the other four are evidence tooling and documentation. Stated
explicitly because the two must not be conflated: *documentation completion is
not completion of the underlying security repair*, and the repair is `2dd0875`
alone.

**Working tree is clean. No dirty or untracked files in this repository.**
Untracked-but-ignored artifacts exist and are intentional: `node_modules`
(a symlink to the primary checkout) and `public/build` (Vite output), both
covered by `.gitignore`.

---

## 10. Next, while production approval is pending

Local work that remains, none of it blocking the conditions in §11:

1. S3-05 contact line — a product decision on whether a reprint shows the
   seller's phone, WhatsApp and email as issued or as today (D-17).
2. A mobile message for `idempotency_outcome_reconciled`. It currently falls
   into the generic 409 alert.
3. Tenant isolation: every §7e category is inventoried. Left: clearing tenant
   context in `Queue::before` (recommended), reading the admin and console
   expressions individually, and S3-16/S3-17 once decided.
4. Candidate-public asset classification: `products`, `shop-logos`,
   `catalog-heroes`, and the two upload writers (§7).
5. S3-13, S3-06b and S3-06c, once their policy questions are answered.

---

## 11. Release readiness

**Not ready.** Independent reviews: `7b1d709` (seven findings, §0b),
`9aaf1af` (XR-01, XR-04, XR-06 accepted; XR-02, XR-03, XR-05, XR-07 partial —
answered in §0c), `d37879b` (those four accepted) and `c9d30b0` (S3-14/S3-15
validation repairs and S3-18's protection for new exports accepted; answered
further in §0d, locally only). Answering a finding is not a reviewer accepting
the answer. **Nothing here establishes that the application as a whole is
secure:** these results cover the named findings and routes; the
tenant-isolation inventory (§7e, §0d Part 2) covers the forms and paths it
names, lists 157 admin and console rows it has not read, and does not make
isolation complete. Every production statement here
dates from the last observation of the server (2026-09-20T18:36:08+00:00), not
from today.

What the branch supports, locally only: the repairs listed in §0 behave as their
tests say on `jewelflow_testing`, the full suite result in §8, and a populated
migration rollback rehearsal (§6a).

### Conditions — each with what closes it

"Closes when" names evidence, not approval alone. Where an approval is needed,
it is named as an approval **and** as the evidence that follows it.

| # | Condition | Closes when | Findings it carries |
|---|---|---|---|
| R1 | Independent source review | XR-01…07 **accepted** at source level (XR-01/04/06 at `9aaf1af`, XR-02/03/05/07 at `d37879b`); S3-14/S3-15 and S3-18 for new exports accepted at `c9d30b0`; the legacy-export refusal, `Looping` cleanup, child-process guard and mobile message accepted at `592d864`. Open for this round (§0e): closes when the reviewer re-reviews the packet regenerated with the delta from `592d864` | all |
| R2 | Drift checks, one per phase | D0 before Phase 1, D1 before Phase 2, D2 before Phase 3, D3 after it, each recorded (`signature-migration-release-order.md` § Drift checks). **Corrected:** the single check "`HEAD` = `018b3d8` before any step" was wrong for Phase 3. On drift, stop and re-derive | all deploy steps |
| R3 | Migration order | the eight Phase 1 migrations applied, one file per command; the `notifications` table only in Phase 2b, after the code is live everywhere and D2 shows no baseline export job ran after the switch (runbook D2/D2b); code deployed to every node, with the D1 connection check passed on the **effective** configuration — `DB_URL`, the pooler switch and any cached config resolved, pooler mode from the pooler's own configuration (XR-02, corrected in §0c); contract applied last; D3 shows all three constraints validated. Rehearsed in §6a; contract lock boundaries measured (XR-04) | S3-04, S3-07b, S3-09b, S3-09e, S3-02/S3-03 disk columns |
| R4 | Karigar attachments off the public path | after R3: `karigar-invoices:relocate-attachments` dry run reviewed → approved `--execute` → `--verify` clean → separately approved `--purge-originals`. The manifest is kept. Until the purge, the public copy stays reachable at its URL | **S3-02** |
| R5 | Purchase images off the public path | the same sequence with `purchases:relocate-invoice-images` (`9d48331`). The production count must be established first — it is not known here | **S3-03** |
| R6 | Signatures off the public path | `signatures:relocate`, same gating (§7). **Closes for current signatures only.** Superseded versions stay public: count them with `--orphans`, then either approve Option SIG-ORIGIN (§7, after Phase 2; PARTIAL) or record the residual as accepted | S3-04 |
| R7 | KYC exposure | an approved package executed and its §6 verification output recorded. For the edge layer that means a unique probe path with **0 origin log lines**, next to a positive control; a 403 alone does not show the edge rule works (corrected). ORIGIN-ONLY leaves the edge-cache residual open until §3 and §5 run, and stays PARTIAL | S3-01, S3-01c |
| R8 | Device verification | the §5 checks run on Android and iOS, with results recorded, for the inline signature and for S3-09c handling | S3-04, S3-09c |
| R9 | Backup A in production | after deploy: `backup:scope-check` clean as `www-data`, and one `backup:run` whose archive listing shows the allowlist | backup A |
| R10 | Tenant-isolation findings in production | read-only checks recorded before deploy (§0d Part 1): `tenant:audit-foreign-references` (every stored cross-shop reference, S3-14 and S3-15 included); `reporting:audit-export-files` (cross-shop candidates by physical file; exit 2 = some storage unresolved, not clean); `to_regclass('notifications')` and the queue driver (the configuration now — whether shared files can be served now, not whether they were); `report_exports` history and `notifications` rows (what was recorded); the access log for the download route (what was served). Each answers its own question; none alone shows a disclosure. Any hit is a data question with its own decision — nothing is corrected by hand | S3-14, S3-15, S3-17, S3-18 |

### Open, and deliberately NOT conditions of this release

Each predates the branch and is unchanged by it, or is a question of policy.
Each stays OPEN, with its smallest repair in §0a. Listing them here is not
acceptance.

* **S3-05 contact line** — whether a reprint shows the seller's phone,
  WhatsApp and email as issued or as today is a product decision (D-17). The
  rest of S3-05 ships with this release; this part is not claimed.
* **S3-13** — the billing policy on edits is undecided.
* **S3-16 activation** — the expiry mechanism ships inactive; the activation
  date, the backlog, the allocation order and drifted balances are a product
  and accounting decision (§0d Part 5). Shipping changes no balance.
* **S3-06b** — publication consent is per shop, not per item.
* **S3-06c** — item photos outlive the shopfront toggle.
  A product decision on whether "disable" must unpublish them. The branch
  does not change item-image exposure.
* **Unresolved S3-09 claims** — retaining them is safe. The procedure
  (`8d5a066`) is needed only to clear one, and each use needs approval.
* **Backup C** — a procedure change for the operator's deploy runbook. It is
  independent of this code release.
* **S3-16** (loyalty expiry) and **S3-17** (queued-export notification) —
  each needs a product or accounting decision before it can be repaired.
* **Tenant isolation** — every §7e category is now inventoried; what remains is
  named there (Octane, transaction pooling, production queue driver, admin and
  console expressions not read individually). The worker does not clear tenant
  context between jobs: safe only because every job uses `runFor`; clearing it
  in `Queue::before` is recommended, not implemented.
* **Device checks** (R8 above) and the **mobile message** for
  `idempotency_outcome_reconciled` — still not run and not built.
* **KYC containment** (R7) stays a separate package, unexecuted, pending its
  own approval; ORIGIN-ONLY is PARTIAL.
