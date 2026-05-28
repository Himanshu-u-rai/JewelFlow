# Tier 2 Metal Opt-In Playbook

> **Constitutional reference:** CONSTITUTION.md Article XIII (Material Tier Doctrine), Article XV (MetalRegistry Authority).

When a pilot shop wants to transact in a **Tier 2** metal (currently `platinum` or `copper`), the operator must explicitly opt them in via `shop_enabled_metals`. Tier 1 metals (`gold`, `silver`) are auto-enabled on shop creation; Tier 2 requires this deliberate flow because of the operational restrictions that apply.

## What Tier 2 means for the shop

A Tier 2 metal **can**:
- Be assigned as `metal_type` on items, products, lots
- Appear on invoices and credit notes
- Be tracked in vault and reconciliation reports (per-metal)
- Receive a daily rate in `shop_daily_metal_rate_entries` (manually entered)

A Tier 2 metal **cannot**:
- Be auto-repriced by `RepriceRetailerInventoryJob` (item holds manual `selling_price`)
- Be auto-fetched by `FetchLiveMetalRatesJob` (rate is manual)
- Be used as dhiran (gold-loan) collateral
- Be accepted as old-metal payment in POS exchange flow
- Be pooled in weekly old-gold/old-silver lot aggregation

These restrictions are enforced at three layers: controller validators, service-layer guards (`MetalRegistry::is*Eligible`), and the DB CHECK constraint that accepts the Tier 2 metal at all.

## Step 1 — Confirm the request is real

Before any DB action:
- Has the shop owner specifically requested platinum or copper transactions?
- Is the rate-update flow understood (they must manually enter the rate, not expect API fetch)?
- Is the dhiran-blocked restriction acceptable to the owner?
- Has the request been recorded in the support log?

If any answer is "no" — do not proceed.

## Step 2 — Insert the opt-in row

```sql
-- One row per (shop_id, metal_type). Mirror this exact pattern.
-- PostgreSQL boolean rule per CONSTITUTION.md §2 Pattern F4: TRUE literal.
INSERT INTO shop_enabled_metals
    (shop_id, metal_type, enabled, enabled_at, enabled_by_user_id, notes, created_at, updated_at)
VALUES
    (<SHOP_ID>, '<METAL>', TRUE, NOW(), <OPERATOR_USER_ID>,
     'Tier 2 opt-in — owner requested <DATE>. Acknowledged: no auto-reprice, no dhiran, no exchange payment, manual rate entry only.',
     NOW(), NOW());
```

Example for shop 4 opting in to platinum:
```sql
INSERT INTO shop_enabled_metals
    (shop_id, metal_type, enabled, enabled_at, enabled_by_user_id, notes, created_at, updated_at)
VALUES
    (4, 'platinum', TRUE, NOW(), 1,
     'Tier 2 opt-in — shop owner requested 2026-06-15. Acknowledged: no auto-reprice, no dhiran, no exchange payment, manual rate entry only.',
     NOW(), NOW());
```

Once inserted, the shop's owner can immediately:
- Create items with `metal_type='platinum'`
- Enter a daily platinum rate from Settings → Pricing (manual rate-entry only)
- See platinum in vault, closing, PnL, and reconciliation reports as a separate column

## Step 3 — Clear the MetalRegistry cache (one-shot)

The `MetalRegistry::enabledMetalsForShop` per-process cache must be invalidated. The cache is process-local and resets between requests, so the next HTTP request to the shop will see the new metal. If a long-running queue worker is in scope, restart it.

```bash
# Restart queue workers so they pick up the new enabled-metals list.
php artisan queue:restart
```

## Step 4 — Audit-log the opt-in (recommended)

```php
\App\Services\AccountingAuditService::log([
    'shop_id'     => <SHOP_ID>,
    'user_id'     => <OPERATOR_USER_ID>,
    'action'      => 'tier_2_metal_enabled',
    'model_type'  => 'shop_enabled_metals',
    'model_id'    => <ROW_ID>,
    'description' => 'Tier 2 metal "<METAL>" enabled for shop <SHOP_ID> per owner request.',
    'data'        => ['metal_type' => '<METAL>', 'tier' => 2],
]);
```

## Step 5 — Monitor parity

After opt-in, the shop's first manual rate save for the new metal writes both to:
- `shop_daily_metal_rates` — legacy columns (`gold_24k_rate_per_gram`, `silver_999_rate_per_gram` — Tier 2 metals are NOT written here)
- `shop_daily_metal_rate_entries` — new table (Tier 2 rates land here only)

**Important asymmetry during Stage A:** the `rates:reconcile-shadow-write` parity command compares the legacy gold/silver columns to the new entries table. Tier 2 metals appear only in the new table; they will NOT show as parity mismatches because the command only iterates gold/silver from the legacy side.

Run the command to verify:
```bash
php artisan rates:reconcile-shadow-write --shop=<SHOP_ID>
```

Expected output:
- `Parity proven` if gold/silver are in sync
- Tier 2 entries (platinum/copper) silently present in `shop_daily_metal_rate_entries` and consumed by mobile dashboard, but not part of the parity check

## Reversal — disabling a Tier 2 metal

This requires care. A Tier 2 metal cannot be disabled if there are open records referencing it (in-stock items, open lots, open job orders). Direct DB disable would orphan those records.

If disable is genuinely needed:
1. Check for blocking records:
   ```sql
   SELECT COUNT(*) FROM items WHERE shop_id = <ID> AND metal_type = '<METAL>' AND status = 'in_stock';
   SELECT COUNT(*) FROM metal_lots WHERE shop_id = <ID> AND metal_type = '<METAL>' AND fine_weight_remaining > 0;
   SELECT COUNT(*) FROM job_orders WHERE shop_id = <ID> AND metal_type = '<METAL>' AND status IN ('issued', 'partial_return');
   ```
2. Liquidate or transfer all blocking records first.
3. Update the row to disabled (do NOT delete the row — preserves audit trail):
   ```sql
   UPDATE shop_enabled_metals
   SET enabled = FALSE,
       disabled_at = NOW(),
       disabled_by_user_id = <OPERATOR_USER_ID>,
       notes = COALESCE(notes, '') || ' | Disabled <DATE> per owner request.',
       updated_at = NOW()
   WHERE shop_id = <ID> AND metal_type = '<METAL>';
   ```

Historical records (finalized invoices, locked lots) referencing the metal are NOT affected — they remain visible in reports, locked at the trigger level.

## Constitutional integrity

This playbook honors:
- **Article XIII** — Tier 2 enabled via the only authoritative surface (`shop_enabled_metals` + `MetalRegistry`)
- **Article XIV** — Tier 2 rates are manual valuations; no automated process touches them
- **Article XV** — `MetalRegistry` is the single source of truth; controllers/services consult it; no metal literals appear in business logic for the new metal
