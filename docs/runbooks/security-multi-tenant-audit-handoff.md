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
| S3-05 finalized invoice reprints with today's settings | OPEN | **None — characterization tests only** | OPEN |

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
* Superseded signature versions are named by no settings row — only by snapshots.
  `--orphans` reports them; the default pass does not move them, because moving a
  file that no row names would strip the only pointer to it.
* **If an image was already overwritten or deleted by the baseline's
  `Storage::delete()` calls, it is gone. It cannot be reconstructed.** Nothing
  here claims otherwise.

---

## 8. Commands actually run, and their results

```
php artisan test tests/Feature/Security tests/Feature/Mobile
  -> 206 passed, 722 assertions

php artisan test --filter='Invoice|Sales|Exchange|Installment|Return|QuickBill|Repair'
  -> 427 passed, 2 skipped, 1694 assertions
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
| Release ordering | yes (T-01…T-07) |
| **On-device print** | **NOT RUN — see §5** |
| **Edge cache behaviour** | **NOT RUN — no Cloudflare access** |

## 9. Commits, diff, working tree

22 commits ahead of `018b3d8`; cumulative **46 files changed, +7,813 / −54**.
This session added:

```
7fc27cf  Capture a render snapshot at finalization (S3-04)
0ae6b15  Drive the quick-bill signature regression through real routes (S3-04)
1eef5b2  Prove relocation preserves the signature on the printed page (S3-04)
```

(plus, earlier in the same session: `a542745` migration split, `ad9babe` runbook
de-duplication, `2f26386` S3-05 characterization tests.)

**Working tree is clean. No dirty or untracked files in this repository.**
Untracked-but-ignored artifacts exist and are intentional: `node_modules`
(a symlink to the primary checkout) and `public/build` (Vite output), both
covered by `.gitignore`.

---

## 10. Next, while production approval is pending

1. S3-05 has characterization tests and **no fix**. A fix extends the finalized
   invoice's snapshot to cover `igst_mode` and the HSN map, with the same
   legacy-snapshot compatibility rules the signature snapshot uses.
2. The mobile on-screen "Signature unavailable" banner (§5).
3. Remaining model/route/job/cache investigations.
4. The tracked backup repair.
5. Item-image and candidate-public asset classification (§7).
