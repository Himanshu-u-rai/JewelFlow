# Multi-tenant security audit — reviewable handoff

Branch `security/multi-tenant-audit`, worktree
`/home/himanshu/Desktop/jewelflow-worktrees/security-multi-tenant-audit`.

**Nothing in this branch has been pushed, deployed, or applied to any
environment other than the local `jewelflow_testing` database.** Every number
below is local evidence. Production exposure status is tracked separately and is
not improved by any of it.

Reported deployed baseline: `018b3d810e37d534f498033ab582ee41f3197c27`.
Verified as a local git object on 2026-09-21T00:37+05:30. That is a check of the
SHA I was **given**, not an observation of what is running on the servers.
Recheck for drift against the deployed tree before executing any approved step.

Mobile repository `/home/himanshu/Desktop/jewelflowMobileApp` at `d8a0781`
("chore(brand): close remaining JewelFlows branding gaps"). **I made no change to
that repository.** It carries one pre-existing dirty file, `src/utils/storage.ts`
(last written 2026-07-09, two months before this audit opened), plus untracked
scratch files that are not mine.

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
| S3-03 purchase attachment public | OPEN | Authenticated route committed | **OPEN** |
| S3-04 signature public + mutable | OPEN | Option B implemented; immutability proven end to end | **OPEN** |
| S3-05 finalized invoice reprints with today's settings | **FIXED for every ASSERTED field** — tax (`igst_mode`, HSN), tax identity (`show_gstin`, `gst_number`), terms, and all payment instructions now come from the bill's own snapshot. RENDERING fields (theme, font, paper, subtitle, tagline, other `show_*`) stay live **on purpose**; D-12 pins that bound. Quick bills carry less and the gap is named (§7d) | Fix `b216b80` (tax half) + this commit (asserted half); 16 tests, 89 assertions; 2 mutations run | **OPEN** — local only, not deployed |
| S3-06 catalog tenant context survives a throw | **CLOSED as a code defect** — no cross-tenant read demonstrated | Fix committed (`721c06d`), 5 tests | **OPEN — needs the deploy** |
| S3-06b enabling a shopfront publishes every in-stock item | OPEN — product-consent gap, not a tenant break | Characterized (C-03), deliberately not repaired | N/A — feature decision, not an audit repair |
| S3-06c published item images outlive the shopfront toggle | OPEN | None — recorded limitation | **OPEN** |
| S3-07 mobile payment cache-hit path was unauthorized | **CLOSED as a code defect** — no exploit against shipped code; the binding blocked it | Guard committed (`0296431`), 9 tests | **OPEN — needs the deploy** |
| S3-07b payment retry integrity (cache/commit not coordinated) | **REPAIRED LOCALLY** — both defects closed; two more found by real concurrency and fixed; **rollback to the deployed baseline is measured UNSAFE** | Characterized `893a49b` (3 tests) → repaired `2dd0875` → claim scope `0cdd794` → claim staked before validation + P-14 `7d20e08` → rollback evidence, P-06 relabel, P-15 `b1a52f0`. 15 tests, 250 in band | **OPEN — needs the deploy, under the constraints in `payment-idempotency-rollback-constraints.md`** |
| S3-08 static memoization across a long-lived worker | **CLOSED — examined, not a tenant break** | None needed; one inaccurate docblock noted | N/A |
| S3-09 `EnsureIdempotency` records completion AFTER the controller, outside any transaction | **REPAIR IMPLEMENTED; VERIFICATION INCOMPLETE.** Supported conclusion is narrow: pre-staking blocks same-key automatic re-execution *while the claim is retained*. It does NOT by itself establish atomic business completion, recoverable successful replay, or that key retention survives pruning | Characterized `8cddbc3` → repaired `b8673db`. 17 tests / 89 assertions; 9 contract tests still green; 273 passed (1028 assertions) across `Feature/Security` + `Feature/Mobile`, no regressions. Concurrency is now **REAL multi-process** (§7c-3): pre-repair blob `6a8c4cc` produced 4×201 / 4 cash rows / sum 10000 under one key; repaired produces 1×201 + 3×409 / 1 cash row. Recoverable successful replay is now measured too | **OPEN. Closed sub-items: 4xx-release safety (§7c-2), real concurrency (§7c-3), mobile consumer handling (§7c-4). Still open: retained-claim disposal (§7c-1 — the pruning *code* defect is fixed in `e68eb31`; what remains open is an operator decision, since a retained unresolved claim has no supported clearing path and growth is slow but unbounded), 12 routes now REVIEWED (§7c-6) — no write-then-4xx on any of the 16, so the release-on-4xx policy is supported rather than assumed; three routes (`drawer-check`, `job-orders`, `uploads/intent`) have no duplicate guard and inherit S3-09c; two NEW defects found and characterized but NOT repaired (S3-09e, S3-12). New limit found — payload-conflict detection is sequential-path only; a concurrent different-payload loser gets `idempotency_in_flight`, not `idempotency_key_conflict`** |

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
| S3-09e | Replayed responses drop every header, wedging the two `PATCH` routes whose contract needs `ETag`. **Predates S3-09** — pre-repair blob `6a8c4cc` behaves identically | *(new — ID confirmed unused before assignment)* | §7c-6, CHARACTERIZED, NOT REPAIRED |
| S3-11 | `ReturnService::approveReturn` cancels the pending header, then can fail | *(new — ID confirmed unused before assignment)* | §7c-2, FIXED |
| S3-12 | `If-Match` validator is one-second granular (`updated_at` is `timestamp(0)`), so a concurrent write is silently lost | *(new — ID confirmed unused before assignment)* | §7c-6, CHARACTERIZED, NOT REPAIRED |
| S3-13 | Editing a quick bill re-captures `shop_snapshot` on the shared save path, so an edit to an unrelated field re-states the supply type on a bill that keeps its number. **Presentation only** — bill number and every stored figure verified unchanged | *(new — ID confirmed unused before assignment)* | §7d-1, CHARACTERIZED, NOT REPAIRED |

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
| Mobile "Signature unavailable" on-screen banner | **NOT DONE** | Both screens currently only `Alert.alert('Print Failed', …)`. The printed document *does* carry the marker (it is in the HTML); the on-screen banner is separate and unbuilt |

