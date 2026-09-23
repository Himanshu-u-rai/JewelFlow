# Release order for the disk-column and signature-relocation schema changes

Findings S3-02, S3-03, S3-04 (attachment/signature disk recording).

**Status: PROPOSED. Nothing here has been executed.** No migration in this set
has run anywhere but the local `jewelflow_testing` database. Applying any of it
to production or staging needs explicit approval.

Last observed deployed baseline: `018b3d810e37d534f498033ab582ee41f3197c27` (observed on the server 2026-09-20T18:36:08+00:00; not re-observed since)
(authored 2026-09-15, subject "Stop the read-only KPI calling a lapsed shop
'unattributed'"). Verified as a local git object on 2026-09-21T00:37+05:30.
That is a check of the SHA I was *given*, not an observation of what is running
on the servers — recheck for drift against the deployed tree before executing
any step below.

---

## The mistake this document exists to correct

I previously described the three disk-column migrations as "additive" and
treated that as meaning they were safe to apply while the deployed application
kept serving. That inference was wrong.

Each of the three did three things in one file: add a nullable disk column,
backfill it, and add a both-or-neither CHECK constraint. The column and the
backfill are genuinely additive — the old code neither reads nor writes the new
column, and a NULL is a state it already produces. The **constraint is not**. It
forbids a row that has a path and no disk, and writing exactly that row is what
the baseline does on every file upload:

| Baseline writer | Line | Writes |
|---|---|---|
| `SettingsController::update()` | 526-527 | `digital_signature_path`, never `_disk` |
| `SettingsController::update()` | 528-530 | nulls the path on removal, leaves `_disk` behind |
| `StockPurchaseController` | 140, 354 | `invoice_image` only |
| `KarigarInvoiceService` | 49, 114 | `invoice_file_path` only |

So applying the constraint before the new code is live turns every file upload
on the running site into a database error, and every signature removal too.

This is demonstrated, not argued: `tests/Feature/Security/DiskColumnReleaseOrderTest.php`.

---

## Phases

### Phase 1 — EXPAND (safe while the baseline is still serving)

| Migration | Adds |
|---|---|
| `2026_09_15_120000_add_invoice_file_disk_to_karigar_invoices.php` | `karigar_invoices.invoice_file_disk` + backfill |
| `2026_09_15_140000_add_invoice_image_disk_to_stock_purchases.php` | `stock_purchases.invoice_image_disk` + backfill |
| `2026_09_16_120000_add_digital_signature_disk_to_billing_settings.php` | `shop_billing_settings.digital_signature_disk` + backfill |
| `2026_09_20_120000_create_signature_relocations_table.php` | new `signature_relocations` table |

All four are nullable-column / new-table additions. The baseline neither reads
nor writes any of them.

The backfills record `'public'` for rows that already hold a path, because that
is where those bytes physically are. **This is a truthful relabel of the status
quo, not a remediation.** The files remain on the public tree until the
separately-approved relocation procedure runs.

### Phase 2 — APPLICATION

Deploy the new application code to every serving node. Until this completes,
Phase 3 must not run.

### Phase 3 — CONTRACT (only after Phase 2 is live everywhere)

| Migration | Adds |
|---|---|
| `2026_09_20_130000_add_disk_column_check_constraints.php` | all three both-or-neither CHECK constraints |

This migration:

1. **Reconciles first.** The window between Phase 1 and Phase 3 is one in which
   the old code keeps writing rows the constraint will reject — in both
   directions (path without disk on upload, disk without path on removal). The
   Phase 1 backfill ran *before* those rows existed. Without a second sweep,
   `VALIDATE CONSTRAINT` aborts the deploy on exactly the rows the plan told the
   old code it was free to write. Pinned by T-07.
2. **Adds each constraint `NOT VALID`, then `VALIDATE`s it.** A plain
   `ADD CONSTRAINT` holds `ACCESS EXCLUSIVE` for a full table scan. `NOT VALID`
   takes that lock only briefly and still enforces the CHECK on every subsequent
   INSERT and UPDATE; `VALIDATE` then scans under `SHARE UPDATE EXCLUSIVE`,
   which blocks neither reads nor writes.

The `VALIDATE` step is load-bearing and easy to lose: a migration that adds the
constraint and skips it reports a **successful deploy** while permanently
exempting every row written during the expand window. T-06 asserts
`pg_constraint.convalidated`, which is the only signal that separates the two.

---

## Rollback

| From | Rolling back | Result |
|---|---|---|
| Phase 3 | `down()` drops the three constraints | Returns to the expand-only window, which the baseline tolerates. Fully reversible. |
| Phase 2 | Redeploy baseline code | See the caveats below — schema rollback alone does not undo what the new code wrote. |
| Phase 1 | `down()` drops the columns and the relocations table | Reversible as schema, but destroys the record of which disk each file is on. |

### What a rollback cannot undo

State this plainly rather than implying the change is freely reversible:

- **Files already written to the private disk by the new code.** The baseline
  reads attachments from the public disk unconditionally. A file the new code
  stored privately is recorded as such in its row's disk column — and rolling
  back Phase 1 *deletes that column*. The baseline will then look for those
  bytes on the public tree and not find them. Those uploads become unreachable
  to the old code, and the record of where they actually are is gone.
- **Files already relocated.** Same shape, plus the `signature_relocations`
  ledger that maps a historical reference to its verified private copy is
  dropped with it.

Practical consequence: **roll back Phase 3 freely; treat Phase 1 rollback as
one-way once any private upload or relocation has occurred.** If the application
must be reverted after that point, revert the code (Phase 2) and leave the
columns in place — the baseline ignores them, so keeping them costs nothing and
preserves the only record of where the bytes are.

---

## Dependencies

- `2026_09_20_130000` (contract) requires all three expand migrations. It skips
  any table whose disk column is absent rather than failing, so a partial estate
  does not abort the deploy.
- `2026_09_20_120000` (`signature_relocations`) is required by
  `App\Services\SignatureRelocationLedger`, which
  `App\Services\InvoiceSignatureRenderer` and
  `App\Console\Commands\RelocateShopSignatures` both depend on. It has no
  ordering relationship to the contract phase.
- The relocation command must not run before Phase 2, and its purge step
  requires ledger evidence per row (pinned by R-18).

---

## Recorded limitations

- **S3-02b (new, open).** The signature and purchase constraints also restrict
  the disk to `('public','local')`; the karigar one does not. The contract
  migration carries each constraint over verbatim so that this phase changes
  release order and nothing else. Tightening karigar to match is a behaviour
  change for its own reviewed commit.
- The flat `signatures/` layout the baseline uses means every shop's signature
  shares one public directory and the paths are mutually enumerable. That is
  part of S3-04's exposure. No path check fixes it; only relocation does.
- Test evidence here is local only (`jewelflow_testing`, synthetic fixtures).
  Production exposure remains OPEN until containment is applied and verified.
