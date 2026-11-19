# Codex handoff before local acceptance — 2026-11-18

Consolidated update from Codex's checkpoint (`636273ce`, tested `5032f245`) to the current review candidate.
Written by Claude from the repositories, git history, test runs and the session records listed under each
item. **Verified** means checked today with the command or run named; **record** means taken from an
earlier written record and not re-checked today; **inferred** means reasoned from source, not run.

Nothing in this handoff was deployed, merged to `main`, pushed to staging/production, built as an APK/AAB
or published as an OTA. OTP stays excluded from production.

## 0. Revisions at a glance

| What | SHA |
|---|---|
| Review branch | `feature/table-consistency` (web), pushed without force |
| Code tip tested | `c5c46e452c6f23a4dad80caacf4223806a54e3fe` |
| Review tip | the docs-only commit that adds this file, directly on `c5c46e45` (SHA in the commit message reply / `git log -1`) |
| Codex checkpoint | `636273ce9026bf0ee3550156d2cd0fa54c754f45` (ancestor of the tip, verified) |
| Codex's last tested code | `5032f245` (ancestor of the tip, verified) |
| Remote branch before this push | `85233dbc98a8178451935cee2fb0490a1172e4c9` (ancestor, verified with `ls-remote` + `merge-base`) |
| Local HEAD before today's commits | `801448c8ee697a44cc12943ba93c080818ba04c5` |
| `main` (origin) | `d7dcfb0367fa6ffd3571502d25c44a7bdb36aedf` (verified with `ls-remote` today) |
| Production / staging | `d7dcfb0` per release tag `release/production-20261017-toast-tones` and the 2026-10-17/31 records; **not re-checked on the servers today** |
| Mobile review branch | `integration/mobile-companion-20261017` at `efdf71f700dbf9eca6e6458e4ebd8977eb95fca5`, base `bfaac1a` (see §1.2) |

Comparison commands:

```
git log --oneline 636273ce..c5c46e45          # everything since the checkpoint (331 commits)
git diff --stat 636273ce c5c46e45             # 812 files
git log --oneline 801448c8..c5c46e45          # today's 14 commits (worktree changes made into commits)
git diff d7dcfb0...c5c46e45                    # what staging/production would receive
```

The full one-line list of commits since the checkpoint is in
`docs/handoffs/evidence/2026-11-18-commits-since-636273ce.txt`.

## 1. Current state

### 1.1 Web

- Worktree `/home/himanshu/Desktop/jewelflow-worktrees/ui-integration`, branch `feature/table-consistency`.
- Before today the worktree carried 109 modified, 25 staged-deleted and 82 untracked paths from three
  streams: Claude (2026-11-15..18), Codex (2026-11-12, its own notes in
  `docs/handoffs/table-consistency-takeover-2026-11-01.md`) and one admin fix. All were completed work by
  their own records; they were committed as 14 commits (§2.2). After the commit the worktree content is
  **byte-identical** to `c5c46e45` (verified: every changed path assigned, tip blobs compared with
  `git hash-object` of the worktree files). No stashes in the web repo (verified `git stash list`).