---

## 6. Migrations — dependency order

Full reasoning: `docs/runbooks/signature-migration-release-order.md`. **Status:
PROPOSED. Nothing executed anywhere but `jewelflow_testing`.**

| Phase | Migrations | Safe while baseline serves? |
|---|---|---|
| 1 EXPAND | `2026_09_15_120000` karigar disk, `2026_09_15_140000` purchase disk, `2026_09_16_120000` signature disk, `2026_09_20_120000` `signature_relocations` | Yes — nullable columns and a new table |
| 2 APPLICATION | deploy the new code to **every** serving node | — |
| 3 CONTRACT | `2026_09_20_130000` all three both-or-neither CHECKs | **No.** Must not run before Phase 2 completes |

The correction this split exists for: I called the original three migrations
"additive" and treated that as safe-while-serving. The column and backfill are
additive. **The constraint is not** — it forbids a row with a path and no disk,
and that is exactly what baseline `018b3d8` writes on every upload
(`SettingsController:526-527`, `StockPurchaseController:140,354`,
`KarigarInvoiceService:49,114`). Demonstrated by `DiskColumnReleaseOrderTest`
T-01/T-03/T-05; T-02 is the expand-only positive control.

Phase 3 reconciles **immediately before** enforcing, in both directions, because
the Phase 1 backfill ran before the window it authorises. It adds each constraint
`NOT VALID` then `VALIDATE`s it — `NOT VALID` takes `ACCESS EXCLUSIVE` only
briefly and still enforces on every subsequent INSERT/UPDATE; `VALIDATE` scans
under `SHARE UPDATE EXCLUSIVE`, blocking neither reads nor writes. T-06 asserts
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
code put on the private disk, and of which have been relocated. Treat Phase 1
rollback as **one-way** once any private upload or relocation has occurred;
revert the code and leave the columns.

