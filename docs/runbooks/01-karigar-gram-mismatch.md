# Runbook 01: Karigar Gram Mismatch

**Scenario:** Vault reconciliation shows grams with karigar does not match job order issued weights. The lot balance or karigar outstanding balance looks wrong compared to what job orders record.

---

## 1. Diagnose

Run the karigar reconciliation for the affected shop:

```bash
php artisan karigar:reconcile --shop=<shop_id>
```

To limit to a specific karigar:

```bash
php artisan karigar:reconcile --shop=<shop_id> --karigar=<karigar_id>
```

The command is read-only and safe to run on production.

## 2. Reading the Output Sections

| Section | What It Shows | Normal State |
|---|---|---|
| **A — Outstanding balance** | Fine grams issued to each karigar minus receipts received | Small positive float is normal; large or negative is a flag |
| **B — Overdue jobs** | Job orders past their expected return date with no receipt | Should be empty or owner-acknowledged |
| **C — Orphaned items** | Items marked `with_karigar` with no open job order | Should be empty (see Runbook 03) |
| **D — Cross-check** | Sum of lot outflows vs sum of receipts + outstanding | Should balance within 0.01g tolerance |

A mismatch in Section D is the primary indicator that something is wrong.

## 3. Safe Recovery

If Section D shows a discrepancy traced to a missing or incorrect entry:

1. Navigate to **Vault → Add Adjustment** in the UI.
2. Select the affected lot.
3. Enter the adjustment amount:
   - Positive if the lot is under-credited (vault has more metal than recorded).
   - Negative if the lot is over-credited (a duplicate or inflated receipt was recorded).
4. **The note field is mandatory.** Write a clear explanation, for example:
   > "Correction for karigar Raju — receipt job #1234 recorded 2.1g but scale showed 1.9g. Adjusting -0.2g fine. Verified by owner on 2026-05-20."
5. Save. The system will create a `vault_adjustment` MetalMovement and record an audit log entry.

After saving, re-run `karigar:reconcile` to confirm the discrepancy is resolved.

## 4. When to Escalate

Escalate to a second engineer or the business owner if:

- The variance is **>2g** across multiple karigars and cannot be traced to a single receipt.
- Section C shows multiple orphaned items (may indicate a systematic job order cancellation bug — see Runbook 03).
- The same karigar shows recurring discrepancies across multiple reconciliation runs.

Document the situation in the audit log before escalating.

## 5. What NOT to Do

- **Do not run raw SQL** on `metal_movements`, `karigar_lots`, or `karigar_receipts`. These tables are protected by the ImmutableLedger guard and a DB-level trigger.
- **Do not manually edit `issued_fine_weight`** on a lot or job order — this column is locked after finalization.
- **Do not delete or edit metal movement rows** — they are append-only by design.
- Do not adjust a lot belonging to a different shop to "balance the books" — each shop's vault is independent.
