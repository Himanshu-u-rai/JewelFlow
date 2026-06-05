# Phase 2 Residual Compliance CSV — Retirement Assessment

> **Diagnosis only. No code, routes, controllers, or tests modified.** Targeted
> assessment of the three residual legacy machine-CSV endpoints surfaced in
> `PHASE3_PRESTART_AUDIT.md` §2/§9.
> **Date:** 2026-06-05

Scope: `report.gstr1.csv`, `report.cn-register.csv`, `report.day-book.csv`.

---

## 1. Exact route definitions (`routes/web.php`)

| Name | Line | URL | Action |
|---|---|---|---|
| `report.gstr1.csv` | L429 | `GET /report/gstr1/export` | `Reporting\TaxReportController@gstr1Csv` |
| `report.cn-register.csv` | L432 | `GET /report/cn-register/export` | `Reporting\TaxReportController@creditNoteRegisterCsv` |
| `report.day-book.csv` | L437 | `GET /report/day-book/export` | `Reporting\ReconciliationReportController@dayBookCsv` |

All three: `->middleware('can:reports.view')`.

---

## 2. Exact controller methods (what they emit + how)

All emit via `App\Reporting\Export\CsvReportExporter::fromRows(...)` (the legacy ad-hoc CSV path the frozen architecture marks for retirement, §1.2/§5.3/§13).

- **`TaxReportController@gstr1Csv`** — a **single CSV with banner rows**: `== B2B (registered buyers) ==`, `== B2CS (consumers) ==`, `== HSN Summary ==`, with interleaved header + data rows. This is the **deprecated banner-CSV format** (`resources`: `TaxReportController.php` ~L44–96).
- **`TaxReportController@creditNoteRegisterCsv`** — a single clean table, 13 columns: `CN Number, Date, Type, Original Invoice, Original Invoice Date, Customer, Rate %, Taxable, CGST, SGST, IGST, GST, CN Total` (~L129–152).
- **`ReconciliationReportController@dayBookCsv`** — a single clean table of day-book rows (~L84–101).

---

## 3. Current consumers

| Endpoint | UI links | Tests | Integrations |
|---|---|---|---|
| `report.gstr1.csv` | `reports/tax/gstr1.blade.php` L21 (**orphaned view**) | `TaxServiceTest` L141; `TaxExportGoldenTest` L88 | none found |
| `report.cn-register.csv` | `reports/tax/cn-register.blade.php` L21 (**orphaned view**) | `TaxServiceTest` L136; `TaxExportGoldenTest` L116 | none found |
| `report.day-book.csv` | `reports/day-book.blade.php` L17 (**orphaned view**) | **none** | none found |

Every UI link lives **inside a legacy Blade view that is no longer served by any route** (the parent screen routes were repointed to the spine; the legacy screen controllers are unrouted — see `PHASE3_PRESTART_AUDIT.md` §2/§9). So the only *reachable* consumers are the **tests** (gstr1/cn-register) and **direct-URL access**.

---

## 4. Does any user-facing path still depend on them?

**No.** Evidence:
- Not linked from the sidebar (`layouts/app.blade.php`), the dashboard command palette (`dashboard.blade.php`), or any served report screen — those link the **screen** route names, which resolve to the spine.
- The three download links exist only inside orphaned legacy blades that no route renders.
- They remain reachable by **direct URL / old bookmark** (with `auth` + `can:reports.view`), but that is not a nav-linked user path.

---

## 5. Can the spine CSV exporter produce equivalent output?

Spine `CsvRenderer` (evidence: `CsvRenderer.php`): single-section → one clean `text/csv`; **multi-section → a ZIP of per-section CSVs + a `<report>-meta.json` provenance sidecar** (L50–83), with raw values and **no banner rows** (the deliberate fix per frozen §5.3).

| Endpoint | Semantic equivalence | Byte equivalence | Notes |
|---|---|---|---|
| `report.gstr1.csv` | ✅ same data (B2B/B2CS/HSN/CN) | ❌ **No** — legacy = one banner-CSV; spine = **ZIP of per-section CSVs** (different container + clean format) | The spine output is the *intended replacement*; the banner-CSV is explicitly deprecated (§5.3/§13) |
| `report.cn-register.csv` | ✅ same columns/data | ❌ **No** — header labels + value formatting differ (clean machine values) | Single CSV both sides; spine = clean format |
| `report.day-book.csv` | ✅ same data | ❌ **No** — clean format vs legacy | Single CSV both sides |

