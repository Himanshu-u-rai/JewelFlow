# Compliance CSV Export — Migration Note

> The legacy machine-CSV endpoints for GSTR-1, Credit Note Register, and Day Book
> are retired. Their output is replaced by the **reporting spine** CSV exporter.
> This note is for anyone (a CA, an accountant, or a script) who consumed the old
> CSV files.

**Date:** 2026-06-05 · **Affects:** `report.gstr1.csv`, `report.cn-register.csv`, `report.day-book.csv`

---

## 1. Legacy format (retired)

- Produced by `CsvReportExporter::fromRows()` (ad-hoc `fputcsv`).
- **GSTR-1:** a *single* CSV with **banner rows** separating sections — `== B2B (registered buyers) ==`, `== B2CS (consumers) ==`, `== HSN Summary ==` — with section headers and data interleaved in one file.
- **CN Register / Day Book:** a single CSV table.
- URLs: `GET /report/gstr1/export`, `/report/cn-register/export`, `/report/day-book/export`.

## 2. Spine format (replacement)

Produced by the spine `CsvRenderer` (frozen §5.3): **one header row + raw machine values, no banner rows, no metadata inside the CSV.**

- **GSTR-1 (multi-section):** a **ZIP** containing one clean CSV per section —
  `b2b.csv`, `b2cs.csv`, `hsn.csv`, `credit-notes.csv` — plus a
  `gstr1-<timestamp>-meta.json` provenance sidecar (report version, period,
  shop, generated-by/at, filters).
- **CN Register / Day Book (single-section):** one clean `text/csv` file.
- **Value rules:** ISO dates (`YYYY-MM-DD`), decimals with `.` and 2 dp (weights 3 dp), **no ₹, no thousands separators**, booleans `true`/`false`.
- **Access:** the export panel → `POST /reports/{report}/export` with `format=csv`
  (writes a `report_exports` audit row; respects `reports.view`/`reports.export`).

## 3. Compatibility impact

| Consumer | Impact | Action |
|---|---|---|
| Human / CA reading the file | Cleaner: one table per section; sections are separate files in the GSTR-1 ZIP | Open the ZIP; each section is its own CSV |
| Script parsing GSTR-1 | **Breaking:** no more `== … ==` banner rows; sections are now separate ZIP entries | Read the ZIP entries by name (`b2b.csv`, …) instead of splitting on banners |
| Script parsing CN Register / Day Book | Mostly compatible: still a single CSV; **header labels and value formatting changed** (raw values, ISO dates) | Re-map column headers; parse raw numeric/date values |
| GSTN offline tool | Unaffected — these endpoints were a convenience export, not a GSTN-upload format | n/a |

**Data parity:** the spine exports contain the **same data** (verified by `TaxExportGoldenTest` against the spine format — exact B2B/B2CS/HSN/CN rows). No data is lost; only the container/shape changed.

## 4. Migration guidance

1. Switch from the retired URLs to the export panel (`/reports/gstr1/export`, etc.) with `format=csv`.
2. For GSTR-1, treat the download as a **ZIP**; read `b2b.csv` / `b2cs.csv` / `hsn.csv` / `credit-notes.csv`.
3. Update any banner-row parsing to read separate files.
4. Re-map CN Register / Day Book headers (see the spine headers in `TaxExportGoldenTest`).

## 5. Retirement rationale

- The legacy banner-CSV is the exact format the frozen architecture set out to remove (§1.2, §5.3, §13: "retire ad-hoc `fputcsv` and the banner-CSV format").
- It **bypassed the spine export audit** (no `report_exports` row), unlike the spine path.
- It duplicated a compliance export path outside the single dataset→renderer spine.
- The endpoints were **not user-facing** (no nav link; their parent screens were already repointed to the spine in Phase 2 sign-off).

---

*The clean spine CSV/ZIP is now the single CSV path for these reports.*
