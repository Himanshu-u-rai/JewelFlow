# Runbook 06: Abandoned Draft Return Orders

**Scenario:** Draft return orders are piling up from transactions that were started at the counter but never completed or cancelled. These drafts have no accounting footprint but clutter the UI and inflate workflow queue counts.

---

## 1. How to Detect

**Via artisan command:**

```bash
php artisan shop:quality-signals --shop=<shop_id>
```

Look for **Signal Q3** in the output:

```
[Q3] Draft return orders older than 7 days
  → return_order_id=201: draft since 2026-05-10 (12 days), customer_id=55
  → return_order_id=208: draft since 2026-05-08 (14 days), customer_id=89
```

You can also detect them via:

```bash
php artisan shop:detect-stuck --shop=<shop_id>
```

Look for **Check S4** in that output.

## 2. Safe Cleanup

Draft return orders (status = `draft`) have **no accounting footprint** — no credit note has been issued, no store credit has been moved, and no item status has been changed. They are safe to cancel.

**Via UI (preferred):**

1. Go to **Returns** in the admin panel.
2. Filter by status = `draft`.
3. Open each draft return order.
4. Click **Cancel** (or the equivalent abandon action).
5. The system marks the return order as `cancelled` and records an audit log entry.

**Via DB (acceptable for drafts only):**

Unlike settled returns and issued credit notes, `status='draft'` return orders may be cancelled directly at the DB level when there are many to clean up at once and no UI bulk-cancel is available:

```sql
-- Safe ONLY for draft status. Verify before running.
UPDATE return_orders
SET status = 'cancelled', updated_at = NOW()
WHERE shop_id = <shop_id>
  AND status = 'draft'
  AND created_at < NOW() - INTERVAL '30 days';
```

Confirm the scope with a `SELECT` before running the `UPDATE`. This is the **only** case where a direct status update is acceptable — `draft` records have zero accounting impact.

## 3. Prevention

- Cashiers should **always cancel** a draft return at the counter if they are abandoning the transaction mid-flow. The cancel button is available at all stages before settlement.
- The shop owner should review Signal Q3 in the weekly quality signals report and action anything older than 14 days.
- Stale drafts older than 30 days with no activity can be treated as abandoned and cancelled.