---

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
* Superseded signature versions are named by no settings row — only by snapshots.
  `--orphans` reports them; the default pass does not move them, because moving a
  file that no row names would strip the only pointer to it.
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

## 7d. S3-05 second half — the asserted/rendering split — FIXED

The first half (`b216b80`) pinned `igst_mode` and the HSN map. This completes
the finding for every remaining field that **the document asserts about the
transaction**, and deliberately closes it no further than that.

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

## 7e. Cross-shop queries, relationships, jobs and exports — a NEGATIVE result, and the one gap it exposed

**Outcome first: no cross-shop defect was found in this sweep.** A negative
result is worth recording only if the method that produced it is stated, so
that a later reader can judge what it did and did not cover. What the sweep
DID produce is a coverage gap — the most load-bearing line in the tenancy
design was bound by no test — which is now closed and mutation-verified.

### Why the surface is much smaller than it looks

`BelongsToShop::bootBelongsToShop` **fails closed**. When no shop id resolves
it appends `whereRaw('1 = 0')` rather than leaving the query unfiltered. That
single choice inverts the usual multi-tenant audit:

* The classic risk — *forgetting* a `where('shop_id', …)` — cannot leak. A
  forgotten filter on a `BelongsToShop` model in a context-free path returns
  **zero rows**, not everyone's rows.
* The real surface is therefore the places that **drop the scope on purpose**:
  `withoutTenant()`.

So the audit collapses from "every query in the codebase" to a countable list.

### What was scanned, and how it narrowed

| Step | Population | Figure |
|------|-----------|--------|
| `withoutTenant()` call sites in `app/` | all | **144 across 58 files** |
| …excluding `app/Console/` (operator-run, shop argument explicit) | — | — |
| …with **no** `shop_id` / `shopId` / `whereKey` / `find` / `where('id'…)` within 12 lines | candidates | **18** |
| …surviving individual inspection as a genuine question | — | **0** |

The 18 resolve into five explained groups, each read in full:

1. **Global-uniqueness existence checks** — `CatalogShareService:354,363`
   (`share_token`, catalog `token`). Cross-shop by necessity: a token must be
   unique across the estate. Returns a boolean, never a row.
2. **Deliberately public, token-keyed** — `PublicCatalogController:16,32`.
   Already covered by `PublicCatalogExposureTest`.
3. **Platform-admin surfaces** — `Admin/DashboardController`,
   `Admin/UserManagementController`. Cross-shop is the feature.
4. **Counts over a foreign key whose parent is already tenant-resolved** —
   `ProductController:201`, `CategoryController:128,129`,
   `PaymentMethodController:54,56`. The id being counted against belongs to
   this shop, so the FK cannot reach another's rows.
