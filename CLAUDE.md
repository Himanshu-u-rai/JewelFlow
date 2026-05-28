# JewelFlow Developer Reference

## Constitutional Foundation

All accounting, ledger, and trigger rules live in **[CONSTITUTION.md](CONSTITUTION.md)**. Read it before touching any migration, service, or model that handles financial data.

Key articles:
- **Article I** — ImmutableLedger + assertShopLockForDate are inviolable
- **Article IX.A** — 17 constitutionally-protected DB triggers (must never be disabled)
- **Article IX.B** — Never-disable rule for triggers
- **§2** — Forbidden patterns (raw UPDATE, forceFill on finalized, DISABLE TRIGGER)
- **§5** — Intentional historical NULLs (don't backfill these 9 columns)
- **§7** — GST truth path (single authoritative path through GstRateResolver)

## Reconciliation Visibility

Reconciliation commands (`vault:reconcile`, `karigar:reconcile`, `shop:detect-stuck`, `returns:validate`) are **read-only**. They exit with code 1 if discrepancies are found but make no DB writes. See CONSTITUTION.md §2 Pattern F5.

Any correction must go through the service layer as an explicit compensating entry. See CONSTITUTION.md §3 Boundary Doctrine.

## Common Pitfalls

**PostgreSQL boolean bulk UPDATE**
Always use `DB::raw('true')` and `DB::raw('false')` in bulk `->update()` calls. Never use PHP `true`/`false` literals — PostgreSQL rejects 0/1 for boolean columns.

```php
// ✗ WRONG — crashes on PostgreSQL
GstCategory::whereNotIn('id', [$id])->update(['is_default' => false]);

// ✓ CORRECT
GstCategory::whereNotIn('id', [$id])->update(['is_default' => DB::raw('false')]);
```

**Turbo Drive frame redirect forms**
All forms inside a `<turbo-frame>` that perform redirects to full pages must have `data-turbo-frame="_top"` on the `<form>` element.

```blade
{{-- ✗ WRONG — shows "Content missing" if this form redirects --}}
<form method="POST" action="...">

{{-- ✓ CORRECT --}}
<form method="POST" action="..." data-turbo-frame="_top">
```
