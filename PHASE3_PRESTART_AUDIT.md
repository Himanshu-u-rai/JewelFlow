# Phase 3 Pre-Start Audit — Phase 2 Compliance Verification Sweep

> **Verification only. No code changed.** Every finding is backed by route /
> controller / ReportDefinition / dataset / test / file-level evidence.
> **Date:** 2026-06-05 · **Branch:** `feature/report-export-architecture`

---

## Section 1 — Compliance route audit

All five report **screens** resolve to the spine `ReportScreenController@show` (evidence: `php artisan route:list`, `routes/web.php` L426–437).

| Report | Route name · URL | Screen controller | ReportDefinition (class / profiles) | Dataset | PDF | Excel | CSV | Export audit | Permission |
|---|---|---|---|---|---|---|---|---|---|
| GST Report | `report.gst` · `/report/gst` | `ReportScreenController@show` (`report=gst`) | `compliance` / `[fixed]` | `GstReportDataset` | spine | spine | spine | `report_exports` | `can:reports.view` |
| GSTR-1 | `report.gstr1` · `/report/gstr1` | `ReportScreenController@show` (`gstr1`) | `compliance` / `[fixed]` | `Gstr1Dataset` | spine | spine | spine | `report_exports` | `can:reports.view` |
| GSTR-3B | `report.gstr3b` · `/report/gstr3b` | `ReportScreenController@show` (`gstr3b`) | `compliance` / `[fixed]` | `Gstr3bDataset` | spine | spine | spine | `report_exports` | `can:reports.view` |
| Credit Note Register | `report.cn-register` · `/report/cn-register` | `ReportScreenController@show` (`cn-register`) | `compliance` / `[fixed]` | `CreditNoteRegisterDataset` | spine | spine | spine | `report_exports` | `can:reports.view` |
| Day Book | `report.day-book` · `/report/day-book` | `ReportScreenController@show` (`day-book`) | `accounting` / `[summary,detailed,ca,ca_standard]` | `DayBookDataset` | spine | spine | spine | `report_exports` | `can:reports.view` |

- Registry-confirmed (runtime): every definition reports `formats=[pdf,excel,csv,screen]`. "spine" PDF/Excel/CSV = produced by `ExportController` → `ExportPipeline` → `RendererFactory` (Section 8).
- Export = `reporting.export.panel` (GET `/reports/{report}/export`) + `reporting.export` (POST), gated in `ExportRequest` (`reports.export` / `reports.export_sensitive`).

**Flag — legacy screen reaching:** **NONE.** No route points at a legacy compliance *screen* method.

---

## Section 2 — Legacy surface audit

| Assertion | Result | Evidence |
|---|---|---|
| No active compliance route serves a legacy GST screen | ✅ PASS | `routes/web.php`: `report.gst` → `ReportScreenController`; `GstController` has **0** route references |
| No active compliance route serves a legacy GSTR screen | ✅ PASS | `report.gstr1/gstr3b` → `ReportScreenController`; no `TaxReportController@gstr1/gstr3b` route |
| No active compliance route uses browser-print | ✅ PASS | repointed screens render `reporting.reports.generic-screen` (no `window.print`) |
| No active compliance route depends on `window.print` | ✅ PASS | the two compliance `window.print` views (`reports/gst.blade.php`, `reports/tax/gstr3b.blade.php`) are rendered only by **unrouted** `GstController@index` / `TaxReportController@gstr3b` |
| No duplicate user-facing compliance entry points | ⚠️ PARTIAL | see residual legacy CSV note below |

**Active / orphaned / retired (compliance):**

| Component | State | Evidence |
|---|---|---|
| `ReportScreenController` (spine screen) | **ACTIVE** | serves all 5 routes |
| 5 dataset classes (`Gst*/CreditNoteRegister/DayBook`) | **ACTIVE** | registered + routed via spine |
| `GstController` | **ORPHANED** (cleanup candidate) | 0 route refs |
| `reports/gst.blade.php` (`window.print`) | **ORPHANED** | rendered only by unrouted `GstController@index` |
| `reports/tax/gstr1.blade.php`, `gstr3b.blade.php` (`window.print`), `cn-register.blade.php` | **ORPHANED** | rendered only by unrouted `TaxReportController` screen methods |
| `reports/day-book.blade.php` | **ORPHANED** | rendered only by unrouted `ReconciliationReportController@dayBook` |
| `TaxReportController@gstr1/gstr3b/creditNoteRegister` (screen methods) | **ORPHANED methods** (class ACTIVE for CSV) | no screen route; `gstr1Csv`/`creditNoteRegisterCsv` still routed |
| `ReconciliationReportController@dayBook` (screen method) | **ORPHANED method** (class ACTIVE) | no screen route; `dayBookCsv` + non-compliance reports still routed |
| `report.gstr1.csv`, `report.cn-register.csv`, `report.day-book.csv` (legacy machine-CSV) | **ACTIVE (residual)** | `routes/web.php` L429/432/437 → legacy `*Csv` methods |

