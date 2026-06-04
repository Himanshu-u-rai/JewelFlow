# JewelFlow — Report & Export Implementation Plan

> **Status: EXECUTION PLAN.** This converts the **frozen** architecture in `REPORT_EXPORT_ARCHITECTURE_PLAN.md` (READY TO FREEZE, Addendum C §32) into a phased build plan. **No architecture is reopened, no decisions revisited.** Every section cites the frozen source it implements. No code, no migrations applied, no commits are part of this document.
>
> **Date:** 2026-06-04
> **Frozen source of truth:** `REPORT_EXPORT_ARCHITECTURE_PLAN.md` §0–§32.
> **Rollout order (frozen §12):** Phase 0 spine → Phase 1 Sales/Invoice Register pilot → Phase 2 Compliance → Phase 3 remaining Accounting → Phase 4 Operational/Owner/Audit/Dhiran + Export Center.

---

## Conventions used by this plan (from codebase scan)

- Reporting services live under **`app/Services/Reporting/`** (matches existing `app/Services/Mobile/`, `app/Services/PricingEngine/` nesting).
- Reporting controllers extend the existing **`app/Http/Controllers/Reporting/`** namespace.
- Excel via **`maatwebsite/excel ^3.1`** (already in `composer.json`, currently unused by reports — frozen §1.1/§5.2).
- Permissions seed through **`database/seeders/RolesAndPermissionsSeeder.php`** + a dated migration (matches `2026_05_08_200000_add_core_permissions.php`).
- Tests: **PHPUnit** (`phpunit.xml`, `tests/Feature`, `tests/Unit`, `tests/TestCase.php`).
- Reconciliation/validation commands follow the existing **`app/Console/Commands/ValidateReportTotals.php`**, **`ReconcileVaultBalances.php`** pattern.
- **Queue is `sync` today** (`.env QUEUE_CONNECTION=sync`); the queued-export path (frozen §20) requires a real queue worker as an infra prerequisite — flagged per phase, not assumed.
- Tenant scoping is automatic via the global shop scope (frozen §1.3) — every query and audit row inherits it; never hand-filter `shop_id`.

---

## Cross-phase invariants (enforced in every phase)

These are the frozen rules that the tests and reconciliation checkpoints assert at **every** phase, not once:

