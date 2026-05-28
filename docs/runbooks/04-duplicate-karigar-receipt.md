# Runbook 04: Duplicate Karigar Receipt

**Scenario:** A karigar receipt was entered twice. The fine weight was credited to a lot twice, inflating the lot balance and the karigar's reconciliation ledger.

---

## 1. How to Detect

Run the vault reconciliation command:

```bash
php artisan vault:reconcile --shop=<shop_id>
```

An unexpectedly high lot balance compared to what was physically issued is the primary symptom. Also check:

```bash
php artisan karigar:reconcile --shop=<shop_id> --karigar=<karigar_id>
```

In the output, Section A will show the karigar's outstanding balance as unexpectedly **low** (or negative) because the duplicate receipt over-credits their account, making it look like they returned more than was issued.

**To confirm a duplicate:** look at the karigar receipts list for the job order. Two receipts with the same gross weight, same date, and same job order reference are the clearest indicator.

## 2. Safe Recovery

Enter a **compensating vault adjustment** for the duplicate fine weight:

1. Calculate the duplicate fine weight: this is the `fine_weight` field on the duplicate receipt (typically `gross_weight * purity / 24`).
2. Navigate to **Vault → Add Adjustment** in the UI.
3. Select the affected lot.
4. Enter the adjustment amount as a **negative number** equal to the duplicate fine weight.
   - Example: duplicate receipt credited 1.85g fine → enter `-1.85`.
5. **The note field is mandatory.** Write a clear, traceable explanation:
   > "Correction for duplicate receipt — karigar Suresh, job #5678, receipt entered twice on 2026-05-10. Removing duplicate credit of 1.85g fine. Approved by owner."
6. Save. The system creates a `vault_adjustment` MetalMovement with the note attached.

Re-run `vault:reconcile` and `karigar:reconcile` after saving to confirm the balances are correct.

## 3. What NOT to Do

- **Do not delete** the duplicate `metal_movements` row. Movement rows are append-only and trigger-protected.
- **Do not edit** `issued_fine_weight` on the lot directly — this column is locked by the ImmutableLedger guard after finalization.
- Do not "void" the karigar receipt by setting a status flag — the system has no void concept for receipts; use compensating entries only.
- Do not enter the adjustment on the wrong lot — verify the `lot_id` before saving.