**Residual legacy CSV note (the only non-spine compliance path):** three legacy machine-CSV endpoints remain reachable by direct URL (auth + `can:reports.view`). They are **not** linked from any served view (their parent screens are orphaned), are **machine-CSV (not browser-print)**, and are intentionally retained because `TaxExportGoldenTest` pins their byte-stable output. They **bypass the spine renderer and write no `report_exports` audit row.** Classification: non-blocking cleanup candidate (Section 9). GST and GSTR-3B have **no** legacy CSV route at all.

---

## Section 3 — Navigation audit

All compliance navigation links by **route name**, which now resolves to the spine (no template change needed).

| Surface | Evidence | Lands on |
|---|---|---|
| Sidebar nav | `layouts/app.blade.php` L332–352 and L424–444 → `route('report.gst'|'gstr1'|'gstr3b'|'cn-register'|'day-book')` | spine |
| Dashboard command palette | `dashboard.blade.php` L3557 → `route('report.gst')` | spine |
| Quick links / internal report links | the cross-link `reports/tax/gstr1.blade.php` L134 → `report.cn-register` lives **inside an orphaned view** (not user-reachable) | n/a |

**No compliance navigation lands on a retired screen.** (Confirmed: every nav `route()` name now maps to `ReportScreenController`.)

---

## Section 4 — Report registry audit

Runtime evidence (`ReportRegistry::keys()`): `sales-register, gst, gstr1, gstr3b, cn-register, day-book` — **count 6, unique 6**.

| Check | Result | Evidence |
|---|---|---|
| All five in `ReportDefinition` registry | ✅ | registry resolves each `definition()` with correct class/profiles |
| All five in `ReportingServiceProvider::boot()` | ✅ | `ReportingServiceProvider.php` L52–64, each `if (! $registry->has(KEY)) register(...)` |
| Dataset registry (key → dataset service) | ✅ | `datasetService(key)` resolves each `*Dataset` class |
| No missing registration | ✅ | all 5 present |
| No duplicate registration | ✅ | unique==count; `register()` throws on duplicate, guarded by `has()` |
| No shadow definitions | ✅ | one class per key; `ReportRegistry::resolveDefinition` asserts `definition->key === key` |

---

## Section 5 — Export coverage audit

Every definition declares `formats=[pdf,excel,csv,screen]` (Section 1). The spine produces all three file formats through `RendererFactory` (`pdf→PdfRenderer`, `excel→ExcelRenderer`, `csv→CsvRenderer`). Export-audit row written by `ExportAuditService::write()`.

| Recorded field | Source | Evidence |
|---|---|---|
| report key | `$request->definition->key` | `ExportAuditService.php` L92 |
| profile | `$request->profile->value` | L94 |
| format | `$request->format->value` | L96 |
| user | `$request->userId` | L91 |
| timestamp | `Carbon::now()` → `generated_at` | L103 |
| (also) report_version, sensitive_included, mode, status | — | L93/98/100 |

Verified by `ExportAuditTest` (sync/queued/failed each write exactly one row; provenance immutable).

---

## Section 6 — Permission audit

| Gate | Where | Evidence |
|---|---|---|
| `reports.view` | route middleware on all 5 (`can:reports.view`) + `ReportScreenController` re-check | `routes/web.php` L426–437 |
| `reports.export` | `ExportRequest::authorize()` | export panel/POST |
| `reports.export_sensitive` | `ExportRequest` (forged sensitive → 403) + `ColumnPolicy` (drops sensitive) | `ColumnPolicyTest` (8 cases) |
| compliance-specific gate | none required — compliance reports have **no sensitive columns** (`hasSensitiveColumns()===false`, `ComplianceReportsTest`) | — |

| Scenario | Result | Test |
|---|---|---|
| Authorized user (`reports.view`) opens report | ✅ 200 | `Phase2SignoffTest::test_legacy_report_urls_now_serve_the_spine...` |
| Unauthorized user (no `reports.view`) | ✅ 403 | `Phase2SignoffTest::test_permission_still_required` |
| Tenant isolation (download) | ✅ foreign tenant 404, signature⊕auth⊕permission | `ExportDownloadAuthzTest` (5 cases) |

---

## Section 7 — Reconciliation audit

No Phase 2 report lost reconciliation coverage during the sign-off migration (the spine datasets are unchanged; only the screen routes were repointed).