1. **Single dataset, no renderer re-query** (frozen §3.1). A renderer consumes the `ReportDataset` output only. Screen total = PDF total = Excel total = CSV total, to the paisa/milligram.
2. **Sensitive gate enforced at export time, not just UI** (frozen §7, §13, §28). A preset or signed link can never bypass `reports.export_sensitive`.
3. **Compliance is rigid** (frozen §9): no column toggles, no profile choice, fixed layout.
4. **Every export stamps the provenance block** (frozen §15) and **writes one `report_exports` audit row** (frozen §16) — including queued and failed exports.
5. **No exported file is ever stored long-term** (frozen §16); queued files are transient with 7-day signed expiry (frozen §20).
6. **Branch stays a dormant hook** (frozen §3.2, §12) — reserved dataset dimension + filter slot, never rendered.
7. **Queued-file download is authorized, not bearer-only** (resolves H-3). The signed URL is necessary but **not sufficient**: the download route additionally requires an authenticated session, asserts the file's owning `shop_id` equals the session tenant, and re-checks the originating report permission (incl. `reports.export_sensitive` if the file contains sensitive columns). Signature ⊕ authz, never signature alone. A leaked/forwarded/guessed link cannot pull another tenant's (or anyone's) export.
8. **Heavy datasets eager-load; query count is bounded** (resolves M-2). Every dataset that joins per-row relations (Sales Register line detail; Metal Movement Ledger from/to-lot, item, invoice, user; Dhiran loans + pledged items; receivables + customer) **must eager-load** and run in O(1) queries regardless of row count. Each report's parity test asserts a query-count ceiling, not just total equality.

---

# PHASE 0 — Build the spine

**Goal (frozen §12 Phase 0):** ship the shared machinery with **no user-visible report change**. Nothing is wired to a live report yet; Phase 0 ends with the spine unit-tested behind the existing reports, which continue to render exactly as today.

### 0.1 Files / modules affected

**New — metadata & contracts**
- `app/Services/Reporting/Definition/ReportDefinition.php` — declarative metadata value object: `key`, `version`, `classification`, `columns[]` (key/label/type/tier), `profiles[]`, `filters[]`, `permissions`, `formats[]`, optional `pdfTemplate` override (frozen §3.1, §15, Addendum B §24).
- `app/Services/Reporting/Definition/ReportRegistry.php` — registry that resolves a report key → `ReportDefinition`; reports self-register. Code-defined, version-controlled (frozen §15 "versions live in ReportDefinition metadata, not a DB table").
- `app/Services/Reporting/Definition/ColumnTier.php`, `ReportClassification.php`, `ReportProfile.php` — enums for the three column tiers (frozen §7.1), five classes + Receivables/Dhiran (frozen §11, §22), and the profile set Summary/Detailed/CA/**CA Standard**/Raw/Fixed (frozen §8, §18).

**New — dataset contract**
- `app/Services/Reporting/Dataset/ReportDataset.php` — canonical RowSet: `sections[]`, resolved ordered `columns[]`, typed `rows[]`, `totals`/`subtotals`, `meta` (filters applied, period, shop, generated_by/at) (frozen §3.1). **Presentation-agnostic typed values** (frozen §3.1 rule).
- `app/Services/Reporting/Dataset/ReportDatasetService.php` — abstract base: contract `build(ReportRequest): ReportDataset`. One concrete subclass per report (none built in Phase 0).
- `app/Services/Reporting/Dataset/ReportRequest.php` — resolved filters + profile + column selection + format + requesting user.

**New — renderer interfaces + base renderers**
- `app/Services/Reporting/Render/ReportRenderer.php` — interface `render(ReportDataset, ReportRequest): RenderedOutput`.
- `app/Services/Reporting/Render/ScreenRenderer.php` — adapts dataset → Blade paginated view data.
- `app/Services/Reporting/Render/PdfRenderer.php` — Chromium document renderer (frozen §4.1); renders the shared `report-document` Blade print template, not the screen.
- `app/Services/Reporting/Render/ExcelRenderer.php` — maatwebsite typed writer, one sheet per section + Report Info sheet (frozen §5.2).
- `app/Services/Reporting/Render/CsvRenderer.php` — clean single-table/ZIP writer, raw values (frozen §5.3).
- `app/Services/Reporting/Render/ChromiumPdfService.php` — wraps the installed headless Chromium (`--no-sandbox --disable-dev-shm-usage`, frozen §4.1, §13); sync + queued entry points; concurrency cap (frozen §20).
- `resources/views/reporting/layouts/report-document.blade.php` — the mandatory header/footer furniture layout (frozen §4.2): shop legal name, address, GSTIN, report name, period, filters-applied echo, profile; footer generated-by/at, page X of Y, system line; provenance block (frozen §15); watermark slot (frozen §19).
- `resources/views/reporting/partials/` — provenance block, watermark, section-table partials.

**New — export panel UX**
- `app/Http/Controllers/Reporting/ExportController.php` (new, distinct from the legacy `app/Http/Controllers/ExportController.php` which stays untouched until Phase 4) — serves the export panel + handles export requests through the spine.
- `resources/views/reporting/export-panel.blade.php` — pre-filled, scope-editable panel (frozen §6.1): FY-first presets (frozen §17), profile select, format select, column toggles within catalogue, sensitive toggles (hidden/disabled without permission, frozen §7.2).
- `app/Http/Requests/Reporting/ExportRequest.php` — validates filter scope, profile, columns, format; rejects sensitive columns when the user lacks `reports.export_sensitive` (server-side gate, frozen §7.2/§28).

**New — services (cross-cutting)**
- `app/Services/Reporting/FilterResolver.php` — resolves FY-first presets → explicit stamped dates (frozen §17); houses the reserved-but-unrendered branch dimension (frozen §6.2).
- `app/Services/Reporting/ColumnPolicy.php` — applies profile → default columns, then user toggles within catalogue, then the sensitive gate (frozen §7).
- `app/Services/Reporting/WatermarkPolicy.php` — derives `DRAFT`/`INTERNAL USE ONLY`/`CONFIDENTIAL`/none from classification + state + sensitive-opt-in (frozen §19); **automatic, never user-chosen**.
- `app/Services/Reporting/ExportAuditService.php` — writes a `report_exports` row per export event (frozen §16).
- `app/Services/Reporting/ExportSizeRouter.php` — estimates row-count, routes sync vs queued by band, PDF lower threshold (frozen §20).
- `app/Services/Reporting/ProvenanceStamp.php` — builds the five-field provenance block for all formats (frozen §15).

**New — queued export**
- `app/Jobs/Reporting/GenerateQueuedExportJob.php` — generates to temp storage, signed 7-day URL, notifies (frozen §20). (Mirrors existing `app/Jobs/ProcessBulkImportJob.php`.)
- `app/Http/Controllers/Reporting/ExportDownloadController.php` — the **authorized download endpoint** for queued files (resolves H-3): validates the signed URL **and** the authenticated session, asserts file `shop_id` = session tenant, re-checks the report's view/export/`reports.export_sensitive` permission, then streams the transient file. The signed URL alone never grants access.
- `app/Notifications/Reporting/ExportReadyNotification.php` — in-app + optional email link (frozen §20). The link points at `ExportDownloadController`, not at raw storage.
- `app/Console/Commands/Reporting/SweepExpiredExports.php` — scheduled deletion of expired transient files; **audit rows persist** (frozen §16, §20).
- **Scheduler registration (resolves M-1):** add `Schedule::command('reporting:sweep-expired-exports')->daily()` to `routes/console.php` (alongside the existing `backup:run`/`loyalty:expire`/`dhiran:*` daily jobs). Without this entry the 7-day expiry sweep never runs and transient files accumulate past their privacy window.

### 0.2 Migrations required

| Migration | Purpose | Frozen ref |
|---|---|---|
| `…_create_report_exports_table` | Append-only metadata audit: `shop_id`, `user_id`, `report_key`, `report_version`, `profile`, `profile_version`, `format`, `filters` (json), `row_count`, `mode` (sync/queued), `status`, `sensitive_included` (bool), `generated_at`. **No file blob column.** Reuse AuditLog immutability discipline. | §16 |
| `…_create_reporting_presets_table` | Shop-wide presets: `shop_id`, `name`, `report_key`, `profile`, `columns` (json), `filters` (json), `format`, **`scope` enum(`shop`,`user`) default `shop`**, **`owner_id` nullable**, `created_by`. Only `shop` written/exposed now (frozen §8, §21 "schema user-scope-ready"). | §8, §21 |
| `…_add_reports_export_sensitive_permission` | Insert `reports.export_sensitive` permission **and backfill it onto every existing per-shop owner/manager role**. Roles are tenant-scoped (`roles.shop_id`, `2026_02_16_160000_scope_roles_to_shop.php`); seeders do **not** run on deploy. **Backfill follows the per-shop role-grant precedent in `2026_05_15_230000_add_imports_manage_permission.php` / `2026_06_01_120000_add_returns_view_create_permissions.php` (iterate `roles` `whereNotNull('shop_id')`), NOT the create-only `2026_05_08` migration** (resolves H-2). The seeder is updated for fresh installs only. | §10, §28 |
| `…_add_reports_audit_permission` | Insert `reports.audit` permission and backfill onto **owner/manager roles only** (same per-shop pattern as above). Then **switch the route gates** of `report.operator-performance(.csv)`, `report.suspicious-activity(.csv)` from `can:reports.view` → `can:reports.audit`, and gate Dhiran `reports.forfeiture` / `reports.profitability` behind `reports.audit` (in addition to `dhiran.reports`). This is the **mechanism** that enforces the whole-surface owner/manager-only rule the frozen plan asserts but the routes do not yet implement (today they are `can:reports.view`) (resolves H-1, frozen §28, Addendum B §25). Route-gate edits land in Phase 4 when those reports migrate; the permission + backfill ship here so the gate exists before it is referenced. | §28, Addendum B §25 |

**No migration for versioning** (frozen §15 — code-defined in `ReportDefinition`). **No schema change to invoices/credit_notes/metal_movements** — the spine only reads existing data. **Migration count is now five** (the optional disabled `reports.compliance` in Phase 4 unchanged); all additive, all reversible.

### 0.3 Services required (summary)

`ReportRegistry`, `ReportDatasetService` (abstract), the four renderers + `ChromiumPdfService`, `FilterResolver`, `ColumnPolicy`, `WatermarkPolicy`, `ExportAuditService`, `ExportSizeRouter`, `ProvenanceStamp`, `GenerateQueuedExportJob`, `ExportDownloadController` (authorized download, H-3). All under `App\Services\Reporting\*` / `App\Jobs\Reporting\*` / `App\Http\Controllers\Reporting\*`.

### 0.4 Tests required

- **Unit** `tests/Unit/Reporting/ReportDefinitionTest.php` — registry resolves keys; version string present; column tiers well-formed.
- **Unit** `ColumnPolicyTest.php` — profile defaults; toggle within catalogue only; sensitive columns dropped when permission absent (frozen §7.2).
- **Unit** `FilterResolverTest.php` — every FY preset resolves to correct explicit, timezone-correct, Apr-1-based dates (frozen §17); branch dimension present but not rendered.
- **Unit** `WatermarkPolicyTest.php` — each classification/state → correct label; Compliance/CA Standard → no watermark (frozen §19).
- **Unit** `ProvenanceStampTest.php` — five fields present and identical across formats (frozen §15).
- **Feature** `ExportAuditTest.php` — a successful, a queued, and a **failed** export each write exactly one `report_exports` row with `sensitive_included` correct (frozen §16).
- **Feature** `ChromiumPdfSmokeTest.php` — renders the `report-document` layout to a valid PDF with ₹ glyph, repeating table header, page X/Y (frozen §4.2); skips gracefully where Chromium absent in CI.
- **Feature** `ExportPanelGateTest.php` — staff without `reports.export_sensitive` sees no sensitive toggles and a forged POST including them is rejected 403 (frozen §7.2/§28).
- **Architecture guard** `tests/Unit/Reporting/RendererNoQueryTest.php` — static/contract assertion that renderers depend only on `ReportDataset`, never on a model/query (frozen §3.1, §13 "renderer bypassing dataset → number drift").
- **Feature** `ExportDownloadAuthzTest.php` (resolves H-3) — a valid signed URL with **no/foreign session** is rejected; a session from another `shop_id` is rejected; a user lacking the report/`reports.export_sensitive` permission is rejected; only the authorized same-tenant user downloads. Signature alone never suffices.
- **Unit** `PermissionBackfillTest.php` (resolves H-2/H-1) — running the `reports.export_sensitive` and `reports.audit` migrations on a DB with pre-existing per-shop roles grants the permission to every existing owner/manager role (not just fresh-seed roles).

### 0.5 Rollback strategy

- Spine is **inert** in Phase 0 — no live report routes to it. Rollback = revert the feature branch; existing reports are untouched because none were rewired.
- Migrations are additive only (two new tables + two permissions). Down-migrations drop the two tables and remove both permissions (and their per-shop role grants). No data backfill to reverse.
- Chromium service is feature-flagged off until Phase 1; if PDF infra fails, the spine still builds Screen/Excel/CSV.

### 0.6 Validation checkpoints

1. `composer test` green for all Phase 0 unit/feature suites.
2. Chromium smoke test produces a byte-valid PDF on the target server (root, `--no-sandbox`).
3. `report_exports` and `reporting_presets` migrate up **and down** cleanly on a scratch DB.
4. `reports.export_sensitive` **and** `reports.audit` present after migration and attached to owner/manager on **every existing per-shop role set** (not only fresh seeds) — verified by `PermissionBackfillTest` on a multi-shop fixture (H-2/H-1).
5. `reporting:sweep-expired-exports` appears in `php artisan schedule:list` (M-1).
6. No existing report's output changed (diff a sample of current GST/closing/daily screen+print before/after — must be identical).

### 0.7 Reconciliation requirements

- **Dataset-vs-renderer parity harness** (the core §3.1 invariant): a test fixture dataset rendered to all four renderers must yield identical totals/row-counts. This harness is reused by every later phase.
- Extend the existing `app/Console/Commands/ValidateReportTotals.php` with a `--spine` mode that, given a report key, asserts dataset totals equal the screen-rendered totals. (Wired to real reports starting Phase 1.)

---

# PHASE 1 — Pilot: Sales / Invoice Register (end-to-end)

**Goal (frozen §12 Phase 1, Addendum C §27):** prove the spine on the single richest report — full filter set, all five profiles incl. CA Standard, optional **and** sensitive columns (mobile/operator/cost), all three formats with the formal PDF.

### 1.1 Files / modules affected

- `app/Services/Reporting/Reports/SalesRegisterDataset.php` — concrete `ReportDatasetService` for the invoice register (reads existing `Invoice`/`InvoiceItem`/payment data; **no new data**). Classification ACCOUNTING (Addendum C §27).
- `app/Services/Reporting/Reports/SalesRegisterDefinition.php` — the `ReportDefinition` row from Addendum C §27: filters (period FY-first, operator, customer, metal, status, payment mode); mandatory cols (invoice no, date, customer, taxable, CGST/SGST/IGST, total GST, total, status); optional (line detail, HSN, payment split, discount, round-off, POS); sensitive (customer mobile/address, operator, cost/margin); formats P/X/C; profiles Sum/Det/CA/**CAS**/Raw.
- `app/Http/Controllers/Reporting/SalesRegisterController.php` — screen + export-panel entry; route `report.sales-register` (new spine route, Addendum C §27).
- `routes/web.php` — add `report.sales-register` (gated `reports.view`/`reports.export`); leave legacy `export.invoices` in place until Phase 4 (Addendum C §27/§29).
- `resources/views/reporting/reports/sales-register/screen.blade.php` + `document.blade.php` (PDF body using the shared `report-document` layout) + Excel/CSV mappers.
- `app/Services/Reporting/Profiles/CaStandardProfile.php` — the locked, canonical CA Standard profile (frozen §18), first consumed here.

### 1.2 Migrations required

**None.** Sales register reads existing invoice data. *Optional, non-blocking:* a covering index on `invoices(shop_id, status, created_at)` if the parity/performance checkpoint shows slow large-period queries — additive, reversible, decided by measurement not assumption.

### 1.3 Services required

`SalesRegisterDataset`, `SalesRegisterDefinition`, `CaStandardProfile`; reuses all Phase 0 cross-cutting services unchanged.

### 1.4 Tests required

- **Feature** `SalesRegisterScreenTest.php` — renders with each filter; tenant-scoped.
- **Feature** `SalesRegisterExportParityTest.php` — for a seeded shop, Screen/PDF/Excel/CSV totals + row counts are identical across all five profiles (the §3.1 invariant on the real pilot). **Also asserts a query-count ceiling** on the Detailed (line-detail) profile over a large seeded period — the dataset eager-loads and stays O(1) in queries (resolves M-2; invariant 8).
- **Feature** `SalesRegisterSensitiveGateTest.php` — cost/margin/operator/PII columns absent for `reports.export` user, present (and audit `sensitive_included=true`) for `reports.export_sensitive` user (frozen §28).
- **Feature** `SalesRegisterCaStandardLockedTest.php` — CA Standard exposes no column toggles, identical shape regardless of shop, no watermark (frozen §18, §19).
- **Feature** `SalesRegisterProvenanceTest.php` — PDF footer / Excel Info sheet / CSV sidecar carry identical five-field block and stamped resolved dates (frozen §15, §17).
- **Feature** `SalesRegisterLargeExportTest.php` — over-threshold request enqueues `GenerateQueuedExportJob`, produces a signed link, writes a `queued` audit row (frozen §20).

### 1.5 Rollback strategy

- New route + new controller only; **legacy invoice listing/export untouched** (frozen §29 "Superseded … on the spine" is deferred to Phase 4). Disable by removing the `report.sales-register` route / feature flag; users fall back to existing invoice screens.
- No migration to reverse (unless the optional index was added — drop it).

### 1.6 Validation checkpoints

1. **Queue prerequisite (hard gate, resolves M-3):** before the queued path ships to production, `QUEUE_CONNECTION` is **off `sync`** and a worker is running — verified in the deploy environment. On `sync`, `GenerateQueuedExportJob` would run inline in the web request and time out / OOM on exactly the large exports it exists to protect; until the worker exists, the size router (frozen §20) holds large exports at the synchronous row-count guardrail rather than dispatching. Phase 1 production sign-off is blocked until this is confirmed.
2. Parity test green across all five profiles and three formats, **including the query-count ceiling** (M-2).
3. CA/owner UAT on a real shop's data: a CA confirms the CA Standard PDF + Excel are filing-usable (frozen §12 "get real CA/owner feedback").
4. Sensitive gate cannot be bypassed via preset or signed link (security check, frozen §13); **download authz enforced** — `ExportDownloadAuthzTest` green (H-3).
5. Queued path produces a downloadable file that expires at 7 days; audit row survives expiry (frozen §16, §20).
6. PDF renders a multi-page register with repeating headers, no row split, correct page X/Y (frozen §4.2).

### 1.7 Reconciliation requirements

- **Source-of-truth tie-out:** Sales Register totals (taxable, CGST, SGST, IGST, total) must reconcile to the same figures the **GST report** and the **finalized `invoices` table** produce for the same period — asserted by `ValidateReportTotals --report=sales-register`. This is the proof that the dataset is authoritative before compliance reports depend on the spine.
- Parity harness (Phase 0.7) run as a release gate for the pilot.

---

# PHASE 2 — Compliance family (rigid track)

**Goal (frozen §12 Phase 2, §9, §22 COMPLIANCE):** GST Summary, GSTR-1, GSTR-3B, Credit/Debit Note Register, plus **Day Book** onto the spine — formal PDF + **prescribed CSV/Excel**, period-only filter, **no column flex** (frozen §9). Highest external-trust value.

### 2.1 Files / modules affected

- `app/Services/Reporting/Reports/Compliance/GstSummaryDataset.php`, `Gstr1Dataset.php`, `Gstr3bDataset.php`, `CreditNoteRegisterDataset.php`, `DayBookDataset.php` — wrap the **existing** dataset logic in `app/Http/Controllers/Reporting/TaxReportController.php` and `ReconciliationReportController.php` (frozen §1.3 "data already correct at source; problem is presentation"). Logic moves into dataset services unchanged; controllers delegate.
- Matching `…Definition.php` per report: classification COMPLIANCE, profile **Fixed**, no optional/sensitive toggles (frozen §9, §22). Day Book is ACCOUNTING with Sum/Det/CA/**CAS** (frozen §22) — included here because §12 groups it on the rigid-quality track.
- `resources/views/reporting/reports/compliance/*` — formal PDF document templates per report; GSTN-shaped CSV/Excel writers (frozen §5.2/§5.3, §22 req 2 "prescribed structures").
- `app/Http/Controllers/Reporting/TaxReportController.php` — rewired to spine renderers; **retire its ad-hoc `fputcsv`** for these reports (frozen §3 Phase 3 cleanup, pulled forward for the compliance set).
- `routes/web.php` — existing `report.gst`, `report.gstr1(.csv)`, `report.gstr3b`, `report.cn-register(.csv)`, `report.day-book(.csv)` repoint to spine; **route names unchanged** (no consumer breakage).

### 2.2 Migrations required

**None.** Compliance reports read existing finalized GST data (frozen §1.3). No schema change.

### 2.3 Services required

Five dataset services above; reuse Phase 0 renderers. A `app/Services/Reporting/Render/GstnCsvShape.php` helper to enforce the prescribed GSTN offline-tool column order/format (frozen §22 req 2).

### 2.4 Tests required

- **Feature** `Gstr1FormatFidelityTest.php`, `Gstr3bFormatFidelityTest.php`, `GstSummaryFormatTest.php`, `CnRegisterFormatTest.php` — output matches the prescribed/expected layout byte-structurally (golden-file assertion, frozen §13 "tests against the expected layout").
- **Feature** `ComplianceRigidityTest.php` — these reports expose **no** column/profile toggles and **no** sensitive opt-in; the export panel renders period-only (frozen §9).
- **Feature** `ComplianceNoWatermarkTest.php` — Compliance + CA Standard exports carry no watermark (frozen §19).
- **Regression** `ComplianceNumbersUnchangedTest.php` — for a seeded period, new spine output equals the **pre-migration** controller output figure-for-figure (the calculation didn't change, only presentation).

### 2.5 Rollback strategy

- Route names are reused, so rollback = repoint the route closures back to the old controller methods (kept dormant, not deleted, until Phase 2 sign-off). Old `fputcsv` methods stay in the file (commented/guarded) for one release as the fallback.
- **CSV-consumer migration risk** (frozen §13): announce the clean/GSTN-shaped CSV as the new default; provide a one-release note to CAs/scripts. If a consumer breaks, the legacy CSV method is the temporary fallback behind a query flag.

### 2.6 Validation checkpoints

1. Golden-file format tests green for all four statutory outputs.
2. A real GSTR-1 CSV imports cleanly into the GSTN offline tool (external validation, frozen §22 req 2).
3. Regression test proves numbers unchanged vs pre-migration.
4. CA sign-off that the formal PDFs are filing-reference quality.

### 2.7 Reconciliation requirements

- **GST tie-out:** GST Summary ↔ GSTR-1 ↔ GSTR-3B ↔ `invoices`/`credit_notes` must reconcile for the same period (output tax, CGST/SGST/IGST split, net liability, CN reversal). Extend `ValidateReportTotals` with a `--compliance <period>` cross-check; this is a hard release gate.
- CN Register total reversed-GST ↔ credit-note source data (ties to the existing GST/returns integrity already validated by `ValidateReturnsIntegrity`).

---

# PHASE 3 — Remaining Accounting reports

**Goal (frozen §12 Phase 3, §22 ACCOUNTING):** migrate the remaining ACCOUNTING-class reports onto the spine: Daily Closing, Cash Flow, Payment Reconciliation, Inventory Valuation, Daily (sales summary), Metal Liability, and the **Metal Movement Ledger** (Addendum C §30). Retire their legacy screen+print / `fputcsv`.

### 3.1 Files / modules affected

- `app/Services/Reporting/Reports/Accounting/` — `DailyClosingDataset.php`, `CashFlowDataset.php`, `PaymentReconciliationDataset.php`, `InventoryValuationDataset.php`, `DailySalesDataset.php`, `MetalLiabilityDataset.php`, `MetalMovementLedgerDataset.php` + matching `…Definition.php` (classes/cols/sensitive/formats/profiles exactly per frozen §22 + Addendum C §30).
- Controllers rewired: `ClosingController`, `CashReportController`, `DailyReportController`, `Reporting/ReconciliationReportController` (payment-recon, inventory-valuation), `Reporting/ReceivablesReportController` (metal-liability), `LedgerController`.
- `resources/views/reporting/reports/accounting/*` — PDF document templates (formal PDF **mandatory** for Accounting, frozen §11); Excel/CSV mappers. Legacy `report_closing.blade.php`, `report_cash.blade.php`, `report_daily.blade.php`, `ledger.blade.php` print blocks retired once spine output is signed off.
- `routes/web.php` — `report.closing` (keeps `reports.daily_closing` gate, frozen §10), `report.cash`, `report.daily`, `report.payment-reconciliation(.csv)`, `report.inventory-valuation(.csv)`, `report.metal-liability(.csv)`, `ledger.index` repoint to spine; names unchanged.

### 3.2 Migrations required

**None.** All read existing data (`cash_transactions`, `metal_movements`, `invoices`, advance-gold). Inventory Valuation/Metal Movement Ledger surface **cost/operator** as sensitive columns gated by `reports.export_sensitive` (no new column; gate is policy-level, frozen §28, Addendum C §30).

### 3.3 Services required

Seven dataset services above; reuse all renderers and cross-cutting services. No new shared service.

### 3.4 Tests required

- **Feature** per report: screen + export parity (all formats), sensitive gate (cost on Inventory Valuation; operator on Cash Flow / Ledger), provenance, FY-first filters.
- **Feature** `DailyClosingPermissionTest.php` — `reports.daily_closing` still required (frozen §10) post-migration.
- **Regression** `AccountingNumbersUnchangedTest.php` — spine figures equal pre-migration figures per report.
- **Feature** `LedgerAccountingGradeTest.php` — Metal Movement Ledger reconciles movement debits/credits and ties to vault balances (Addendum C §30).

### 3.5 Rollback strategy

- Per-report feature flag: each report flips to the spine independently; flip back to legacy controller method on any regression. Legacy print blocks kept one release as fallback.
- No migration to reverse.

### 3.6 Validation checkpoints

1. Per-report parity + regression green.
2. Daily Closing gate intact.
3. Inventory Valuation cost/margin never leaks without `reports.export_sensitive`.
4. Owner UAT on closing/cash/daily — formal PDFs replace browser-print acceptably.

### 3.7 Reconciliation requirements

- **Ledger ↔ vault:** Metal Movement Ledger fine-weight in/out must reconcile to `ReconcileVaultBalances` output for the same scope (reuse the existing command as the oracle).
- **Cash Flow ↔ cash ledger:** running balance ties to `cash_transactions`.
- **Daily Closing ↔ Sales Register/GST:** closing sales+GST for a date ties to the Phase 1 register and Phase 2 GST figures (chain consistency across phases). Hard gate via `ValidateReportTotals`.

---

# PHASE 4 — Operational / Owner / Audit / Receivables / Dhiran migration + Export Center

**Goal (frozen §12 Phase 3 "migrate the rest", §22 remaining classes, Addendum B, Addendum C §29):** bring **all** remaining surfaces onto the spine — Operational, Owner, Audit, **the rest of the Receivables class**, Dhiran — and **retire the legacy Export Center `fputcsv`**.

### 4.1 Files / modules affected

**Receivables** (frozen §22 RECEIVABLES, §11; resolves B-1 — these three were missing from the plan): `CustomerDuesAgingDataset`, `PendingEmiDataset` (retailer), `SchemeLiabilityDataset` (retailer) — rewire `Reporting/ReceivablesReportController` (`duesAging`/`duesAgingCsv`, `emi`/`emiCsv`, `schemeLiability`/`schemeLiabilityCsv`; routes `report.dues-aging(.csv)`, `report.emi(.csv)`, `report.scheme-liability(.csv)`, [routes/web.php:452-457](routes/web.php#L452)). Class RECEIVABLES; aging buckets 0–30/31–60/61–90/90+; **customer mobile/address are sensitive** → `reports.export_sensitive`-gated, CA-safe by default (frozen §22). View = `reports.view`; `report.emi`/`report.scheme-liability` keep their `edition:retailer` gate. (Metal Liability — the fourth Receivables-class report — already migrated in Phase 3.)

**Operational** (frozen §22): `DeadStockDataset`, `RepairsDataset`, `MetalExchangeDataset` (retailer), `StockAgingDataset` (retailer), `KarigarSettlementDataset` (mfr) — rewire `RepairReportController`, `MetalExchangeReportController`, `Reporting/ReconciliationReportController` (dead-stock, purchase-efficiency), `Reporting/KarigarReportController` (settlement, shrinkage), `RetailerDashboardController` (stock-aging). Excel-first (frozen §11).

**Owner** (frozen §22): `PnlDataset`, `GoldBalancesDataset`, `PurchaseEfficiencyDataset`, `ReferencePricesDataset`, `SellersDataset`, `OccasionsDataset` — rewire `PnlController`, `ReportController` (gold), `ReferencePriceHistoryController`, `RetailerDashboardController` (sellers, occasions). **View = `reports.view`; cost/margin gated by `reports.export_sensitive`** (Addendum C §28 — the resolved B4 rule; no whole-report exclusion). `CONFIDENTIAL` watermark where cost present (frozen §19).

**Audit** (frozen §22, §28): `OperatorPerformanceDataset`, `SuspiciousActivityDataset`, `ShrinkageDataset` — rewire `Reporting/AuditReportController`, `KarigarReportController`. **Whole-surface owner/manager only** — enforced by **switching the route gates from `can:reports.view` → `can:reports.audit`** on `report.operator-performance(.csv)` and `report.suspicious-activity(.csv)` ([routes/web.php:448-451](routes/web.php#L448)), using the `reports.audit` permission shipped in Phase 0.2 (resolves H-1; today they are `can:reports.view`). `INTERNAL`/`CONFIDENTIAL` labels (frozen §19). *(Shrinkage stays `reports.view`-visible — it is OPERATIONAL/AUDIT-grade but not in the §28 owner-only set.)*

**Dhiran family** (Addendum B): `DhiranActiveLoansDataset`, `DhiranOverdueDataset`, `DhiranInterestDataset`, `DhiranForfeitureDataset`, `DhiranCashbookDataset`, `DhiranProfitabilityDataset` — rewire `DhiranController` report methods. Register as a **distinct family** with KYC masking everywhere (Addendum B §23), two **bespoke PDF templates** — `LoanAccountStatement` (per-loan) and `ForfeitureNoticeRecord` (Addendum B §24). Baseline gate `dhiran.reports`; **Forfeiture + Profitability additionally gated `can:reports.audit`** (resolves H-1 for the §28 Dhiran exception) so they are owner/manager-only on top of `dhiran.reports` (Addendum B §25, §28).
- `resources/views/reporting/reports/dhiran/loan-account-statement.blade.php`, `forfeiture-record.blade.php` — bespoke document templates (Addendum B §24).
- `app/Services/Reporting/KycMaskPolicy.php` — Aadhaar/PAN mask-by-default, audited reveal, never in shareable CSV/Excel (Addendum B §23).

**Export Center** (Addendum C §29): rewire legacy `app/Http/Controllers/ExportController.php` (`export.customers/products/invoices/gold-ledger/cash-transactions/all`) through spine renderers as **Raw-profile dumps**, every PII/cost/vendor surface gated by `reports.export_sensitive`; `export.all` stays owner/manager + `edition:manufacturer`. **Retire all ad-hoc `fputcsv` and the banner-CSV format** (frozen §3 Phase 3, §13).

**Explicitly excluded (Addendum C §31):** `report.whatsapp`, `report.audit`, `report.hub`, Dhiran `reports.index` — left as-is; **not** migrated to the spine. Document the exclusion in code comments referencing §31.

### 4.2 Migrations required

- `…_add_reports_compliance_permission` *(optional, frozen §10)* — only if a shop wants to restrict compliance exports; ships disabled. Decide at phase start; not required for function.
- **No schema migration** for the route-gate switch (H-1): `reports.audit` was created + backfilled in Phase 0.2; Phase 4 only edits `routes/web.php` middleware (`reports.view` → `reports.audit` on the two Audit routes; add `reports.audit` to the two Dhiran routes). No DB change.
- **Receivables (B-1): no schema.** Customer Dues / EMI / Scheme Liability read existing installment/scheme/receivables data; customer-PII columns use the policy-level sensitive gate (no new column).
- **No other schema.** KYC masking is policy-level on existing `kyc_*` columns (Addendum B §23). Dhiran datasets read existing `App\Models\Dhiran\*` data.

### 4.3 Services required

All datasets above + `KycMaskPolicy`. Dhiran registered as a family in `ReportRegistry` (Addendum B §23 — dataset contract already serves non-sales entities per frozen §22 req 1, built into Phase 0).

### 4.4 Tests required

- **Feature** parity (incl. query-count ceiling, M-2) + sensitive gate + provenance per report (all classes).
- **Feature** `ReceivablesReportsTest.php` (resolves B-1) — Customer Dues aging buckets correct; EMI/Scheme retailer-edition gated; customer mobile/address absent without `reports.export_sensitive`, present + audited with it; all three formats parity (frozen §22 RECEIVABLES).
- **Feature** `OwnerViewNotTightenedTest.php` — Owner-class reports viewable with `reports.view`; cost/margin gated by `reports.export_sensitive` only (asserts the resolved B4 rule, Addendum C §28).
- **Feature** `AuditSurfaceOwnerOnlyTest.php` — operator-performance / suspicious-activity / Dhiran forfeiture+profitability return 403 for a `reports.view`-only staff user and 200 for an owner/manager holding `reports.audit` (asserts the actual gate switch, H-1; frozen §28 exception).
- **Feature** `DhiranKycMaskingTest.php` — Aadhaar/PAN masked by default; full value requires elevation + writes an audited reveal; never present in shareable CSV/Excel (Addendum B §23).
- **Feature** `DhiranForfeitureDocumentTest.php`, `LoanAccountStatementTest.php` — bespoke templates render with required legal fields (Addendum B §24).
- **Feature** `ExportCenterRawDumpTest.php` — each `export.*` surface produces a clean (non-banner) typed dump; PII/cost gated; `export.all` owner/manager + manufacturer (Addendum C §29).
- **Regression** `LegacyCsvRetiredTest.php` — old `fputcsv` paths no longer reachable; clean CSV is the default.
- **Guard** `ExcludedSurfacesNotOnSpineTest.php` — `report.whatsapp`/`report.audit`/hubs are not registered reports (Addendum C §31).
- **Isolation** extend existing `tests/Feature/DhiranIsolationTest.php` — Dhiran reports stay tenant- and edition-scoped.

### 4.5 Rollback strategy

- Per-report feature flags (as Phase 3). Export Center: keep legacy `fputcsv` methods one release behind a flag; flip back if a downstream CSV consumer breaks (frozen §13 risk).
- Dhiran family flag-gated independently; KYC masking defaults to **most restrictive** so a rollback never widens exposure.
- No destructive migration (the only migration is an optional disabled permission).

### 4.6 Validation checkpoints

1. Parity + regression green for every remaining report, **including the three Receivables reports** (B-1).
2. KYC masking verified by security review — no Aadhaar/PAN in any default export (Addendum B §23).
3. Audit/forfeiture surfaces return 403 for floor staff (`reports.view` only) and 200 for owner/manager (`reports.audit`) — the gate switch is live, not just asserted (H-1, frozen §28).
4. Export Center clean-CSV migration note issued; no consumer breakage in UAT.
5. Excluded surfaces confirmed untouched.

### 4.7 Reconciliation requirements

- **Receivables ↔ source (B-1):** Customer Dues aging totals tie to outstanding invoice balances; Pending EMI ties to installment-plan schedules; Scheme Liability ties to scheme balances/accrued bonus. `ValidateReportTotals --receivables` cross-check.
- **Dhiran ↔ source:** Active/Overdue/Interest/Cashbook/Profitability tie to `App\Models\Dhiran\*` loan/payment/cash-entry balances; Forfeiture ties to forfeiture records. New `ValidateReportTotals --dhiran` checks (extends the existing `ReconcileKarigarBalances`/`ValidateReportTotals` family).
- **Owner P&L ↔ accounting chain:** P&L revenue/COGS reconciles to Sales Register (Phase 1) + Inventory Valuation (Phase 3) for the period.
- **Export Center ↔ live tables:** each dump row-count and key totals reconcile to the source model counts at export time (caught in the audit row's `row_count`, frozen §16).
- Full **cross-phase reconciliation sweep** as the final freeze-to-done gate: Sales Register ↔ GST set ↔ Daily Closing ↔ Ledger ↔ vault all agree for a shared test period.

---

## Global rollback & sequencing notes

- **Strict order** (frozen §12): do not start a phase until the prior phase's validation + reconciliation checkpoints pass. Phase 1 is the spine's proof; Phase 2 depends on the dataset being authoritative (Phase 1.7 tie-out).
- **Feature flags** per report from Phase 1 onward let any single report fall back to its legacy controller without affecting others.
- **Legacy code retained one release** behind flags for every migrated report; deleted only after the next phase confirms stability.
- **Queue infra prerequisite** (frozen §20; now a **hard Phase-1 gate**, §1.6.1, resolves M-3): before Phase 1 ships the queued path to production, move `QUEUE_CONNECTION` off `sync` and run a worker; until then the size router holds large exports at the synchronous row-count guardrail rather than dispatching inline.
- **Scheduler** (resolves M-1): `reporting:sweep-expired-exports` is registered in `routes/console.php` and verified via `schedule:list` (Phase 0.6.5).
- **Chromium infra** (frozen §13): pinned binary, ₹-capable embedded font, `--no-sandbox --disable-dev-shm-usage`, concurrency cap — validated in Phase 0.6, monitored each phase.

## Definition of done (whole programme)

**Every** route/controller/export surface from the frozen matrix (§22 incl. the full RECEIVABLES class, + Addendum C §27/§29/§30) — Sales/Invoice Register, Compliance set, all Accounting, Owner, Operational, Audit, **Customer Dues / Pending EMI / Scheme Liability / Metal Liability**, Dhiran family, and the six Export Center surfaces — renders through the shared dataset+renderer spine; legacy `fputcsv` and screen-capture print are retired; all parity (incl. query-count ceilings), sensitive-gate, **download-authz**, provenance, watermark, versioning, and export-audit invariants hold; the **whole-surface owner/manager gate (`reports.audit`)** is live on the §28 Audit/Dhiran exception set; the scheduled expiry sweep runs; the cross-phase reconciliation sweep is green; excluded surfaces (§31) are documented and untouched.

---

*End of implementation plan. No code, schema, routes, templates, or exports were modified; nothing committed. Execution plan only, derived strictly from the frozen `REPORT_EXPORT_ARCHITECTURE_PLAN.md`.*
