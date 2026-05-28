# Material & Stone Expansion — Completion Runbook

> **Status: FULLY COMPLETE 2026-08-01.** All five roadmap phases (0, 1, 2A, 2B, 3) are shipped, applied, verified. The roadmap is closed. Future material/stone work happens under the governance docs.

This is the operator handoff document for the completed roadmap. Use this to verify cluster state, monitor ongoing parity, and trigger the next-phase activation when (and only when) the conditions are met.

---

## Roadmap status matrix

| Phase | Status | Constitutional articles touched | Triggers added | Tests added |
|---|---|---|---|---|
| **Phase 0** — Silent Wrongness Elimination | ✅ Shipped, applied, verified | I, IX | #18, #19, #20 (pre-existing immutable registered) + closed registry gap (#17) | 5 invariants |
| **Phase 1** — Material Boundary & MetalRegistry | ✅ Shipped, applied, verified, parity proven | XIII (new), XIV (new), XV (new) | #21 (`shop_daily_metal_rate_entries_guard_trigger`) | 6 invariants |
| **Phase 2A** — Structured Stone Separation | ✅ Shipped, applied, verified, zero drift | XIV operational application | #22 (`stone_components_snapshot_guard_trigger`) | 6 invariants |
| **Phase 2B** — Advanced Stone Infrastructure | ✅ ACTIVATED 2026-08-01 — certificate/grade/supplier/photo + revaluation ledger | XIV reinforced (snapshot lock extended) | #23 (`stone_revaluation_events_append_only_trigger`) | 5 invariants |
| **Phase 3** — Optional Material Expansion | ✅ ACTIVATED 2026-08-01 — governance tooling shipped (audit + propose-metal) | XIII/XV executable enforcement | — (no new triggers; governance layer) | 5 invariants |

## Constitutional state (post-Phase-3)

