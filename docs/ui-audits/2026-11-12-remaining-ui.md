# Remaining shop UI work — read-only audit, 12 November 2026

## Outcome

The main register/theme rollout is substantially in place. Remaining work is
concentrated in secondary forms, financial detail screens, feedback layers and
older workflow screens. A broad replacement of the main pages is not warranted.

No UI/application code or business data was changed in this audit. Only this
report and its coverage inventory were added. POS, platform Admin and Dhiran
were excluded. Claude's committed changes through `9e9e73b9` remain intact.

## Scope and evidence

- Inventoried registered GET routes and the view tree, then inspected 124
  requested URLs in the signed-in local shop. Some resolve to the same page,
  are restricted by edition/state, or have no matching demo record.
- 191 recorded page visits; 123 distinct requested URLs at 1440px desktop,
  68 at 390px emulated mobile. These are layout/DOM checks, not 124 complete
  end-to-end functional tests. Representative screenshots received closer
  visual review: Staff, Scheme Enrollment, Vault Lot, Historical Entry, Invoice
  Details, Exchanges. No physical phone or synthesized touch/drag tests.
- Checked all 30 report destinations linked by Reports and all 17 Settings tabs.
- Opened stock filters, reorder creation, karigar creation, vendor creation,
  cash-entry and drawer-count dialogs, report filters/export, category creation,
  catalog custom-message editor, and the pricing-alert overlay. Cancelled or
  navigated away without saving. No payments, refunds, imports, login-mobile
  changes, emails, archive/delete/approve actions or downloads were submitted.
- The Impeccable source detector was attempted on the selected secondary view
  folders and returned `[]`. That does not certify Blade-generated UI; manual
  source and rendered evidence below takes precedence.
- Coverage inventory: [2026-11-12-remaining-ui-coverage.json](2026-11-12-remaining-ui-coverage.json).

## Technical health snapshot

This is a scoped diagnostic rating, not WCAG certification or a performance
benchmark. No P0 task blocker was established in reachable shop workflows.

| Dimension | Score | Evidence |
| --- | --- | --- |
| Accessibility | 2/4 | Unassociated labels on several financial forms; category overlay lacks dialog semantics. |
| Performance | Not scored | Profiling was outside this UI-only pass; no speed or bundle claims. |
| Theme consistency | 2/4 | Main registers consistent; secondary screens retain teal/indigo/pink/navy actions and different KPI treatments. |
| Responsive layout | 3/4 | No document-width overflow in checked mobile pages; some dense grids correctly use internal scroll. Real gestures/large text not tested. |
| Implementation integrity | 2/4 | Feedback and dialog systems remain split; financial amounts can break within digits. |

Implementation verdict: **partial pass**. The shared shop design is coherent on
primary screens, but secondary flows do not yet consistently preserve its
readability and interaction rules. No combined /20 score is claimed because
performance was not measured. Findings: **3 P1, 12 P2, 1 P3**.

## Prioritized findings

### F01 — P1: Invoice currency values split inside the number

- Page: `/invoices/1`, at 1440px desktop.
- Evidence: Wastage and discount tiles render `₹0.00` on two lines; GST can split
  too. The value occupies about 36px and 39px height rather than a single 19.5px
  line. The invoice summary uses four columns even inside its narrower column.
- Source: `resources/views/invoices/show.blade.php:293` and
  `resources/css/app.css:27186`, especially the value `overflow-wrap: anywhere`
  at `27232`.
- Impact: Financial values become harder to read, even though no calculation
  discrepancy was established.
- Fix direction: Allow fewer tiles per row based on available panel width;
  keep each currency value intact. Do not change financial values or formulas.
- Category: responsive/readability. Suggested pass: Impeccable layout/adapt.

### F02 — P1: Visible financial fields lack usable label associations

- Confirmed rendered controls: scheme payment amount/method/receipt and refund
  reason; vault correction direction/weight/reason; store-credit amount/notes;
  login-mobile fields; karigar-invoice payment split fields; many Stock
  Add/Edit fields.
- The fields have neither an associated `<label>` nor `aria-label`/
  `aria-labelledby`. Some visible captions are separate labels without `for`;
  others rely only on placeholders.
- The shared date adapter also copies only an explicit `aria-label` to its
  visible replacement input. Dates whose original control is labeled by `for`
  or `aria-labelledby` can lose their accessible name after enhancement.
- Sources: `schemes/enrollment-show.blade.php:85`,
  `vault/show-lot.blade.php:191`, `store-credit/adjust.blade.php:66`,
  `profile/mobile-change.blade.php:84`,
  `karigar-invoices/show.blade.php:1037`,
  `inventory/items/create-retailer.blade.php`,
  `inventory/items/edit-retailer.blade.php`, `resources/js/app.js:1180`.
