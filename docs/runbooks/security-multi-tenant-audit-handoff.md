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
| S3-05 finalized invoice reprints with today's settings | **PARTIAL** — `igst_mode` + HSN fixed. The rest still drift, and they are **not** all cosmetic: bank details, printed terms and the GSTIN drift too (§1a) | Fix `b216b80`; classification `2a6c7ce`; 12 tests | **OPEN** |
| S3-06 catalog tenant context survives a throw | **CLOSED as a code defect** — no cross-tenant read demonstrated | Fix committed (`721c06d`), 5 tests | **OPEN — needs the deploy** |
| S3-06b enabling a shopfront publishes every in-stock item | OPEN — product-consent gap, not a tenant break | Characterized (C-03), deliberately not repaired | N/A — feature decision, not an audit repair |
| S3-06c published item images outlive the shopfront toggle | OPEN | None — recorded limitation | **OPEN** |
| S3-07 mobile payment cache-hit path was unauthorized | **CLOSED as a code defect** — no exploit against shipped code; the binding blocked it | Guard committed (`0296431`), 9 tests | **OPEN — needs the deploy** |
| S3-07b payment retry integrity (cache/commit not coordinated) | **REPAIRED LOCALLY** — both defects closed; two more found by real concurrency and fixed; **rollback to the deployed baseline is measured UNSAFE** | Characterized `893a49b` (3 tests) → repaired `2dd0875` → claim scope `0cdd794` → claim staked before validation + P-14 `7d20e08` → rollback evidence, P-06 relabel, P-15 `b1a52f0`. 15 tests, 250 in band | **OPEN — needs the deploy, under the constraints in `payment-idempotency-rollback-constraints.md`** |
| S3-08 static memoization across a long-lived worker | **CLOSED — examined, not a tenant break** | None needed; one inaccurate docblock noted | N/A |
| S3-09 `EnsureIdempotency` records completion AFTER the controller, outside any transaction | **OPEN — identified, NOT repaired** | None. Scope enumerated only (§7c) | **OPEN** |

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
`FinalizedInvoiceSettingsDriftTest` D-09…D-12 — file now 12 passed, 48
assertions). D-12 is the bound: theme colour drifts by the identical mechanism
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

`app/Http/Middleware/EnsureIdempotency.php`:

* line 140 — `$response = $next($request);` the controller runs, commits its own
  transaction, and returns.
* line 149 — `IdempotencyKey::create([...])` the completion record is written
  **afterwards**, in a separate statement, **outside any transaction**.

That is the same uncoordinated-commit shape S3-07b had, in shared middleware.
Anything that kills the process between those two lines leaves the business
effect durable and no record that the key was used, so the retry re-runs it.

Two further soft-failure paths widen it:

* the unique-collision `catch (QueryException)` logs
  `EnsureIdempotency: concurrent insert collided on unique key` and returns the
  response — it does not replay the winner's;
* the outer `catch (Throwable)` fails soft, with a comment that already concedes
  *"a future retry with the same key will simply re-run (not ideal…)"*.

The uniqueness is on `(shop_id, user_id, key)`. A **read** failure against the
table returns 503, so the read side is fail-closed; it is the **write** side
that is not.

### Affected state-changing routes — 16, enumerated from `route:list`, not grep

| Route | What a duplicate does |
|---|---|
| `POST /uploads/intent` | extra pending upload record; benign, storage only |
| `POST /cashbook` | **duplicate cash movement** — money in/out recorded twice |
| `POST /cashbook/drawer-check` | duplicate drawer reconciliation entry; corrupts the count trail |
| `POST /sessions/lock` | second lock on an already-locked session |
| `POST /sessions/unlock` | second unlock; re-opens a session an operator closed |
| `DELETE /sessions` | second bulk revoke; idempotent in effect, audit noise |
| `DELETE /sessions/{session}` | as above, single session |
| `PATCH /items/{item}` | re-applies an update; last-write-wins, may clobber an edit made between the two attempts |
| `PATCH /customers/{customer}` | as above, customer record |
| `POST /returns` | **duplicate return order** — stock returned twice |
| `POST /returns/{returnOrder}/approve` | second approval on an approved return; credit-note risk |
| `POST /job-orders` | duplicate job order issued to a karigar |
| `POST /job-orders/{jobOrder}/receipt` | **duplicate karigar receipt** — metal received twice |
| `POST /installments/finalize` | duplicate plan finalization |
| `POST /installments/discard-draft` | second discard; benign |
| `POST /installments/{plan}/pay` | **duplicate installment payment** — money recorded twice |

Four of these move money or metal: `cashbook`, `returns`,
`job-orders/{jobOrder}/receipt`, `installments/{plan}/pay`.

**Status: identified and scoped, NOT repaired and NOT tested.** No fix is
attempted here, and no broad middleware rewrite is proposed. Verified by reading
`CashBookController::store` (lines 140–186): it calls `CashTransaction::record`
and `AuditLog::create` with no internal deduplication of its own, so the
middleware is the only thing standing between a retry and a second ledger row.
The other 15 controllers have **not** been read for internal dedup — that is
NOT RUN, not "confirmed absent".

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
| **Per-item publication opt-out** | **none exists — characterized by C-03, not covered** |
| **Anonymous HTTP fetch of a public-disk file** | **NOT RUN — served by nginx, not Laravel; unmeasurable from the suite** |
| **On-device print** | **NOT RUN — see §5** |
| **Edge cache behaviour** | **NOT RUN — no Cloudflare access** |

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

1. S3-05's **statutory half is fixed** (`b216b80`): `igst_mode` and the HSN map
   now come from the bill's own snapshot on all five render paths (web invoice,
   web quick bill, quick-bill original, and both mobile HTML endpoints), with
   the same read-time legacy defaulting the signature snapshot uses. **The
   cosmetic half is not fixed** — 43 live settings reads (theme colour, font
   tier, paper size, subtitle, tagline) still re-resolve at reprint, and bills
   finalized before these keys were captured still fall back to live settings.
   S3-05 therefore stays PARTIAL, not closed.
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
