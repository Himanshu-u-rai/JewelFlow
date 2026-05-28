# Runbook 08: returns:validate Failures

**Scenario:** `php artisan returns:validate` fails on one or more of its 12 checks. The command outputs which checks failed and which rows triggered each failure.

```bash
php artisan returns:validate --shop=<shop_id>
```

The command is read-only and safe to run on production at any time. Exit code 0 = all clear. Exit code 1 = at least one failure.

---

## The 12 Checks

### Check 1 — Credit-note totals do not exceed original invoice total
The sum of all issued credit notes for an invoice must not exceed the invoice's original total. Overflow here means the system issued more refund than was paid.

**If failing:** Investigate whether a CN was issued against the wrong invoice, or whether a line item amount was duplicated at settlement. Correct via a compensating adjustment — never edit the CN total.

---

### Check 2 — Every settled return has exactly one issued credit note
A settled return order must have exactly one credit note in `issued` status. Zero CNs means the settlement flow did not complete. More than one means a duplicate was issued.

**If failing (0 CNs):** The return is stuck in a settled state with no CN. Complete the CN issuance via the Returns UI — do not re-settle; look for a "Re-issue CN" or contact support tooling.

**If failing (2+ CNs):** Investigate whether a cashier resubmitted the settlement. Identify the duplicate by issued_at timestamp. Enter a compensating negative adjustment equal to the duplicate CN amount.

---

### Check 3 — Credit note total matches sum of line item refund_totals
The CN header total must match the sum of `refund_total` across all its return line items (for CNs with `policy_breakdown` populated). Drift here indicates a rounding or calculation error at settlement time.

**If failing:** Review the `policy_breakdown` on each line. If the drift is within a rounding tolerance (less than ₹1), it is benign; document and monitor. If large, treat as a system bug and raise a bug report before acting.

---

### Check 4 — Credit notes respect the refund_gst=false policy (gst_amount=0 when GST not refunded)
When the shop's `refund_gst` preference is `false`, credit notes issued after `return_policy_configured_at` must have `gst = 0`. Non-zero GST on such CNs means the policy wasn't applied at settlement.

**If failing:** The CN is immutable. Issue a compensating store credit deduction for the wrongly-refunded GST amount if the shop owner requires it, or document the exception. Correct the shop preference going forward.

---

### Check 5 — No invoice line has been refunded more than its locked allocated amount
The cumulative `refund_total` across all return line items for an invoice item must not exceed the locked allocated amount on that invoice item (`line_total + gst_amount - allocated_discount + allocated_round_off`). Overflow means a customer was over-refunded.

**If failing:** This is a serious arithmetic issue. Do not auto-correct. Investigate the invoice item's allocation columns and all return line items referencing it. Raise a bug report. If over-refund is confirmed, recover via a new sale or manual deduction.

---

### Check 6 — Every sent_to_melt disposition with a recorded lot has a return_melt_recovery MetalMovement
When a returned item is sent to melt and a `target_lot_id` is recorded, a `return_melt_recovery` type MetalMovement must exist referencing that disposition. Missing movements mean the lot was not credited for the recovered metal.

**If failing:** This is a stuck workflow. Go to Control Center → the affected disposition. Complete the melt recording step via the UI — this will emit the missing MetalMovement. Do not manually insert a metal movement row.

---

### Check 7 — No customer has a negative store-credit balance
The sum of all `store_credit_movements` for a customer/shop combination must not be negative. The DB trigger should prevent this in real time, but historical data may have slipped through.

**If failing:** Investigate the movement history for the affected customer. If a movement was entered with an incorrect sign (e.g., a debit instead of a credit), issue a compensating positive store credit movement via the store credit management UI.

---

### Check 8 — All store_credit_movements reference existing source records
Every `store_credit_movement` with a `source_type` and `source_id` must reference an existing record in the source table (`credit_notes` for `credit_note_issued`, `invoices` for `sale_applied`). Orphaned movements indicate referential integrity gaps.

**If failing:** The source record was likely deleted (which should not be possible under FK constraints) or the movement was created with an incorrect `source_id`. Investigate whether this was a migration artefact. Do not delete the orphaned movement; document it.

---

### Check 9 — Every exchange_order has valid linked return_order and new invoice
Exchange orders must have a valid `return_order_id` pointing to an existing return order, and if `new_invoice_id` is set, it must point to an existing invoice.

**If failing:** The linked record may have been deleted (should be FK-protected) or the exchange order was created with a wrong reference. Investigate the exchange order's history in the audit log before acting.

---

### Check 10 — All returned-status items have a matching return_line_item record
Items with `status = 'returned'` must have at least one `return_line_items` row referencing them. Items with `returned` status and no return line item are orphaned — see [Runbook 03](03-orphaned-with-karigar-item.md) for the general orphaned item pattern.

**If failing:** Use Control Center → "Fix Status" to re-disposition the item. See Runbook 03 for full steps.

---

### Check 11 — All CN gst values match sum of line refund_gst
The `gst` column on the credit note header must match the sum of `refund_gst` across all its return line items (for CNs with `policy_breakdown`). This is the header-level GST integrity check.

**If failing:** Same approach as Check 3 — small drift (< ₹1) is benign rounding; larger drift indicates a system bug. Document before acting. The CN is immutable.

---

### Check 12 — All CGST+SGST+IGST values sum to GST correctly
On finalized invoices and issued credit notes that have the CGST columns populated, `cgst_amount + sgst_amount + igst_amount` must equal `gst` within ₹0.01. Failures here indicate a GST split calculation error.

**If failing:** This is an arithmetic integrity issue on the tax split. The totals on the invoice/CN are immutable. Document the discrepancy with the invoice/CN number and the delta. Raise a bug report for the GST calculation logic. If statutory filing is affected, consult the accountant before deciding on a correction path.

---

## General Guidance

- **Never auto-repair.** The command detects; humans fix through service-layer actions.
- For stuck-workflow checks (6, 10): complete the workflow via the UI.
- For arithmetic checks (1, 3, 4, 5, 11, 12): investigate fully before acting; the numbers may require a compensating entry.
- For referential integrity checks (2, 8, 9): treat as potential bugs; raise a bug report and document before making any changes.
- Always re-run `returns:validate` after applying a fix to confirm the check passes.