- Impact: Screen-reader users cannot reliably identify these money/weight
  controls; placeholder labels also disappear after typing.
- Standard: WCAG 1.3.1 / 4.1.2. Add proper associations while retaining compact
  visible labels. Fix the shared date adapter only with explicit tenant scope
  and regression checks for excluded surfaces. Suggested pass: harden.

### F03 — P1: Category popups are visual overlays, not semantic dialogs

- Page: `/categories`, Add Category opened live; Add/Edit category/subcategory
  templates use the same pattern.
- Source: `resources/views/categories/index.blade.php:75`, `111`, `151`, `189`;
  open/close implementation at `235`.
- Evidence: Popup is visible and its field receives focus, but the overlay has
  no `dialog` element, dialog role, accessible dialog name or modal semantics.
  Background controls remain represented as ordinary page controls. Source
  handles initial focus and Escape, not a native modal boundary. A live keyboard
  escape was **not** established, so none is claimed.
- Impact: Visually similar to newer dialogs but inconsistent for assistive
  technology and keyboard recovery.
- Fix direction: Reuse the established native dialog pattern, including focus
  return and safe Cancel. Standard: WCAG 4.1.2; keyboard containment needs its
  own test. Suggested pass: harden.

### F04 — P2: Change Mobile presents contradictory verification state

- Page: `/profile/mobile/change`.
- Evidence in this demo: “Not set ✓ Verified”, followed by “we will email a
  6-digit code to .” and an enabled initiation form. No OTP was requested.
- Sources: `profile/mobile-change.blade.php:32` and `:44`;
  `app/Http/Controllers/MobileChangeController.php:40` derives view state only
  from the timestamp, while request handling requires both email and timestamp.
- Impact: Misleading/dead-end instructions. This is not evidence of an account
  takeover: the server separately refuses a missing email.
- Fix direction: Align displayed eligibility with server conditions; clear
  guidance back to Profile, one title instead of repeated headings, compact
  labeled fields. Suggested pass: clarify/harden/layout.

### F05 — P2: Non-Settings toasts and validation can still move the page

- Sources: `resources/css/app.css:4130` / `:4149`,
  `resources/js/app.js` toast placement; Settings alone has the new exception.
- On phones, the shared non-Settings toast reserves `--toast-space` and animates
  `.content-area` padding. This is a verified source path, not a newly triggered
  live failure on every screen.
- In-flow validation summaries also remain in Quick Bills, stock Add/Edit,
  stock purchases, schemes, EMI create, vault create, returns/recovery,
  store-credit, historical/import and report/export forms.
- Impact: Unexpected motion and inconsistent feedback after work was done to
  remove this from Profile.
- Fix direction: Separate transient feedback from actionable field errors.
  Float transient feedback without shifting layout; retain persistent inline
  field errors, important warnings and useful local dialog error summaries.
  Do not blindly convert every warning into an auto-expiring toast.
- Suggested pass: harden/animate.

### F06 — P2: Success/error flashes have duplicate delivery paths

- Sources: `layouts/app.blade.php:8`, `resources/js/toast.js:18`,
  `customers/show.blade.php:32`, `components/app-alerts.blade.php:61`.
- Shared layout emits flash meta tags for the global toast, while Customer
  Profile and pages using the default `<x-app-alerts />` can also render the
  same session message inside the body. Examples: Cashbook, Stock Items,
  Karigar Invoices and Historical screens.
- Impact: A single operation can produce two messages and a body shift. This
  is source-confirmed; no record was changed just to generate a flash.
- Fix direction: One transient notification owner; inline errors remain local.
  Suggested pass: harden/distill.

### F07 — P2: Native browser alerts/confirmations still bypass themed feedback

- Stock Details: `inventory/items/show.blade.php:730` / `:740` uses native
  `alert('Share link copied.')`, including its clipboard fallback.
- Stock Add/Edit photo validation: `create-retailer.blade.php:892` / `:901`,
  `edit-retailer.blade.php:777` / `:786` uses native blocking alerts for oversized
  files/excess image counts.
- Purchase Details: `inventory/purchases/show.blade.php:14`, `:23`, `:33` uses
  button `onclick=confirm`, not form `onsubmit`.
- Return approval: `returns/approve.blade.php:267` / `:296`; EMI draft cancel:
  `installments/create.blade.php:245`; shop-open toggle:
  `dashboard.blade.php:4206` have similar direct handlers.
- Important false positive: ordinary form `onsubmit=confirm` is already lifted
  into the themed confirmation system by `app.js:456`; do not flag all those
  forms as native dialogs. No financial confirm was clicked in this audit.
- Fix direction: Route only the bypasses through shared themed feedback while
  retaining original confirmation semantics and financial guardrails.
  Suggested pass: harden/polish.

### F08 — P2: Scheme Enrollment is a missed secondary-page redesign