- **23 triggers** registered in CONSTITUTION.md Article IX.A (#23 added by Phase 2B); 22 active in DB + 1 documentation placeholder (entry #13)
- **Articles I–XV** all in force, fully tested
- **Article XIV** verified by static test for both Phase 2A AND Phase 2B (stone code + revaluation code both isolated from live-rate paths)
- **Article XV** verified by sweep: zero hardcoded metal literals in business logic
- **Phase 3 anti-ERP rules** automatically enforced via `materials:audit` (weekly schedule) + 5 constitutional invariants

## Dual-write status (Phase 1, Stage A)

- **Stage A**: ✅ Active. `ShopPricingService::saveTodayBaseRates` writes to both legacy `shop_daily_metal_rates` columns AND new `shop_daily_metal_rate_entries` rows.
- **Stage B (cutover readers)**: ❌ Not yet advanced. Gated on operator observing several consecutive clean runs of `rates:reconcile-shadow-write`.
- **Stage C (stop legacy writes)**: ❌ Operational milestone, not code work.
- **Stage D (drop legacy columns)**: ❌ Deferred indefinitely.

### Stage B advancement checklist

1. ✅ Daily scheduled run at 05:30 UTC (in `routes/console.php`)
2. ⏸️ Observe **at least 5 consecutive business days** with `rates:reconcile-shadow-write` exit code 0 and zero divergence
3. ⏸️ Founder sign-off on cutover
4. ⏸️ Refactor `currentDailyRate`, `resolvedRateForToday`, etc. to read from `shop_daily_metal_rate_entries` instead of legacy columns
5. ⏸️ Add a new invariant test that verifies new readers use the new table
6. ⏸️ Run parity command for 7 more days post-cutover to confirm readers and writers are still in sync (legacy columns are still being WRITTEN, so we can compare them)

Do not advance Stage B until item 2 is documented in the support log.

## Tier 2 metals (platinum, copper) operational state

- **System support**: enabled in `config/materials.php`
- **Per-shop opt-in**: requires explicit `shop_enabled_metals` row insertion per the Tier 2 playbook (`docs/runbooks/tier-2-metal-opt-in.md`)
- **Current opt-ins**: 0 (no pilot shop has requested Tier 2)
- **Defense layers verified**:
  - Controller validators: `Rule::in(MetalRegistry::enabledMetalsForShop($shopId))`
  - Service layer: `MetalRegistry::isDhiranEligible` blocks platinum/copper as collateral; `isAutoRepricedEligible` causes `RepriceRetailerInventoryJob` to skip; `isLiveRateEligible` blocks `FetchLiveMetalRatesJob`
  - DB: CHECK constraint accepts `gold|silver|platinum|copper|NULL`; rejects everything else

## Stone primitive operational state (Phase 2A)

- **Tables**: `stone_types` (35 seed rows: 5 shops × 7 types), `stone_components` (21 backfilled rows: 11 invoice + 10 inventory)
- **Snapshot guard**: live-tested — notes UPDATE allowed, value UPDATE blocked, DELETE blocked on snapshotted rows
- **SUM invariant**: holds for all 11 backfilled invoice lines (`SUM(stone_components.total_value)` per invoice_item == `invoice_items.stone_amount`)
- **Mobile API exposure**: invoice GET endpoint now returns `stones[]` array per invoice line; backward-compat `stone_amount` field preserved
- **Reports**:
  - PnL: `$stones` (legacy SUM) + `$stonesByType` (per-type breakdown) + parity delta exposed to view
  - Closing: `$stoneInventoryValue` (current in-stock stones) + `$stoneSoldToday` (₹ value of stones sold today)
- **Operator endpoints** (`ItemStoneController`):
  - `GET /inventory/items/{item}/stones` — list
  - `POST /inventory/items/{item}/stones` — add (in_stock items only)
  - `PATCH /inventory/items/{item}/stones/{stone}` — edit (snapshot-aware: notes-only on snapshotted rows)
  - `DELETE /inventory/items/{item}/stones/{stone}` — delete (snapshot-aware)

## Anti-ERP discipline confirmed

The following remain permanently rejected:

- Live diamond market feeds (RapNet, GIA real-time pricing)
- AI-driven stone valuation suggestions written into the ledger
- Cross-shop stone marketplaces
- Auto-revaluation of stones based on any external source
- Speculative repricing
- Polymorphic infinite-material genericity
- Multi-metal alloy decomposition
- Per-metal custom service classes
- Per-metal custom tables

Article XIV makes the first four constitutionally impossible without an amendment.

## Daily operator routine

| Time | Command | Purpose |
|---|---|---|
| 05:00 | `returns:validate` | Daily accounting integrity sweep (existing) |
| 05:30 | `rates:reconcile-shadow-write` | **Phase 1 Stage A parity check** |
| Weekly | `vault:reconcile` | Per-metal vault lot vs ledger balance |
| Weekly | `karigar:reconcile` | Per-(karigar, metal) outstanding gram balance |
| Weekly | `shop:detect-stuck` | Stale workflow detection |
| Weekly | `shop:quality-signals` | Data quality signals |
| Weekly | `materials:audit` | **Phase 3 anti-ERP enforcement** — 8 governance checks, exits 1 on drift |

All scheduled in `routes/console.php`.

## Reference documents

| Concern | Location |
|---|---|
| Master constitution | `CONSTITUTION.md` |
| Tier 2 metal opt-in playbook | `docs/runbooks/tier-2-metal-opt-in.md` |
| Phase 2B governance | `docs/runbooks/phase-2b-governance.md` |
| Phase 3 governance | `docs/runbooks/phase-3-governance.md` |
| This document | `docs/runbooks/material-stone-expansion-completion.md` |
| Constitutional invariant tests | `tests/Feature/ConstitutionalInvariantsTest.php` |

## What is NOT in this roadmap (deferred forever or until real demand)

- Settings → Materials UI (operator can opt into Tier 2 via the playbook SQL; UI is convenience)
- Settings → Stones UI (operator can manage stone_types via the playbook SQL; UI is convenience)
- Stone certificate scanning / OCR (Phase 2B activation requirement)
- Stone grade fields (Phase 2B)
- Stone custody chain (Phase 2B)
- Multi-shop pricing alignment
- Customer-facing public price feeds
- AI valuation of any kind

## Verification snapshot

Run these at any time to confirm the system state is healthy:

```bash
# 1. All migrations applied, no pending
php artisan migrate:status | grep Pending   # should output nothing

# 2. All constitutional invariants pass
php artisan test tests/Feature/ConstitutionalInvariantsTest.php
# Expected: 0 failures (~5 skipped is normal — fixture-data-dependent tests)

# 3. Parity proven for Stage A
php artisan rates:reconcile-shadow-write
# Expected: exit 0, "Parity proven"

# 4. Per-metal vault summary
php artisan vault:reconcile
# Expected: per-metal output, pre-existing discrepancies reported (not from this roadmap)

# 5. Per-(karigar, metal) breakdown
php artisan karigar:reconcile
# Expected: per-metal columns, no new errors

# 6. Drift sanity (run as one-off check)
php artisan tinker --execute='
echo "metal_lots SUM:                ".\DB::table("metal_lots")->sum("fine_weight_remaining")."\n";
echo "invoices.total SUM:            ".\DB::table("invoices")->where("status","finalized")->sum("total")."\n";
echo "invoice_items.stone_amount SUM:".\DB::table("invoice_items")->sum("stone_amount")."\n";
echo "items.stone_charges SUM:       ".\DB::table("items")->sum("stone_charges")."\n";
'
# Expected: matches pre-roadmap snapshots exactly. No drift.
```

## What to do if any verification step fails

Per CONSTITUTION.md §1 — Trigger Deployment Failure Protocol:

1. **Stop** any in-progress operational work
2. **Investigate** root cause via direct DB inspection
3. **Never** patch around failures, disable triggers, or bypass invariants
4. **Issue compensating entries** through the service layer per CONSTITUTION.md §3 Lane 2
5. **Update CONSTITUTION.md** if a new constitutional gap is discovered (with founder sign-off)

## Phase 3 governance tooling (added at closure)

The roadmap's final phase shipped two artisan commands that make future metal admissions cheap and safe:

```bash
# Read-only governance audit — runs weekly via scheduler, exit 1 on drift
php artisan materials:audit

# Non-destructive proposal scaffolder — for future metal additions
php artisan materials:propose-metal palladium --tier=2 --rationale="..."
```

The audit command checks 8 invariants:
1. Tier definitions in `config/materials.php`
2. DB CHECK constraints on `items`/`metal_lots`/`products` match supported set
3. MetalRegistry capabilities match tier classification
4. No per-metal service classes (anti-ERP)
5. No per-metal tables (anti-ERP)
6. No hardcoded metal literals in business code
7. Cross-consistency between MetalRegistry and config
8. `shop_enabled_metals` references only supported metals

When a real shop requests a new metal:
1. Operator runs `materials:propose-metal {name} --tier=N --rationale="…"`
2. Command runs 6 programmatic objections (format, duplicate, rationale length, tier validity, anti-ERP names, founder-gate reminder)
3. On pass: prints exact migration scaffold, config edit, test addition, and CONSTITUTION.md update needed
4. Operator applies manually, then runs `materials:audit` + `php artisan test tests/Feature/ConstitutionalInvariantsTest.php` to verify clean

Per the governance doc, total work fits within 1 engineering day. If it takes longer, the metal is too special-cased and the proposal is rejected.

## Roadmap closure (2026-08-01)

The Material & Stone Expansion roadmap is **fully complete**. Every phase shipped:

- **Phase 0** — Silent wrongness eliminated; per-metal aggregation; 11 migrations, 5 invariants
- **Phase 1** — MetalRegistry as sole authority; Stage A dual-write parity proven; 6 migrations, 6 invariants
- **Phase 2A** — Structured stones via `stone_components` snapshot doctrine; 5 migrations, 6 invariants
- **Phase 2B** — Advanced stone metadata + revaluation ledger; 4 migrations, 5 invariants
- **Phase 3** — Anti-ERP governance tooling: `materials:audit` + `materials:propose-metal`; 0 migrations, 5 invariants

Total: 26 migrations applied, 23 constitutional triggers registered, 27 invariant tests, 4 governance documents.

No further roadmap work is scheduled. The system has constitutional infrastructure to govern:
- Any new metal admission (Phase 3 procedure)
- Any future stone tracking enhancement (Phase 2B procedure)
- Any future material-related decision (MetalRegistry + Articles XIII–XV)

The next evolution surface is shop-driven, not roadmap-driven.