**Conclusion:** the spine can produce **semantically-equivalent** output for all three, but **not byte-equivalent** — by design (the frozen architecture replaces the banner-CSV with the clean/ZIP format).

---

## 6. Can `TaxExportGoldenTest` be migrated safely?

`TaxExportGoldenTest` (L83–) pins the **legacy banner-CSV bytes**: it asserts rows like `['== B2B (registered buyers) ==']`, the exact B2B/B2CS/HSN header rows, and CN-register rows (gstr1 + cn-register only; **it does not test day-book.csv**).

- **Migratable: yes, but it is a rewrite, not a byte-copy.** Because the spine format is intentionally different (clean CSV / ZIP-per-section, no banners), the golden assertions must be **rewritten** to assert the spine's clean output (e.g., per-section CSV inside the ZIP for GSTR-1; clean header for cn-register).
- **Safety caveat (frozen §13 risk "Breaking existing CSV consumers"):** retiring the banner-CSV is a **format change** for any external consumer (a CA or a script) that parses the current banner-CSV. The frozen plan calls for "a brief migration note" when this format changes. That note + consumer inventory is part of the same retirement.

---

## 7. Phase 3 vs before Phase 3?

The frozen architecture **explicitly schedules this retirement in Phase 3**:
- Frozen plan §3 / §12 Phase 3: *"Bring legacy screen+print reports … onto the spine. **Retire ad-hoc `fputcsv`** and the banner-CSV format."*
- These three endpoints ARE the ad-hoc `fputcsv` / banner-CSV path.

There is **no Phase-2-sign-off reason** to retire them earlier: Phase 2 sign-off was about screens + PDF + browser-print (all complete; these are machine-CSV, not browser-print, not nav-linked).

---

## Per-endpoint classification

| Endpoint | ACTIVE | LEGACY | REQUIRED | RETIRABLE | Evidence |
|---|---|---|---|---|---|
| `report.gstr1.csv` | **Yes** (routed) | **Yes** (deprecated banner-CSV via `CsvReportExporter`) | **Test-only** (`TaxExportGoldenTest`, `TaxServiceTest`); **not** user-facing | **Yes**, with golden-test rewrite + consumer migration note | routes L429; controller `gstr1Csv`; consumers §3 |
| `report.cn-register.csv` | **Yes** (routed) | **Yes** (legacy `CsvReportExporter`) | **Test-only** (`TaxExportGoldenTest`, `TaxServiceTest`); **not** user-facing | **Yes**, with golden-test rewrite | routes L432; controller `creditNoteRegisterCsv`; consumers §3 |
| `report.day-book.csv` | **Yes** (routed) | **Yes** (legacy `CsvReportExporter`) | **No** — zero tests, zero served-view consumers | **Yes — immediately** (no migration required) | routes L437; controller `dayBookCsv`; consumers §3 |

(None are "REQUIRED" by a user-facing path; `gstr1.csv`/`cn-register.csv` are required only by the legacy golden test, which itself pins a deprecated format.)

---

## Recommended Action

### **B. Retire them during Phase 3.**

**Evidence supporting B (and against A and C):**

1. **Frozen scheduling.** The architecture explicitly places "retire ad-hoc `fputcsv` / banner-CSV" in **Phase 3** (§3, §12). Retiring now (Option A) pulls Phase 3 cleanup forward without benefit.
2. **No user-facing exposure.** None are nav-linked; links live only in orphaned views (§3/§4). There is no Phase-2 sign-off violation to fix urgently — the sign-off concern (browser-print screens) is fully resolved.
3. **Retirement carries Phase-3-shaped work.** Safe retirement requires (a) rewriting `TaxExportGoldenTest` to the spine's clean/ZIP format, and (b) a CSV-consumer migration note (frozen §13 risk). Both belong with the broader Phase 3 legacy-retirement sweep, where the spine CSV becomes the documented default.
4. **Against C (keep permanently):** these are the **deprecated banner-CSV** format the architecture mandates removing, and they are an **export path that bypasses the spine audit** (`report_exports` is not written). Keeping them forever = two divergent CSV formats + a permanent unaudited compliance export path. Not acceptable as a permanent state.
5. **`report.day-book.csv` is a free, safe deletion** (no test/consumer) whenever convenient — but retiring one of three piecemeal is inconsistent; batch all three in the Phase 3 cleanup for a single migration note.

**Net:** keep the three endpoints untouched through Phase 2 sign-off; retire all three (with the golden-test rewrite + a one-line consumer migration note) as the **first cleanup task of Phase 3's legacy-retirement step**.

---

*Diagnosis only. No routes deleted, no controllers changed, no tests changed.*
