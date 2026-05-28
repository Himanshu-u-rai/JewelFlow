# Runbook 07: Credit Note / Refund Discrepancy

**Scenario:** A customer disputes that the refund amount on their credit note does not match what they expected. They may have expected a full refund of what they paid, but the CN shows a lower amount.

---

## 1. Where to Find the Breakdown

Every credit note issued after Phase A has a per-line policy breakdown stored in the database.

1. Go to **Returns** in the admin panel.
2. Open the relevant return order (search by customer name, invoice number, or CN number).
3. On the return order show page, expand each line item.
4. Click **"View deduction breakdown"** — this is a `<details>` disclosure element on each line.

The breakdown shows every deduction applied at the time of settlement in plain language.

## 2. Key Fields in the Policy Breakdown

The `policy_breakdown` JSONB column on each `return_line_items` row contains the exact calculation:

| Field | What It Means |
|---|---|
| `original_paid` | The amount the customer originally paid for this line item (inclusive of GST, net of discount) |
| `making_retained` | Making charges withheld per shop policy (0 if the shop policy refunds making charges) |
| `gst_refunded` | GST portion included in the refund (0 if `refund_gst=false` in shop preferences) |
| `final_refund` | The actual refund amount for this line: `original_paid - making_retained ± gst_adjustment` |

Show the customer the `policy_breakdown` values line by line. The most common reason for a lower-than-expected refund is `making_retained > 0` — the shop's return policy retains making charges, which is disclosed at the point of sale.

## 3. If the Policy Was Wrong at Settlement Time

The credit note is **immutable** once issued. The `gst`, `total`, and per-line amounts on a settled CN cannot be edited.

If there was a genuine system bug in the policy calculation at the time of settlement:

1. Document the discrepancy: record the CN number, the expected amount, the actual amount, and the reason the policy calculation was wrong.
2. The forward correction path is:
   - Create a **new sale** for the difference amount (if the customer is owed more), or
   - Issue a **manual store credit adjustment** via the store credit management UI (for partial credit).
3. Add a note to both the original return order and the new sale/adjustment referencing the other record.
4. Log the correction in the audit system.

If the issue was a misconfigured shop preference (e.g., `refund_gst` was set to `false` when it should have been `true`), correct the shop preference going forward and note that historical CNs reflect the policy at the time of issuance.

## 4. What NOT to Do

- **Do not attempt to edit** `credit_notes.total`, `credit_notes.gst`, or any `return_line_items` amount field. The ImmutableLedger guard blocks writes to finalized credit note fields, and the DB-level trigger prevents direct edits to the credit notes table.
- Do not "cancel" a settled return order to re-issue a higher CN — cancellation of settled returns is not supported. Use the forward compensation path described above.
- Do not tell the customer the system will be updated retroactively — settled credit notes are permanent records.
