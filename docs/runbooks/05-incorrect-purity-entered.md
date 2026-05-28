# Runbook 05: Incorrect Purity Entered on Karigar Receipt

**Scenario:** Wrong purity (karats) was entered on a karigar receipt, causing the fine weight calculation to be wrong. For example, 19k was entered instead of 22k, or 18k instead of 22k.

---

## 1. Effect of the Error

Fine weight is calculated as:

```
fine_weight = gross_weight * (purity_karats / 24)
```

If the wrong purity was entered, the fine weight stored on the `metal_movements` row is wrong, and the lot balance reflects that wrong amount. The lot is either **over-credited** (purity entered too high) or **under-credited** (purity entered too low).

## 2. Calculate the Correction

Determine the fine weight difference:

```
difference = (correct_purity/24 - wrong_purity/24) * gross_weight
```

**Examples:**

| Wrong purity | Correct purity | Gross weight | Difference |
|---|---|---|---|
| 19k | 22k | 10.0g | (22/24 - 19/24) × 10 = +1.25g |
| 22k | 18k | 8.0g | (18/24 - 22/24) × 8 = -1.33g |

- Positive difference: the receipt under-credited the lot; add a positive vault adjustment.
- Negative difference: the receipt over-credited the lot; add a negative vault adjustment.

## 3. Recovery

Enter a compensating vault adjustment:

1. Navigate to **Vault → Add Adjustment** in the UI.
2. Select the affected lot.
3. Enter the calculated `difference` as the adjustment amount (positive or negative as determined above).
4. **The note field is mandatory.** Be specific:
   > "Correction for incorrect purity on karigar receipt — job #3456 entered 19k, correct purity is 22k, gross weight 10.0g. Fine weight difference = +1.25g. Verified by owner on 2026-05-20."
5. Save.

Re-run `vault:reconcile` and `karigar:reconcile` to confirm the lot balance is now correct.

## 4. Check the Item's Purity Field

The vault adjustment corrects the lot balance but does **not** change any purity stored on the `items` record. These are separate:

- The `metal_movements.fine_weight` (stored on the original movement row) is what drove the lot imbalance — the compensating adjustment corrects the net balance without editing the original row.
- `items.purity` is a separate mutable field on the item record. If the item itself has the wrong purity listed (e.g., the karigar receipt was created off of a wrongly-keyed item), check whether `items.purity` also needs to be corrected.
- `items.purity` **can** be edited via the normal item edit flow in the UI (it is not immutable). This is a separate action from the vault adjustment.

Make sure to check and correct both if needed.

## 5. What NOT to Do

- **Do not edit** the original `metal_movements` row to fix the fine weight — movement rows are append-only and trigger-protected.
- Do not create a new karigar receipt with the correct purity and leave the original — this doubles the gross weight entry. Use a compensating adjustment on the net fine weight difference only.