| Report | Reconciliation / parity / dataset coverage |
|---|---|
| GST Report | `ComplianceReportsTest` (totals == `GstReportingService`), `GstReportingTest` (updated, still drives the figures) |
| GSTR-1 | `ComplianceReportsTest` (b2b+b2cs == GST report), `TaxServiceTest` (b2b/b2cs split reconcile) |
| GSTR-3B | `ComplianceReportsTest` (outward/net), `TaxServiceTest` (net = output − returns) |
| Credit Note Register | `ComplianceReportsTest` (== `credit_notes` + GST reversals), `TaxServiceTest` (cn_type) |
| Day Book | `ComplianceReportsTest` (salesTotal == Σ sale credits; running balance) |
| Cross-report | `ComplianceReportsTest`: GST `total_gst` == GSTR-1 `totalGst` == GSTR-3B `outwardGst` |
| All-format parity | `ComplianceReportsTest` (each report × [pdf,excel,csv] byteSize>0) |

`TaxExportGoldenTest` (legacy CSV byte-stable) still passes.

---

## Section 8 — Export document audit

Single PDF chain, no per-report path:
- `RendererFactory.php` L29: `ExportFormat::Pdf => $this->pdf` (the one `PdfRenderer`).
- `PdfRenderer.php` L25: `VIEW = 'reporting.layouts.report-document'`; L61: `$this->engine->convert($html)`.
- `ReportingServiceProvider.php` L31: `HtmlToPdf::class` bound to `ChromiumPdfService`.

| Check | Result | Evidence |
|---|---|---|
| `PdfRenderer` used for all 5 | ✅ | factory has no per-report branch |
| `ChromiumPdfService` used | ✅ | `HtmlToPdf` binding |
| `report-document` layout used | ✅ | `PdfRenderer::VIEW` |
| No browser-print / screen-print dependency | ✅ | `Phase2PdfDocumentTest` renders real `%PDF-1.4` for all 5 and asserts `assertStringNotContainsString('window.print')`; samples in `storage/app/reporting-samples/` |

---

## Section 9 — Dead code & cleanup map (diagnosis only — nothing deleted)

| Item | Classification | Notes |
|---|---|---|
| `app/Http/Controllers/GstController.php` | **ORPHANED — cleanup candidate** | 0 route refs |
| `reports/gst.blade.php` | **ORPHANED — cleanup candidate** | `window.print`; only renderer is unrouted `GstController@index` |
| `reports/tax/gstr1.blade.php`, `gstr3b.blade.php`, `cn-register.blade.php` | **ORPHANED — cleanup candidate** | only renderers are unrouted `TaxReportController` screen methods |
| `reports/day-book.blade.php` | **ORPHANED — cleanup candidate** | unrouted `ReconciliationReportController@dayBook` |
| `TaxReportController@gstr1/gstr3b/creditNoteRegister` | **ORPHANED methods** | class itself **ACTIVE** (CSV methods + nothing else compliance) |
| `ReconciliationReportController@dayBook` | **ORPHANED method** | class **ACTIVE** (serves Phase 3/4 reports + `dayBookCsv`) |
| `report.gstr1.csv` / `report.cn-register.csv` / `report.day-book.csv` | **ACTIVE (residual legacy)** | machine-CSV; pinned by `TaxExportGoldenTest`; not user-linked; **bypasses spine audit** |

No route, view, or controller was deleted. Recommended cleanup is a **Phase 3 legacy-retirement** step, not a Phase 2 blocker.

---

## Section 10 — Phase 3 readiness

1. **Can Phase 3 begin safely?** — **YES.** The spine is the sole compliance screen + PDF path; full suite at baseline (84 pre-existing failures unchanged, 540 passed).
2. **Any compliance migration tasks still open?** — None required for sign-off. Optional, non-blocking: delete orphaned `GstController` + the 5 legacy compliance Blade views, and retire the 3 residual legacy CSV routes (Phase 3 cleanup).
3. **Any legacy compliance paths still reachable by users?** — **No screen / browser-print path.** Three legacy **machine-CSV** endpoints are reachable by direct URL only (not nav-linked); they are not browser-print and are golden-test-pinned.
4. **Any compliance report bypassing the spine?** — Screens & PDF: **no.** The 3 residual legacy CSV endpoints bypass the spine renderer + audit (documented; non-user-facing).
5. **Any blocker to Phase 3?** — **None.**

### Verdict: **GO**

Evidence: all 5 screen routes → `ReportScreenController` (spine); registry has 6 unique reports, no duplicates/shadows; PDF via `PdfRenderer`→`ChromiumPdfService`→`report-document` (real `%PDF` samples, no `window.print`); permissions enforced (200 authorized / 403 unauthorized / tenant-isolated download); export audit records key/profile/format/user/timestamp; reconciliation + parity coverage intact. Reporting suite **171 passed (770 assertions)**; key audit suite **21 passed (219)**; full suite **540 passed, 84 pre-existing failures unchanged**.

**One transparency item (non-blocking):** the 3 residual legacy machine-CSV endpoints (`report.gstr1.csv`, `report.cn-register.csv`, `report.day-book.csv`) remain active and bypass the spine audit. Retiring them is a Phase 3 cleanup decision, not a Phase 2 blocker.

---

*Diagnosis only. No code, routes, or views were modified during this audit.*