- Page: `/scheme-enrollments/1`; source `schemes/enrollment-show.blade.php:13`.
- At 1440px, the small details block stretches to match a much taller payment
  sidebar, leaving most of its card blank and pushing history below the fold.
  Teal payment action, old status/KPI treatment, placeholder-only fields and
  unindexed recent ledger differ from the refreshed scheme register.
- Fix direction: Compact header facts/KPIs, deliberate two-column information
  groups, clearly labeled payment action, separate dangerous cancellation,
  consistent history tables. Preserve payment/refund/bonus rules.
- Related `/schemes/1/enroll` already has a designed form/summary and needs
  targeted error/spacing polish, not a wholesale replacement.
- Suggested pass: layout/harden/polish.

### F09 — P2: Bullion Lot Details still uses the old visual system

- Page: `/vault/lots/1`; source `vault/show-lot.blade.php:13`.
- Large isolated KPI cards, multicolour numeric accents, a pink usage line,
  old correction footer and unindexed movement table do not match the compact
  Vault overview/ledger. Correction fields also have F02's label issue.
- Related purchase-to-vault line form:
  `inventory/purchases/vault-line.blade.php` retains its earlier teal/amber
  selection cards and layout. **Source-only** review: the chosen demo purchase
  line was an ornament and correctly failed its bullion-only precondition.
  Its 422 is not reported as a broken reachable purchase action.
- Fix direction: Match current Vault compact facts/KPIs, clear movement history
  and scoped adjustment card; preserve all weights and ledger rules.
- Suggested pass: layout/polish.

### F10 — P2: Add/Edit Staff remains visually older than Settings

- Pages: `/staff/create`, `/staff/3/edit`; sources `staff/create.blade.php:17`,
  `staff/edit.blade.php:17`.
- Add Staff has a narrow centered form, long vertically stacked identity fields,
  a detached quota strip, an older dark primary action and large internal gaps.
  At 390px it fits but requires considerable scrolling before the footer.
- Fix direction: Group identity/contact and security/role controls compactly;
  preserve clear role selection, permissions and independent save actions.
  They are separate routes today; converting them to popups would be a new
  implementation choice, not an already-approved requirement of this audit.
- Suggested pass: layout/polish.

### F11 — P2: Store Credit adjustment needs the same compact form treatment

- Page: `/customers/1/store-credit/adjust`;
  source `store-credit/adjust.blade.php:14`.
- Full separate warning, balance card and form with oversized amount input;
  green primary action and duplicate header/footer Cancel placement. The warning
  is important and must not disappear into a transient toast.
- Fix direction: Compact balance/facts, appropriately sized amount, prominent
  persistent audited-action explanation, orange primary and full-width footer.
  Keep debit protection and confirmation intact. Suggested pass: layout/harden.

### F12 — P2: Historical entry/import workflow needs a dedicated precision pass

- Pages: `/historical`, `/historical/manual`, `/historical/upload`.
- At 1440px, manual line-entry selectors and totals are squeezed into narrow
  table columns; financial/detail sections have competing density and older
  visual accents. A search field uses an unthemed rectangular border.
- Source: `historical/_manual-form-fields.blade.php`,
  `historical/_manual-item-advanced-fields.blade.php`,
  `historical/manual.blade.php:37`.
- Batch mapping, preview, duplicate review, document corrections, attachment
  and publish/rollback states remain **source-only candidates** because no demo
  imports/documents exist. Do not create/publish/rollback imports for UI review.
- Bulk Imports has already received custom layout work: polish its upload
  labeling, feedback and history table rather than classifying it as untouched.
- Suggested pass: layout/harden, then verify real draft states with safe fixtures.

### F13 — P2: Exchanges register and search/filter patterns remain inconsistent

- `/exchanges`: old inline filter strip, separate Apply and Clear, indigo primary
  (`rgb(79,70,229)` rendered), loose KPI badges and a basic empty state rather
  than the current register header/filter-panel pattern.
- Source: `returns/exchanges/index.blade.php:31`, `:56`, `:79`.
- Schemes, Purchases and Customer/Loyalty search still use the expandable search
  icon pattern; Vendors/Stock use a persistently visible compact search.
  Sources: `schemes/index.blade.php:55`, `inventory/purchases/index.blade.php:51`,
  `customers/index.blade.php:82`, `:359`.
- Return approval/recovery and exchange creation/settlement need a state-specific
  polish pass. This demo's invoice is cancelled and return settled, so several
  GETs correctly redirect; these states were **not** falsely marked broken.
- Suggested pass: layout/adapt/polish.

### F14 — P2: Non-report tables still lack the agreed row index treatment

- Confirmed DOM: Scheme enrollment ledger, Scheme detail enrollments, Vault
  Ledger and lot movement history, Karigar Invoice line/payment tables,
  Import History, Returns register, Settings billing/pricing tables.