- How the commits were made: built on a private index (the sandbox cannot write this worktree's own index),
  then the branch ref was moved with a compare-and-swap `update-ref` from `801448c8` and the real index reset
  with `git reset -q` (index only, files untouched). Codex's own uncommitted edits, if it opens the worktree,
  now show as committed — nothing was discarded. `app.css` and `app.js` had a stray 0755 mode in the
  worktree (no content change; Git has 0644 since before the checkpoint); reset to 0644 on disk, not committed.
- Interleaved files: `resources/views/settings.blade.php` carries both Codex's Settings rework and Claude's
  Settings changes on the same lines, so it is one combined commit (`96c68089`). `app.css`, `app.js`,
  `routes/web.php`, `reports/hub.blade.php` and `layouts/app.blade.php` were split hunk by hunk (line by
  line inside insert-only hunks) into the commit each part belongs to. Intermediate commits were **not**
  tested individually; only the tip was.
- Other web checkout: `/home/himanshu/Desktop/JewelFlow` (main checkout) is on `fix/small-batch-20260914`
  `018b3d81` with untracked report notes at its root; not part of this review and not touched.
- Paused OTP code: see §4.

### 1.2 Mobile

- Worktree `/home/himanshu/Desktop/jewelflow-worktrees/mobile-companion`, repo
  `/home/himanshu/Desktop/jewelflowMobileApp`, branch `integration/mobile-companion-20261017`.
- origin `bfaac1a` (verified); local was 3 ahead (`8475e49`, `d80bf73`, `d307a78`: repair photos with sign-in,
  hide shop totals without See Shop Money, Shop notices = the web's one bell). Today two commits were added
  on top and the branch pushed without force, origin now `efdf71f700dbf9eca6e6458e4ebd8977eb95fca5`
  (verified `ls-remote`): `7ab8cf63` the financial-year app tests
  (year-numbered bill number shown verbatim; the server's credit-note deadline message shown on Approve)
  and `efdf71f7` removal of the customer's points from POS checkout, matching the web POS (owner,
  2026-11-15). Comparison base for the app: `bfaac1a` (Codex review round 2).
- **Left uncommitted on purpose:** `app/(tabs)/_layout.tsx` + untracked
  `src/__tests__/stock-tab-badge.test.tsx` (a reorder-count badge on the Stock tab, files dated
  2026-10-31). Its owner and completeness are not recorded anywhere I could find, so it is preserved, not
  committed. Stash `stash@{0}` ("mobile-dirty-work-before-shop-access-device-test", 8 files incl.
  `package.json`) is also preserved and untouched.
- Mobile `main` on origin: `c070364c`. No build, no OTA.

## 2. Changes since the checkpoint

### 2.1 Committed before today (`636273ce..801448c8`, 317 commits, 2026-11-01..15)

Grouped by area; commit subjects in the evidence list carry the details and audit IDs.

1. **Lists and table workspaces (Codex, 11-01..02).** Contained record scrolling with list controls kept in
   view (`07b42d0a`, `list-workspace.js`, `tables.css`), Masters hub rows, customers/occasions workspace,
   row numbers on customer tables, view switches in headers, native status dropdowns.
2. **Register and form compaction (11-02..05).** Quick Bill entry layout (UI only), returns refund rows,
   Schemes, Purchases (behaviour change: a new supplier typed on a purchase is saved to Vendors,
   `54e49e7b`; guarded draft confirmation), Stock workspaces split into dedicated pages with filter popup and
   header metrics, tag printing, Vault tiles, Karigar dialogs and info bubbles, Job Orders, Issue Bullion
   sticky challan summary, thin orange scrollbars.
3. **Behaviour fixes (11-06).** Second reorder rule for one stock group refused; category in use cannot be
   deleted; typed purchase rate kept; Reverse hidden on reversed karigar payments; admin Users search fixed;
   unreadable dates ignored on registers.
4. **RBAC (11-07).** Module permissions (`2026_11_07_100000`), "See Shop Money" switch `reports.money`
   (`2026_11_07_110000`) hiding money reports/totals from staff, cashiers kept out of Settings, links shown
   only when the page would admit the user, two attack-test holes closed; new metal lots require a metal
   (`2026_11_07_120000`).
5. **Dhiran (11-07..12).** QA fixes, navy/gold token theme, tables (row numbers, footers, phone cards, CSV
   export), evidence gallery, Old Loans import (brought forward), and root-cause fixes in interest, audit,
   **forfeiture** (stages decided in one place, `8ed38a17`; notice/deadline `86b87683`, `3f5d5c25`),
   reminders, lapsed plans; migrations `2026_11_12_100000..130000`.
6. **Retail UI audit F01–F16 (11-13)** from Codex's remaining-UI audit: invoice tiles, field names for
   screen readers, modal dialogs, floating messages, app confirm dialogs instead of browser pop-ups, staff
   and store-credit forms, row numbers on the remaining tables, error pages.
7. **Correctness audit H1–H4, M1–M6, L1–L3, N1–N23 (11-13..14).** Repairs bill once (trigger,
   `2026_11_13_100000`), POS bills only its own quote, EMI completes only when paid, scheme bonus only when
   every instalment is paid, returns decided under lock, karigar payment entry types
   (`2026_11_13_120000`), onboarding batch finality (`2026_11_13_110000`), one pending edition request
   (`2026_11_13_130000`), stranded Historical publishing (`2026_11_14_100000`), users active = employment
   status (`2026_11_14_110000`, **N23**), shops refuse activation while a GDPR deletion is pending
   (`2026_11_14_120000`, **N22**), scanner sessions, quick-bill void lock (correctness only), tenant query
   reviews.
8. **One bell (11-14/15).** Price reviews and ready downloads join the header bell; the app's Shop notices
   match (mobile `d307a78`).
9. **Phone and tablet headers (11-15).** One-row phone headers, a 44/40px header size standard, page
   descriptions behind an info button on phones, header badges removed, dashboard tiles sized to space,
   fixed-width layouts fixed, three unrouted report pages removed.

### 2.2 Committed today (`801448c8..c5c46e45`, 14 commits)

| Commit | Stream | What |
|---|---|---|
| `68c57013` | Claude | Remove 25 dead pages/controllers/Breeze components (route- and include-checked) |
| `ebf169a7` | Codex | Record details theme: Edit Scheme, Karigar Profile, Purchase Details |
| `ddb9fd15` | Codex | Vault ledger and Add Bullion layout |
| `944ff45a` | Codex | Shared individual report screen, export popup, Data Exports moved to the Reports header; sales register uses the shared screen |
| `b01dd695` | Claude (earlier session) | Platform-admin code-guess counter under a cache lock (follow-up to `c244a3da`) |
| `ea863f78` | Claude | Sidebar is a slide-in panel up to 1280px |
| `72d71f5a` | Claude | Phone section switch (open section as title, tap lists the others) on Settings/Customers/Stock |
| `690b2feb` | Claude | POS desktop checkout: 19 layout and flow fixes |
| `77dea72b` | Claude | Returns queue value / product-preferences notice off the card edges |
| `75b93aea` | Claude | "By Factual Technologies" subtitle; public policy pages `/about /contact /terms /privacy /refund-policy /delivery-policy` from `config/legal.php` |
| `e2fe1de0` | Claude | Saving rates warns when stock cannot be priced; Pricing tab banner + "Items needing a price" |
| `389cf4c8` | Claude | Financial years (below) |
| `96c68089` | Codex + Claude | Settings: workspace theme, unsaved-changes guard, header imports, invoice numbering fields, books lock panel, thin footer |
| `c5c46e45` | Codex | Codex's 2026-11-12 checkpoint notes and remaining-UI audit |

**Financial years (`389cf4c8`) — completed, quick bills out of scope.**
- Invoice numbers per financial year (IST, 1 Apr–31 Mar): `invoices.fiscal_year` + `invoice_series`,
  continuous or yearly series with an `{FY}` token, uniqueness (shop, fiscal_year, sequence), one SQL
  implementation (`next_invoice_identity`, `peek_invoice_identity`, `render_invoice_number`). **Constitutional
  triggers #6 and #29 amended** (owner sign-off; `CONSTITUTION.md` rows 6, 29, 30–36 and §5 updated). A draft
  billed in a later year takes that year's number and the old number is recorded as voided.
- Starting-number rules: free before the series' first bill; afterwards owner-only, forward-only, a gap must
  be confirmed, audited (`invoice.start_number_changed`); Settings shows the next number.
- Period lock `shop_rules.financial_lock_date`: owner-only lock/unlock (`/settings/books/lock|unlock`),
  only the latest closed year can be reopened; checks on purchases, karigar invoices/payments/opening
  balances, job orders, returns, exchanges, reversals; finished-year dates need a reason, permission
  `books.backdate` (`2026_11_16_400000`) and an audit row; owner dashboard reminder with "Not now"
  (`2026_11_16_400100`).
- GST credit-note deadline (30 November after the year) on returns, exchanges and reversal; the app shows
  the server's message.
- Stock history: `items.acquisition_cost` frozen by trigger, append-only `item_movements` written by
  triggers (#30–#36, `2026_11_16_300000`), `StockAsAt`, lower of cost and market; `/inventory/missing-costs`
  (inventory.edit + reports.money, set once, audited).
- Year-end: append-only `fiscal_year_snapshots` (`2026_11_16_500000`), `books:snapshot-year-end` scheduled
  daily 00:15 IST (`routes/console.php`), `/books/year-end` checklist (owner), `BalancesAsAt`; snapshots
  before exact history are labelled reconstructed (`stock_history_starts`, `2026_11_16_700000`).
- Issued-sales rule across every sales/GST/profit/dashboard/register figure: a bill stays in its own period;
  a cancellation, reversal bill or credit note reduces its own period.
- Year reports: closing stock, stock register by weight (3CD cl. 35), trading account, GSTR-1 table 13
  documents issued, annual HSN (`/report/{closing-stock,stock-register-weight,trading-account,
  gst-documents-issued,gst-hsn-annual}`); **monthly GSTR-1 HSN is now net of credit notes** (behaviour
  change, owner-approved 2026-11-16, so twelve months add up to the year).
- Migrations `2026_11_16_100000` and `600000` drop and recreate SQL functions and amend constitutional
  triggers: rehearse on a copy of production data before staging.

**Removals:** 25 dead views/controllers (`68c57013`), three unrouted report pages (`801448c8`), Settings'
"Download Reports" link (moved to Reports), the old Settings sideways tab strip on phones (now the section
switch).

**Config/dependencies:** new `config/legal.php` (business name, GSTIN, address, grievance officer, etc.;
missing values render as visible placeholders, so fill it before any public launch). No composer/npm
dependency changes since the checkpoint (verified: `composer.json`, `package.json` unchanged in the diff).

**Partial / experimental:** none of the committed code is experimental. Verification gaps are listed in §5.
Codex's individual-reports plan (`docs/superpowers/plans/2026-11-12-individual-reports.md`) still has 14
unticked boxes; its checkpoint section says the code is done and broader signed-in browser verification is
pending — treat that screen family as needing acceptance checks.

## 3. Bugs and fixes

### 3.1 Areas named in the request

| Area | State | Evidence |
|---|---|---|
| Contained table scrolling (tabs, KPIs, filters, column headings stay while records scroll) | Fixed `07b42d0a` (Codex, 11-01), extended to Dhiran (`947ce80c`, `b74ddd6f`) and Repairs (`45cc0195`, `1de1e261`); Settings Pricing tables scroll in their own wrappers | `tests/Feature/Tables/ListWorkspaceUiTest.php` (passes on tip); browser checks per Codex 11-01 notes; **not re-checked in a browser today** |
| Sticky headers | Column headings sticky inside the contained scroll region (`tables.css` in `07b42d0a`); POS sell block sticky on desktop (`690b2feb`); Issue Bullion challan summary sticky (`710ba428`) | DOM tests only for tables; POS checked in the in-app browser 2026-11-16 (desktop) |
| Row actions | Not done as a dedicated pass. Related: field names for screen readers (F02 `609ef87a`), mobile View icons visible (`c64be5f6`), Reverse hidden when already reversed (`5633f7fe`), invoice action-column shadow removed (`ea14eb67`) | **Open:** row-action keyboard/screen-reader audit from the checkpoint list |
| Mobile-width layouts | Phone headers one row (`ee888727`), section switch (`72d71f5a`), fixed-width layouts (`03e4c723`), Dhiran phone cards (`db9fdb28`), Settings tabs at 320/390px (Codex notes) | Tests in `tests/Feature/Tables/*`, `tests/Frontend/*`; Chromium emulation only; **no physical phone** |
| Editable grids | Quick Bill entry row and purchase entry compacted (UI); no editable-grid behaviour test pass done | **Open:** editable-grid keyboard/validation checks from the checkpoint list |
| Admin 2FA page error (`/admin/2fa/manage`) | **Reproduced today, not fixed.** HTTP 500 for every admin role, 2FA on or off: `Undefined variable $slot` in `resources/views/super-admin/layout.blade.php`. Cause: `2fa-enroll` / `2fa-manage` use `@extends('super-admin.layout')`, but that layout is a component layout that prints `{{ $slot }}` and has no `@yield`. Same files on `main` → production is expected to have the same 500 (**inferred**, not hit live). Fix is small (wrap the views in `<x-...>` layout or add `@yield('content')`), deliberately not made in this frozen candidate | probe test run on the tip export (§5) |
| Dhiran forfeiture | Fixed (11-08..12) | `8ed38a17`, `86b87683`, `3f5d5c25`, Dhiran feature tests pass on tip |

### 3.2 Found and fixed this stretch (Claude, 11-15..18)

- POS: compliance alert and remove column overflowing the panel; sell button with no reason; "Fully paid"
  shown when not; unpriced items silently Rs 0 → reproduced in the in-app browser on the demo, fixed in
  `690b2feb`.
- Repricing: saving rates left metal-less stock at Rs 0 and said "saved" → `RetailerPricingTest`
  `test_saving_rates_warns_about_stock_the_rate_cannot_price` (red then green), `e2fe1de0`. The "Review"
  anchor inside the Turbo frame reloaded the frame → replaced by a scroll button.
- Settings footer pushed a 27px page scroll → layout height `calc(100vh - 192px)` + compact footer.
- Financial years, all with failing tests first:
  `Invoice::issue` lost the trigger-assigned number (read back after save);
  yearly reset collided on uniqueness (per-FY counter keys in SQL);
  `peek_invoice_identity` ambiguous OUT parameter hidden by a `rescue()` (renamed, rescue removed, function
  dropped before re-create);
  31 March snapshot rate NULL when no rate that day (falls back to the latest earlier rate and records its
  date);
  cancelled sales reduced twice (`CancelledSalesStayInTheirPeriodTest` probe, fixed by the issued-sales rule);
  lock refusal text broke the "hide without explaining" rule (now `NotAvailable::MESSAGE`). *Correction
  (2026-11-19 acceptance): this covers the `books.backdate` and lock/unlock-permission refusals only; the
  period-lock refusal itself still reads "Financial lock is active through <date>" for everyone — see §7.3.*
- Banner spacing: returns queue value box and product-preferences notice touching card edges (`77dea72b`).

### 3.3 Unresolved

- Admin 2FA 500 (above).
- Row-action accessibility audit and editable-grid checks (from the checkpoint list) not done.
- `item_movements.created_by` is not set and sale/return movements are not linked to their documents.
- Vendor payments are not recorded in the app (affects the trading account's creditors view only).
- Lock reminder in the mobile app: owner decision pending (needs an API field and a build).
- Quick bills: financial-year numbering intentionally not applied (owner will do them separately).

## 4. Integration and boundaries

- History since the checkpoint is linear: no merge commits (verified `git log --merges`). Earlier local
  branches are contained in the tip (verified ancestry): `integration/ui-all` `3df1baf7`,
  `feature/lucide-icons-batch1` `090436da`, `feature/mobile-companion-api` `b1d7f5c4`. Nothing superseded
  was reverted; Codex's 11-12 Settings export link was superseded by Data Exports in the Reports header
  (same stream).
- **OTP (paused):** code lives only on local branches `feature/login-mobile-verification` (`48b0d5e`, code
  `19b976a`, head `df5a40a`) and `staging/photos-and-free-trial-switch`; none of these SHAs is on origin
  (verified `ls-remote` today), in `main` `d7dcfb0` or in this review tip (verified `merge-base
  --is-ancestor`). Staging ran the OTP line `9473326` on 2026-10-15 and its OTP tables were kept on the
  staging DB when staging was moved back (record, 2026-10-16). Production never received OTP code
  (record: release `2e005b3` and every later release excluded it; ancestry check above).
- **Deployments / server changes since the checkpoint:** none made in these sessions (record; no SSH used
  today). Production and staging are recorded at `d7dcfb0`.
- **Real notifications:** none sent (no SMS/WhatsApp/email providers called).
- **Database operations:** only the local demo database `127.0.0.1:55432/jewelflow_mobile_demo`: all new
  migrations applied (latest `2026_11_16_700000`), demo owner password reset (stored outside Git), demo items
  given real prices (today's rate set, metal types set via Legacy Items). Tests ran on throwaway
  PostgreSQL instances and on the host's guarded `jewelflow_testing` (port 5432).
- **Before any production deploy:** run the N23 users query and the N22 GDPR-pending-shops query, or
  migrations `2026_11_14_110000` / `120000` halt the deploy (owner's standing reminder).

## 5. Verification evidence

All on code tip `c5c46e45` unless marked. Logs under
`/home/himanshu/Desktop/jewelflow-worktrees/build-logs/handoff-20261118/`.

| Check | Where it ran | Result |
|---|---|---|
| Full PHPUnit suite | clean `git archive` export of `c5c46e45`, copied `vendor`, built assets, throwaway PostgreSQL 16 (sandbox) | 4,882 tests, 30,751 assertions: 15 errors + 5 failures, 3 skipped, 37 PHPUnit deprecations. All 20 are the sandbox-only groups below |
| Frontend `node --test tests/Frontend/*.test.mjs` | same export | 464 tests: 442 pass, 22 skipped (pre-existing), 0 fail |
| Asset build `vite build` | same export, output to a temp dir | built, no errors |
| `tests/Feature/Tables` | same export | 195 tests, 2487 assertions, OK |
| Chromium PDF tests (18: every report/dataset in every format, compliance PDFs, challan PDFs, RBAC route matrix) and backup tests (11) | host, guarded `jewelflow_testing` on 5432, worktree checked out at `c5c46e45` (after the commit, index clean) | 18 tests / 225 assertions OK; 11 tests / 51 assertions OK (`host-pdf-tests.log`, `host-backup-tests.log`) |
| Admin 2FA probe | same export, throwaway test outside the repo | 500 for 4/4 cases (§3.1) |
| Mobile jest: invoice-pay, return-approve-screen, pos-checkout | mobile worktree | 3 suites, 24 tests pass; `tsc --noEmit` clean; one "worker failed to exit gracefully" warning (known, harmless) |

The 20 sandbox failures: 18 are PDF renders where the sandbox cannot start Chromium ("Chromium failed to render
PDF … Failed to create a unique …"; the report `all_file_formats_render` tests, `ChromiumPdfSmokeTest`,
`Phase2PdfDocumentTest`, `ComplianceReportsTest`, `DataExportsTest`, `SalesRegisterTest`, the challan PDF tests
and `RbacPermissionMatrixTest::test_every_permission_switches_every_route_it_guards`, which renders a PDF
route) and 2 are `BackupSourceExclusionTest` cases that depend on the host's storage layout. These are exactly
the groups the host run covers (all passed there, next row up). Together: no failing test on `c5c46e45`.
Log: `tip-full-suite.log`.

Browser coverage (in-app Chromium pane against the local demo, desktop widths unless stated):
POS desktop checkout, Pricing tab banner and Review button, Settings footer (no outer scroll), legal pages,
invoice numbering settings, books lock, year-end page, missing-costs page, year reports — 2026-11-16..18,
before the commit, same code. Codex's own browser checks (Settings 17 tabs at 320/390px, record details,
Data Exports, export popup) are described in its notes. **Not covered:** physical phones and tablets, real
Back/Forward traversal, native refresh/close prompts, the mobile app on an emulator or device for the
financial-year changes, PDFs of the five new year reports opened by a person.

Checks invalidated or superseded: the earlier full-suite runs on the worktree (4,882 tests, 2026-11-18) are
superseded by the run on the tip export above. Codex's runs listed in its notes were made before Claude's
later edits to the same Settings/report files.

Known flaky: Vite manifest missing while a build runs (Codex: do not build while PHP tests render pages);
the sandbox cannot run Chromium (PDF tests error there; run them on the host).

## 6. Local acceptance handoff

### 6.1 Start

```
bash /home/himanshu/Desktop/jewelflow-worktrees/build-logs/demo-start.sh     # demo PostgreSQL 16 on 127.0.0.1:55432
cd /home/himanshu/Desktop/jewelflow-worktrees/ui-integration
php8.2 artisan migrate                                                         # applies anything pending to the demo DB only
npm run build -- --emptyOutDir false                                           # assets; do not run while PHP tests run
php8.2 artisan serve --host=127.0.0.1 --port=8001                              # or launch entry "ui-integration-demo"
```

- Retail app: `http://localhost:8001` (use `localhost` consistently; `127.0.0.1` has a separate cookie jar).
- Dhiran demo (optional): launch `dhiran-demo` → `http://dhiran.localhost:8003`, DB 55433.
- The demo `.env` points at `127.0.0.1:55432/jewelflow_mobile_demo`; check before migrating
  (`php8.2 artisan about | grep -i database`). Never point it at a real database.
- Scheduler (year-end snapshot): `php8.2 artisan books:snapshot-year-end` runs it once by hand.

### 6.2 Data and roles (synthetic)

| Shop | Role | Login mobile |
|---|---|---|
| JewelFlows Demo Jewellers (retail, id 1) | owner | 9000000111 |
|  | manager | 9000000112 |
|  | cashier | 9000000113 |
| Demo Gold Finance (Dhiran, id 2) | owner | 9000000201 |

Passwords are not in Git or chat. The retail owner's is in
`/home/himanshu/Desktop/jewelflow-worktrees/build-logs/qa/retail-demo-owner-cred.txt`; reset it with
`php8.2 artisan tinker --execute="require '/home/himanshu/Desktop/jewelflow-worktrees/build-logs/qa/reset-retail-demo-owner.php';"`
(refuses any database but the demo). Set manager/cashier passwords the same way if needed. For tenant
checks, register a second retail shop on `localhost:8001/register` with made-up details.

### 6.3 Risk-based checklist

Highest risk first. "Owner" / "Manager" / "Cashier" = the demo roles.

1. **Invoice numbering.** Owner: Settings → Invoice → yearly series with `INV/{FY}/`; make a POS sale; number
   reads `INV/26-27/…`; next number shown in Settings moves. Change the start number backwards (refused),
   forwards (gap confirmation, audit row). Manager cannot change it after the first bill. Continuous series
   still counts on across a year boundary (use a back-dated test only on the demo).
2. **Period lock.** Owner locks books to a past date; as manager try a purchase, karigar invoice/payment,
   job order, return and exchange dated on/before it — each refused with the plain message, no reason given
   to non-owners. Owner reopens only the latest closed year. Dashboard reminder shows to owner only; "Not
   now" hides it for the month.
3. **Back-dating.** A purchase dated in a finished year: needs a reason and `books.backdate`; cashier sees no
   option; the audit log shows the reason.
4. **Credit-note deadline.** A return against a bill whose year's 30 November has passed is refused with the
   old-gold purchase suggestion (web and app message).
5. **Sales figures.** Cancel a bill from an earlier day: its own day/month keeps it, today's figures drop by
   the reversal; GST and HSN monthly totals net of credit notes; dashboard, sales register and reports agree.
6. **Stock and year-end.** Add an item with cost; the cost cannot be edited later; `/inventory/missing-costs`
   (owner/manager with See Shop Money only) fills a missing one once. `/books/year-end`: checklist, snapshot,
   reconstructed label for early years. Year reports open, filter by FY, export CSV/Excel/PDF.
7. **Permissions and tenancy.** Cashier: no Settings, no money reports, no missing-costs, no year reports
   with money; manager without See Shop Money sees no cost. Second shop sees none of shop 1's numbers,
   snapshots or reports (try shop 1 URLs/IDs while signed in to shop 2).
8. **POS desktop (1280–1920px).** Sell block reason, amount on button, Fully paid only when paid, No price
   marker + Set price link (only with inventory.edit), Clear All twice, second payment pre-fills, barcode
   Enter adds, compliance alert inside the panel.
9. **Pricing.** Save rates with a metal-less item in stock: warning flash, Pricing banner, Review scrolls to
   "Items needing a price".
10. **Settings (Codex + Claude).** All tabs at desktop and 390/320px; unsaved guard (Keep editing / Discard)
    on tab change, link and reload; validation toast; Import Data in the header; section switch on phones;
    footer links open the policy pages; no page scroll caused by the footer.
11. **Reports and exports (Codex).** Individual report screens, sorting, phone cards, export popup
    (Close/Escape return focus), `/export` reached from the Reports header, highlighted as Reports.
12. **Navigation at 900–1280px.** Sidebar opens from the menu button and closes on navigation; Stock header
    menu button; no horizontal page scroll.
13. **Tables.** On Customers, Stock, Invoices, Karigars, Dhiran lists: controls and headings stay while rows
    scroll; phone widths show cards; row numbers continue across pages.
14. **Legal pages.** `/about … /delivery-policy` load signed out and signed in; placeholders visible where
    `config/legal.php` is empty.
15. **Admin.** `/admin/2fa/manage` — expect the known 500 until fixed.

### 6.4 Must pass locally before staging

1. Full PHPUnit on the review tip (sandbox run above) **plus** the Chromium PDF tests on the host.
2. A rehearsal of all 2026-11-07..16 migrations on a restored copy of production data, including the
   trigger/function changes in `2026_11_16_100000` and `600000`, the N22/N23 pre-check queries, and a
   rollback plan; the existing invoice numbers must be unchanged after migrating.
3. Checklist items 1–7 (numbering, lock, back-dating, credit-note deadline, sales figures, stock/year-end,
   permissions and tenancy).
4. Settings unsaved guard and POS checkout on a real phone/tablet and a 1280px laptop.
5. Decide the admin 2FA 500 fix (small, separate commit) and fill `config/legal.php` business details.
6. Mobile: app tests on the pushed mobile branch and one emulator pass of POS sale → bill number and a
   refused late return; no build or OTA until the owner says.

## 7. Local acceptance result (2026-11-19)

Local only: no staging or production access, deployment, merge to main, build, OTA, real notification or OTP
work. Evidence and scripts: `jewelflow-worktrees/build-logs/acceptance-20261118/` (outside the repo;
`ACCEPTANCE-REPORT.md` there is the detailed report).

### 7.1 Exact SHAs

- Candidate tested first: web `c5c46e452c6f23a4dad80caacf4223806a54e3fe` (handoff head
  `f093ff351fc7a93e44b7596bb595df33ce822a7b`); mobile `efdf71f700dbf9eca6e6458e4ebd8977eb95fca5` (uncommitted
  Stock badge and stash left untouched).
- Review branch `review/acceptance-fixes-20261118` (on top of `f093ff35`, not merged to main). **Final code SHA
  `d23915c3b1638334a6565c9cc52c779587bce682`**; the commit that adds this section changes only this file.
  1. `fdd6e64b` admin 2FA setup/settings pages render instead of a 500.
  2. `303d5bc1` starting number can go down again before a series' first bill.
  3. `eed4eb1e` cancelling a bill from a finished financial year needs a written reason.
  4. `d23915c3` Customers list keeps one order across pages (id breaks `created_at` ties). Found by the direct
     narrow-screen check: on the acceptance data customers 19–25 were on page 1 and page 2 and seven others on
     neither, at every width. The earlier "rows 16–30" pass only read row-number labels.
- Each fix has a test that fails without it.

### 7.2 What ran on which SHA

| Check | SHA | Result |
|---|---|---|
| Migration rehearsal (synthetic `main` DB, 2 shops; N22/N23 0 rows; 22 migrations; numbers unchanged; next number by peek and real sale) | c5c46e45 | pass, synthetic only |
| Full PHPUnit on host with Chromium | c5c46e45 | 4,882; only 2 backup tests failed from a storage override, 11/11 without it |
| Full PHPUnit on host with Chromium | eed4eb1e | 4,888, 0 failures |
| Full PHPUnit on host with Chromium | **d23915c3** | **4,889, 0 failures, 1 skipped** |
| Frontend (Playwright + Chrome; previously skipped browser cases included) | c5c46e45 | 464, 0 fail (no frontend change since) |
| Checklist 1–7 acceptance tests (synthetic shops; owner/manager/cashier; DB asserted) | d23915c3 | 44/50; the 6 failures are lock wording (§7.3) |
| UI, headless Chrome emulation 1280/768/390: Customers scroll + sticky headings, Settings Keep editing/Discard, POS sale, PDF export | c5c46e45 | 23/25; the 2 failures were the narrow pagination check, redone below |
| Customers page 2 at 768 and 390 (emulation), click Next, compare card customer ids with laptop rows | d23915c3 | navigation pass; cards pass (page 1 = rows 1–15, page 2 = rows 16–30, no overlap) |
| Emulator `jf_test`, local backend, airplane mode: sale + late-return refusal | mobile efdf71f7 against web c5c46e45 | pass |

### 7.3 Owner decisions (not changed)

- **Lock wording (one decision).** A manager/cashier refused by the period lock is told "Financial lock is
  active through <date>" (`InvoiceAccountingService::assertShopLockForDate`; also `SchemeService`,
  `BulkImportService`, `ItemManufacturingService`). The recorded rule (owner 2026-10-30: never explain to a
  non-owner why something is refused; show `NotAvailable::MESSAGE`) does not mention the lock or its date, so
  it is ambiguous whether the date counts as a reason. The six acceptance assertions expecting the plain
  message stay failing until the owner decides. The refusals themselves pass: nothing is written into a locked
  day, and the same actions dated after the lock save.
- **Item cost.** Kept as recorded (owner 2026-10-31, cost shown like the web): a role without `reports.money`
  still sees cost on the item page. Separately confirmed: such a role (with `reports.view`) gets 403 on
  missing-costs, P&L, sales register, daily, GSTR-1, closing stock, trading account, inventory valuation, day
  book and cash; the owner opens P&L. The earlier assertion that hid item cost contradicted the rule and was
  rewritten.
- `config/legal.php` business details are still placeholders.

### 7.4 Not tested — gates before production

- **Rehearsal on an authorized restored production backup** of all 22 migrations, including the N22/N23
  pre-checks and unchanged invoice numbers. Only synthetic data was used; the pre-check queries alone do not
  replace this rehearsal.
- Physical phone and tablet (all widths above were emulation); real browser Back/Forward and native refresh
  prompts; Dhiran host.
- The other twelve `latest()->paginate` lists have the same tie risk as Customers had (not changed here).