5. **Constrained by a key the regex did not see** — `ShopPricingService:291,294`
   (`$key` is built at line 272 and **contains `shop_id`**),
   `AuthController:164` (keyed on the caller's own `token_id`),
   `InvoiceSignatureRenderer:112` (keyed on `invoice_id`, plus its own
   authorization gate).

### Raw SQL, which the global scope cannot reach

Eloquent global scopes do not apply to `DB::table()`. 227 raw call sites exist
in `app/`. Restricted to tenant-facing code (excluding `Admin/` and
`Console/`) and filtered for those with no `shop_id`/`shopId` within 18 lines:
**31 hits, 0 defects.** They are platform tables with no tenant dimension
(`platform_counters`, `failed_jobs`, `razorpay_webhooks`, `sessions`, `roles`,
`personal_access_tokens`), or updates keyed on the primary key of a model that
was already tenant-resolved (`items`, `metal_lots`, `return_orders`,
`kyc_documents`).

`AuditService:42` was read closely because `DB::table('users')->whereIn('id', …)`
looks unscoped: the ids come from this shop's own invoices and credit notes, so
the names returned are this shop's operators.

### The write side

The `creating` hook fills `shop_id` from context **only when the attribute is
empty**, so a client-supplied `shop_id` would survive. Two greps close that:

* `create($request->all())` / `update($request->all())` / `fill($request->all())`
  in `app/Http/Controllers/` and `app/Services/` — **0 hits** (the 8 matches for
  `->all())` are all Collection `->all()`, not Request).
* `'shop_id' => '…'` inside a `validate()` rule set — **0 hits**, so no route
  accepts a client-supplied shop id in the first place.

### Jobs

9 `ShouldQueue` classes. `GenerateQueuedExportJob` — the one at the
intersection of "job" and "export" — wraps its entire body in
`TenantContext::runFor((int) $p['shop_id'], …)`. Because the trait fails
closed, a job that *omitted* that wrapper would read nothing rather than
everything.

### Exports

`ExportDownloadController` requires signed URL **and** session auth **and**
`$export->shop_id === $user->shop_id` **and** the originating report's
view/export permission **and** `reports.export_sensitive` when the file carries
sensitive columns. Already behaviourally covered by
`ExportDownloadAuthzTest` and `UrlKnowledgeAuthorizationTest`; not re-tested
here.

### The gap this exposed, and its repair

Every conclusion above rests on the fail-closed branch. **No test bound it.**
`ConstitutionalInvariantsTest` names `BelongsToShop` only in comments. A
future reader who deletes `whereRaw('1 = 0')` as apparent dead weight converts
every context-free query in the application into a cross-tenant read, and the
application keeps working.

`tests/Feature/Security/TenantScopeFailClosedTest.php` — **4 passed, 7
assertions.** Two tenants, one item each; a precondition proves both rows exist
so that a later zero cannot mean an empty table; a positive control proves the
denial is the missing context rather than a scope that denies unconditionally.

**Mutation, run rather than claimed:**

| # | Mutation | Result |
|---|----------|--------|
| 1 | `whereRaw('1 = 0')` → bare `return;` (scope degrades to "no filter") | **2 failed, 2 passed** — killed |

The failure is the informative one: `Failed asserting that 2 is identical to 0`
— the context-free query saw **both** shops. The precondition and the positive
control correctly survive, since neither depends on the deny branch. Reverted
and re-verified: `md5 ed2014819134cc65d58daedf29293ea8`, `git diff` empty,
4 passed again.

### Stated limitations

* **Inspected, not behaviourally tested:** the 18 `withoutTenant()` sites and
  the 31 raw-SQL sites. They were read; no test was written per site. Writing
  ~49 route probes to re-prove a guard that is visible in three lines of source
  would be raising a count.
* The new test binds the **read** side. A context-free create is caught by the
  column's `NOT NULL` constraint, which is the database's guarantee and is
  pinned where the schema is pinned.
* The `withoutTenant()` scan used a 12-line window and the raw-SQL scan an
  18-line window. A call whose shop constraint is applied further away than
  that would have surfaced as a candidate and been read anyway — the windows
  bound false negatives in the *filter*, not in the inspection.
* `app/Console/` was excluded from the `withoutTenant()` narrowing. Operator-run
  commands take an explicit shop argument and are covered by
  `ConstitutionalInvariantsTest::test_no_writes_in_reconciliation_commands`.

## 8. Commands actually run, and their results

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
| **The 18 `withoutTenant()` sites with no nearby shop constraint** | **INSPECTED, NOT BEHAVIOURALLY TESTED — see §7e** |
| **The 31 tenant-facing raw-SQL sites with no nearby `shop_id`** | **INSPECTED, NOT BEHAVIOURALLY TESTED — see §7e** |
| **Context-free CREATE on a tenant model** | **NOT BOUND HERE — caught by the column's `NOT NULL`, pinned with the schema** |

### §7e run figures

```
php artisan test tests/Feature/Security/TenantScopeFailClosedTest.php
  -> 4 passed (7 assertions)

# mutation: BelongsToShop.php:23  whereRaw('1 = 0')  ->  bare `return;`
php artisan test tests/Feature/Security/TenantScopeFailClosedTest.php
  -> 2 failed, 2 passed (7 assertions)
     "Failed asserting that 2 is identical to 0"   <- saw BOTH shops

# reverted
md5sum app/Models/Concerns/BelongsToShop.php
  -> ed2014819134cc65d58daedf29293ea8    (matches pre-mutation)
git diff --stat app/Models/Concerns/BelongsToShop.php
  -> (empty)

php artisan test tests/Feature/Security/
  -> 196 passed (786 assertions)      [192/779 before this file]
```

Scan figures, for reproduction:

```
grep -rn "withoutTenant(" app/            -> 144 occurrences, 58 files
  minus app/Console/, minus a shop/key constraint within 12 lines
                                          -> 18 candidates, 0 defects

grep -rn "DB::table(|DB::select(" app/    -> 227 occurrences
  restricted to app/{Reporting,Services,Models,Http/Controllers} less Admin/
  and with no shop_id within 18 lines     -> 31 candidates, 0 defects

grep for create/update/fill($request->all())      -> 0 (8 matches are Collection->all())
grep for 'shop_id' => '...' in a validate() ruleset -> 0
```

## 9. Commits, diff, working tree

**Measured at `1b6aeb4`, not at HEAD — deliberately.** A diffstat recorded inside
a tracked file changes the diffstat, so "the figure at HEAD" has no fixed point,
and chasing it is exactly how the earlier revisions of this line came to be
wrong. Pinning it to a named commit makes it rerunnable:

```
$ git diff --shortstat 018b3d8..2dd0875
 58 files changed, 11868 insertions(+), 100 deletions(-)

$ git log --oneline 018b3d8..2dd0875 | wc -l
44
```

`2dd0875` is the last **code** commit on the branch; every commit after it is an
edit to this document.

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

1. S3-05 is now fixed for **every field the document asserts about the
   transaction** — the statutory half in `b216b80` (`igst_mode`, HSN map) and
   the rest in this session: `show_gstin`, `shop.gst_number`,
   `terms_and_conditions` and all nine payment-instruction fields. See §7d.
   What remains is **not** a gap to close but a deliberate bound: theme colour,
   font tier, paper size, subtitle, tagline, copy label and the other `show_*`
   toggles describe the printer in front of you, not the sale, and pinning them
   to an old snapshot would be a defect rather than a repair. D-12 exists to
   keep that bound honest.

   Two things genuinely remain open and are recorded as limitations, not
   repairs: **quick-bill snapshots carry less** than invoice ones (no
   `show_gstin`, no itemised bank group — capturing them is a separate change),
   and **bills finalized before these keys were captured** have no record to
   fall back on and still resolve live.
2. The mobile on-screen "Signature unavailable" banner (§5). The printed
   document already carries the marker; what is missing is the in-app banner in
   `app/invoice/[id].tsx` / `app/quick-bill/[id].tsx`, which today only raise
   `Alert.alert('Print Failed', …)`. **I have made no change to the mobile
   repository** (still `d8a0781`).
3. Remaining model/route/job investigations. **The cache investigation is
   DONE** — every `Cache::` call site in `app/` was reviewed; see §7b. No
   rendered document, and therefore no signature byte, is held in any
   application cache. The one unscoped key is S3-07.
4. The tracked backup repair, including spelling out what "backup fix A+C"
   changes.
5. Candidate-public asset classification. **Item images are now classified**
   (§7a): they have a real, opt-in publishing feature and are NOT relocation
   candidates. Still unclassified: `products`, `shop-logos`, `catalog-heroes`,
   `UploadIntentService:110,251`, `Api\Mobile\ItemController:226,495`.
6. S3-06b, if the business wants it: a per-item publication flag. Adding it
   should break C-03, which is where to record the change.