- Thirty linked report pages themselves now have indexes; no need to redo them.
- Fix direction: Add indexes only to screen record tables where useful; update
  spans/empty rows/footer alignment together. Editable grids and print/invoice
  documents need deliberate exceptions, not a global injected column.
- Sources: `schemes/enrollment-show.blade.php:226`, `schemes/show.blade.php`,
  `vault/ledger.blade.php`, `vault/show-lot.blade.php:117`,
  `karigar-invoices/show.blade.php`, `imports/index.blade.php`,
  `returns/inbox.blade.php`, `settings.blade.php`,
  `partials/settings/pricing-tab.blade.php`.
- Suggested pass: polish/harden with structural table assertions.

### F15 — P2: Vendor address editor does not reuse the established address UX

- Pages: Add/Edit Vendor popup; source `vendors/_editor-form.blade.php:33`.
- City and State remain plain text fields and no PIN-assisted group is present,
  unlike the Karigar searchable-state/PIN lookup pattern the owner requested.
- Fix direction: Reuse the same component when this form is next refined;
  preserve existing saved free-text addresses and provide manual fallback.
  Do not silently overwrite city/state from ambiguous lookup results.
- Suggested pass: harden/polish.

### F16 — P3: Recovery/error screens retain separate hard-coded styling

- Sources: `errors/401`, `403`, `404`, `419`, `429`, `500`, `503` views and
  opening-setup pending/in-progress variants.
- 403/404 were seen live; other states reviewed as templates only. Recovery
  buttons use a hard-coded navy treatment and large isolated icon/title layout.
- Fix direction: A small shared error/recovery surface with current buttons,
  readable guidance and appropriate Back/Dashboard actions. Do not intentionally
  expire tokens or fail the server to obtain screenshots.
- Suggested pass: clarify/polish.

## What's already working — preserve it

- Checked desktop/mobile page widths remain contained. Horizontal movement in
  Job Receive's wide editable grid is inside its scroll region, not a broken
  full-page mobile layout.
- Stock's filter sheet is bottom-aligned at 390px; current reorder, vendor and
  karigar editors remain within viewport. Report filters and export also open
  as bottom sheets on mobile.
- Cashbook manual-entry and drawer-count dialogs load without submitting;
  counting explicitly says it does not adjust the ledger.
- Catalog custom-message editor uses plain-language copy and shows its public
  product link in preview. No raw template-token editor is shown.
- Settings retains all 17 sections, its internal navigation and bottom main
  Save actions. Profile remains compact/two-column where space permits.
- All 30 linked reports loaded and had indexed tables where rendered. No
  document-width overflow was found at 1440px or 390px. This is not a financial
  correctness certification or proof of populated totals for empty reports.

## Remaining states that need fixtures or a different edition

- Active/defaulted/completed EMI detail/payment/receipt; this demo has no plan.
- Pending return approval, melt recovery and populated exchange settlement.
- Historical batch/map/preview/document and import result/error screens.
- Opening-balance setup's multi-step states: demo is already “Started clean”.
- Purchase-to-vault line: requires a confirmed bullion-reserve line, not ornament.
- Manufacturer Gold Inventory and Product Master create/edit/details: current
  retailer account is edition-restricted. Source inventory only, not live-tested.
- Empty/full/long-list variations, long names/large amounts/translated text,
  keyboard traversal of every popup, physical mobile interactions and actual
  success/failure flashes after write actions were not exhaustively tested.
- Guest/auth, public shopfront, printed/PDF documents are separate surface
  families. Do not force their layouts into the logged-in register design.
- `/inventory/items/pricing-alerts` direct navigation was browser-client blocked.
  Its visible pricing-alert overlay was inspected from Stock instead; no
  workaround was used to bypass the blocked navigation.
- Old `report_*`/`reports/*` templates, legacy loyalty views, pending-stock views
  and redirect-only category routes must be checked for active routing before
  any redesign. A file's presence alone does not make it an unfinished page.

## Recommended implementation order

1. Harden shared financial readability/accessibility: invoice amounts, proper
   field labels, visible date labels and category dialog semantics (F01–F03).
2. Unify feedback without layout shift or duplicate flashes; replace only native
   bypasses and fix Change Mobile's contradictory state (F04–F07).
3. Refine Scheme Enrollment, Bullion Lot, Staff and Store Credit (F08–F11).
4. Historical/import and Exchanges flow precision pass with safe draft fixtures
   for missing states (F12–F13).
5. Finish table indexes, address consistency and recovery screens (F14–F16).
6. Impeccable polish: compare desktop, tablet, narrow phone and keyboard use;
   re-run the audit after each batch rather than declaring all pages finished.

These are recommendations, not authorization to change financial rules, create
records, broaden into POS/Admin/Dhiran, or redesign every surface at once.
