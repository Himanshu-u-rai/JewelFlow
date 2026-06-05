# Phase 2 Sign-off Completion (Option B)

> **Classification:** completion of the Phase 2 compliance rollout — NOT Phase 3 scope.
> **Goal:** the user-facing GST compliance reports now use the approved spine
> (report-document architecture + Chromium PDF), not the legacy browser-print
> screens. Scope strictly limited to the 5 compliance-family reports.
>
> **Date:** 2026-06-05

---

## 1. Repoint map (old route → new destination → legacy component retired)

Each route keeps its **URL, name, and `can:reports.view` permission**. No redirect:
the same URL now serves the spine `ReportScreenController` via `->defaults('report', KEY)`.
Because the nav (`layouts/app.blade.php` sidebar + dashboard command palette) links by
**route name**, every menu entry now lands on the spine automatically.

| Old route (name · URL) | Old action (legacy) | New destination | Legacy component retired (now orphaned) |
|---|---|---|---|
| `report.gst` · `/report/gst` | `GstController@index` | `ReportScreenController@show` (`report=gst`) → spine `generic-screen` | `reports/gst.blade.php` (had `window.print`) |
| `report.gstr1` · `/report/gstr1` | `TaxReportController@gstr1` | `ReportScreenController@show` (`report=gstr1`) | `reports/tax/gstr1.blade.php` |
| `report.gstr3b` · `/report/gstr3b` | `TaxReportController@gstr3b` | `ReportScreenController@show` (`report=gstr3b`) | `reports/tax/gstr3b.blade.php` (had `window.print`) |
| `report.cn-register` · `/report/cn-register` | `TaxReportController@creditNoteRegister` | `ReportScreenController@show` (`report=cn-register`) | `reports/tax/cn-register.blade.php` |
| `report.day-book` · `/report/day-book` | `ReconciliationReportController@dayBook` | `ReportScreenController@show` (`report=day-book`) | `reports/day-book.blade.php` |

**Browser print retired:** the two `window.print` buttons (in `reports/gst.blade.php` and
`reports/tax/gstr3b.blade.php`) are no longer reachable — those views are orphaned. The
spine `generic-screen` contains no `window.print`; its only export action is the **Export**
button → spine export panel → Chromium PDF / typed Excel / clean CSV.

---

## 2. What is preserved

| Concern | How preserved |
|---|---|
| **URLs** | Identical (`/report/gst`, …) — repointed via route action + `->defaults()`, no redirect. |
| **Bookmarks** | Legacy `?month=&year=` bookmarks still work — `ReportScreenController::resolvePeriod()` honours them (else FY-first presets). |
| **Permissions** | `can:reports.view` middleware unchanged; controller re-checks the report's view gate. A user without `reports.view` gets **403** (proven). |
| **Reconciliation** | Unchanged — the spine datasets wrap the same `GstReportingService` / `TaxService` / `LedgerService` (reconcile by construction). |
| **Export audit** | The spine export path writes the `report_exports` audit row (legacy print wrote none — this is an improvement). |
| **Nav menu** | Links by route name → now resolve to the spine with no template change. |

**Kept intentionally (NOT retired):** the legacy machine-CSV routes (`report.gstr1.csv`,
`report.cn-register.csv`, `report.day-book.csv`, …) and their `TaxReportController` /
`ReconciliationReportController` CSV methods. They are not browser-print; `TaxExportGoldenTest`
pins their byte-stable output. The legacy *screen* controller methods (`GstController@index`,
`TaxReportController@gstr1/gstr3b/creditNoteRegister`, `ReconciliationReportController@dayBook`)
are now dead code (no route) — safe to delete in a later cleanup, out of scope here.

**Content note:** the legacy GST *screen* combined the rate breakdown + credit-note section +
net liability in one page. On the spine these are modular: GST Report = output-tax summary,
GSTR-3B = net liability, Credit Note Register = CN detail. No information is lost — it is
surfaced on the correct compliance report.

---

## 3. Verification evidence

| Check | Result |
|---|---|
| Opening each report from the menu (route name) reaches the spine | ✅ `Phase2SignoffTest::test_legacy_report_urls_now_serve_the_spine_without_browser_print` (5 reports, 200 + spine markers) |
| No compliance report depends on `window.print` | ✅ same test asserts `assertDontSee('window.print')` for all 5 |
| PDF export uses the Chromium document renderer | ✅ `Phase2PdfDocumentTest` — real `%PDF-1.4` samples in `storage/app/reporting-samples/` (gst 46 KB, gstr1 77 KB, gstr3b 45 KB, cn-register 51 KB, day-book 49 KB), 70 furniture assertions |
| Permissions unchanged | ✅ `Phase2SignoffTest::test_permission_still_required` (403 without `reports.view`) |
| Bookmarks (`?month=&year=`) preserved | ✅ updated `GstReportingTest` / `TaxServiceTest` drive the legacy query params against the spine |
| Reconciliation unchanged | ✅ `ComplianceReportsTest` (10 tests) still green |
| No export regression | ✅ legacy golden CSV (`TaxExportGoldenTest`) unchanged + spine all-format exports green |
| No new regression | ✅ full suite **540 passed, 84 pre-existing failures unchanged** (baseline) |

Reporting suite after sign-off: **171 passed**.

---

## 4. Commits

- `e8da0f8` — report-version provenance stamp + `Phase2PdfDocumentTest` (PDF proof).
- *(this change)* — repoint 5 routes to the spine, `Phase2SignoffTest`, `ReportScreenController` month/year bookmark support, legacy test updates.

---

*Phase 2 sign-off is complete: every user-facing compliance report opens on the spine and
exports through the approved Chromium document renderer. Phase 3 (remaining Accounting
reports) has not been started.*
