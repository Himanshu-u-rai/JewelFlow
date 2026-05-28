# Runbook 02: Stuck Pending Restock

**Scenario:** Items are stuck in `pending_restock` status for more than 14 days. Control Center Queue 2 never cleared. The items are physically back in the shop but the system still shows them as awaiting a decision.

---

## 1. How to Detect

Run the stuck-item detector for the affected shop:

```bash
php artisan shop:detect-stuck --shop=<shop_id>
```

Look for **Check S1** in the output:

```
[S1] Items in pending_restock > 14 days
  → item_id=42: pending_restock since 2026-05-01 (21 days)
```

You can also see these items in the UI under **Control Center → Queue 2 (Returned Items awaiting disposition)**.

## 2. Why It Happens

The typical cause is an incomplete cashier workflow:

1. Cashier received a returned item and clicked **"Sent to Melt"** or **"Sent to Rework"** from Queue 2.
2. The item moved to `pending_restock` (or a melt/rework intermediate state) but the follow-through step was never completed — the cashier closed the tab or got interrupted.
3. No one came back to finish the workflow.

Less commonly: a system error during disposition that left the item in a transitional state with no corresponding `returned_item_dispositions` row.

## 3. Recovery

**Preferred path — use the UI:**

1. Go to **Control Center → Returned Items**.
2. Find the stuck item (filter by status = `pending_restock` or use the "stuck items" flag if available).
3. Use the inline **"Fix Status"** button or choose an explicit disposition action:
   - **Restock** — puts the item back to `in_stock`, suitable if it was returned in good condition.
   - **Send to Melt** — records a `sent_to_melt` disposition and triggers lot credit flow.
   - **Send to Rework** — records a `sent_to_rework` disposition, linking to a new job order.
4. The system will create a `ReturnedItemDisposition` row and update the item status atomically.
5. Check the audit log on the item to confirm the status transition was recorded.

After fixing, re-run `shop:detect-stuck` to confirm Check S1 is clear.

## 4. Prevention

- The shop owner should review **Control Center → Queue 2** at least once per week.
- Any item in Queue 2 for more than 7 days should be actioned or flagged.
- Cashier training: if a disposition flow is interrupted, return to Control Center and complete it before end of day.
- Consider setting a shop-level alert (via shop preferences) to notify the owner when items sit in Queue 2 past a configurable threshold.
