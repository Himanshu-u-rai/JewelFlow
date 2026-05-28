# Runbook 03: Orphaned With-Karigar Item

**Scenario:** An item shows `with_karigar` status but no open job order exists for it. The item appears to be at a karigar's workshop in the system, but there is no job order record to back that up.

---

## 1. How to Detect

**Via artisan command:**

```bash
php artisan shop:detect-stuck --shop=<shop_id>
```

Look for **Check S2** in the output:

```
[S2] Items with_karigar but no open job order
  → item_id=88: status=with_karigar, no open job_order found
```

**Via UI:**

Go to **Control Center → Incomplete Workflows**. Items with `with_karigar` status and no linked active job order appear in this section.

## 2. Common Causes

- A job order was **cancelled** (e.g., customer changed their mind) but the item's status was not restored. The cancellation flow failed to call the status-restore path.
- A job order was **completed** but a data integrity gap left the item status as `with_karigar` instead of advancing it to `returned` or `in_stock`.
- Manual data entry during a migration or import set the item status directly without creating the corresponding job order.

## 3. Recovery

Use the **"Fix Status"** inline button in Control Center:

1. Go to **Control Center → Incomplete Workflows**.
2. Find the orphaned item.
3. Click the **"Fix Status"** button next to the item.
4. The system determines the correct target status based on the item's last known job type:
   - **Repair job** (last job was a repair/polish/rhodium): restores to `in_stock`.
   - **Rework job** (item was being remade): restores to `returned`.
5. The system creates a new `ReturnedItemDisposition` row (append-only) and transitions the item status. It does **not** edit any historical rows.

After using "Fix Status", verify the item's entity event feed shows the status transition and that the audit log entry was created.

## 4. Verify

Run the validation command after fixing:

```bash
php artisan returns:validate --shop=<shop_id>
```

Check that Check 10 (`All returned-status items have a matching return_line_item record`) passes. Also re-run:

```bash
php artisan shop:detect-stuck --shop=<shop_id>
```

Confirm Check S2 no longer lists the item.

## 5. What NOT to Do

- **Do not run** `UPDATE items SET status = 'in_stock' WHERE id = X` directly. This bypasses the ImmutableLedger guard and leaves no audit trail.
- Do not delete the orphaned item's job order row — it may still contain useful historical data.
- Do not create a new fake job order just to satisfy the check — fix the status through the disposition path.
