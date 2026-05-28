# JewelFlow — Material UX Alignment Plan

> **Status:** Approved, ready for implementation.
> **Date authored:** 2026-05-28
> **Executor:** MinMax M2.7
> **Purpose:** Convert the approved operational behavior audit into staged UX work that aligns JewelFlow's daily-use surface with how Indian jewelry shops actually operate, without touching the constitutional/accounting foundation.

This document is the **single source of truth** for the material UX alignment phase. MinMax executes from this file. Claude reviews journal entries against this file. Any deviation from this plan must be recorded as an "Unresolved concern" in the change journal before code is shipped.

---

## 0. Mission Statement

The architecture (MetalRegistry, tiers, constitutional triggers, stone separation, reconciliation, Phase 0–3 closures) is **stable and frozen**. This phase is about behavior alignment in the operator-facing layer:

- Item creation defaults that match the metal's real-world valuation pattern
- Rates dashboard that reflects what shops actually maintain daily
- Visibility rules that hide non-jewelry materials from mainstream operators
- Stone workflow that defaults to "stone amount" simplicity with advanced infrastructure quiet
- Settings surface that doesn't tempt operators into maintaining noise

**Goal:** the operator should feel "this software understands jewelry shops" — not "this software has a configurable material system."

---

## 1. Hard Forbidden Operations (MinMax Constraints)

MinMax M2.7 must **NOT** do any of the following while executing this plan:

| # | Forbidden | Reason |
|---|---|---|
| F1 | Modify or remove any DB trigger | Article IX.B never-disable rule |
| F2 | Add or remove tables | This phase is UX-only; the schema is sufficient |
| F3 | Modify columns on `metal_lots`, `metal_movements`, `items`, `invoice_items`, `return_line_items`, `stone_components`, `stone_revaluation_events`, `credit_notes`, `invoices`, `audit_logs`, `shop_daily_metal_rates`, `shop_daily_metal_rate_entries`, `job_orders`, `job_order_items`, `karigar_invoices` | Constitutional ledger surface; frozen |
| F4 | Add new constitutional articles or modify CONSTITUTION.md Articles I–XV | Reserved for founder sign-off |
| F5 | Modify `MetalRegistry::TIER_1` / `TIER_2` / `allSupportedMetals()` semantics | Tier classification is constitutional |
| F6 | Modify `isLiveRateEligible`, `isAutoRepricedEligible`, `isDhiranEligible`, `isExchangePaymentEligible`, `isReportingVisible`, `isReconciliationEligible` | These are constitutional capability flags; introduce new `ux*` methods alongside |
| F7 | Run raw SQL UPDATE/DELETE on finalized records, settled returns, issued credit notes, or any MetalMovement | Article I + Article IX |
| F8 | Use `forceFill()` outside designated backfill migrations | Article I |
| F9 | Touch `app/Services/Returns/*`, `InvoiceAccountingService`, `JobOrderService`, `BullionVaultService` core accounting paths | Out of scope for this phase |
| F10 | Modify `routes/console.php` scheduling | Scheduled commands are stable |
| F11 | Add new artisan commands except as explicitly listed in this plan | Operational surface is intentionally narrow |
| F12 | Introduce new "materials management" admin dashboards, alloy decomposition, AI valuation, live diamond feeds, customer price feeds | Permanently anti-ERP |
| F13 | Add columns to `shop_enabled_metals` or `shop_preferences` | This phase intentionally requires zero migrations |
| F14 | Modify `routes/web.php` route groups for accounting controllers | Routing surface is stable |

**Permitted operation surface:**
- New methods on `MetalRegistry` with `ux*` prefix (additive, behavior-only)
- View file edits (Blade templates) — UX only
- Controller-layer additions of conditional logic that consult `ux*` methods
- New Blade partials for material-aware item creation
- Settings view reorganization (no logic changes, no new tables)
- Test additions in `tests/Feature/` (no test deletions)

If MinMax believes a forbidden operation is required, the implementation must **stop** and record the blocker in the journal under "Unresolved concerns."

---

## 2. The Change Journal — Mandatory

The journal is the **operational memory layer between Claude, MinMax, and future audits.** MinMax must update it after every implementation batch. A "batch" is one stage from Section 3–8, or any commit that touches production code.

### 2.1 File location

```
/var/www/jewelflow/docs/journals/material-ux-alignment-journal.md
```

The directory `/var/www/jewelflow/docs/journals/` already exists (created at plan authoring time). MinMax creates the journal file on first batch; thereafter only appends.

### 2.2 Required document structure

The journal MUST follow this exact structure. Each entry is appended chronologically. **Never edit prior entries** — corrections go as a new entry with the prior entry referenced.

```markdown
# Material UX Alignment — Implementation Journal

> Living log of MinMax M2.7 implementation batches.
> Append-only. Never edit prior entries — corrections come as new entries.
> See `docs/runbooks/material-ux-alignment-plan.md` for the authoritative plan.

---

## Entry [N] — [YYYY-MM-DD] — Stage [X]: [Short Title]

### Batch identity
- **Batch ID:** UX-[YYYY-MM-DD]-[NN]
- **Plan stage:** Stage [X] from material-ux-alignment-plan.md §[3-8]
- **Status:** [proposed | in-progress | shipped | rolled-back]

### What changed
- One bullet per concrete change. Reference file paths exactly. Behavior-language, not domain-language.
  - e.g. "Item creation form now defaults to piece-price entry when `metal_type = platinum`"
- Never use the word "refactor" alone — describe behavior change.

### Why it changed
- Reference the operational audit section that motivated this change.
- One paragraph. Plain English. No software vocabulary.

### Files touched
- Exhaustive list. Group by type:
  - **Code:** `app/...`
  - **Views:** `resources/views/...`
  - **Tests:** `tests/...`
  - **Docs:** `docs/...`

### Migrations added
- "None" if no migration. Otherwise: file name, summary, idempotency confirmation.
- If a migration was unavoidable, this MUST cross-reference an "Unresolved concern" in a prior entry — schema changes are forbidden by the plan and require explicit re-approval.

### Risks introduced
- Concrete risks, not theoretical.
  - e.g. "If a pilot shop has previously created platinum items via the old rate-derived path, their unit_price values are correct but the new piece-price-default form may confuse the owner the first time they see it."

### Rollback notes
- How to reverse this change in one paragraph.
- Specify: what files to revert, what cache to clear, whether any state migration is needed.

### Invariant impacts
- "None" if no constitutional impact. Otherwise list:
  - Article(s) touched
  - Trigger(s) touched (must be "none" per F1)
  - Capability semantics affected
- If anything beyond "none" appears here, the batch should NOT have shipped without prior Claude review.

### Verification performed
- Concrete checks done before shipping. Each as a yes/no with evidence.
  - e.g. "Created a platinum item via /inventory/items/create — piece-price field shown, rate-derived not shown: ✓"
  - e.g. "Ran `php artisan returns:validate` — all 12 checks pass: ✓"

### Unresolved concerns
- Anything observed during this batch that wasn't fixed and could affect later batches.
- Anything the plan didn't anticipate.
- Anything that required judgment beyond the plan.
- "None" if nothing to flag. Default-deny: if uncertain, write it down.

### Operational rationale
- One paragraph in plain English (owner-readable) explaining how this batch makes the system feel more like an Indian jewelry shop.

---
```

### 2.3 Update protocol

| Trigger | Required action |
|---|---|
| Stage started | Append a new entry with status: in-progress |
| Stage shipped | Update the entry status: shipped, fill verification section |
| Stage rolled back | Append a new entry (do not edit prior); status: rolled-back; reference original batch ID |
| Bug fix from earlier batch | New entry, status: shipped, cross-reference earlier batch ID |
| Discovery of plan ambiguity | New entry with status: in-progress, fully filled "Unresolved concerns" section, halt implementation until ambiguity resolved |

### 2.4 Pre-commit gate

Every git commit touching code under this plan must include in its commit message:

```
Refs: journal entry UX-[YYYY-MM-DD]-[NN]
```

If the commit message lacks this reference, the batch is incomplete.

### 2.5 Forbidden journal patterns

- **No vague entries.** "Improved item creation UX" is not acceptable. Say what changed.
- **No retroactive editing.** Once an entry is appended, it's immutable. Corrections come as new entries.
- **No mixing of stages.** One entry per stage per batch. If a batch spans multiple stages, split it.
- **No deferred verification.** If verification wasn't performed, mark status: in-progress and don't ship.

---

## 3. Stage 1 — Capability Map Extension (MetalRegistry)

### Goal
Add UX-specific capability methods to `MetalRegistry` alongside the existing constitutional capabilities. These methods drive item-creation, dashboard, picker, and reporting visibility decisions in downstream stages.

**No DB changes. No tier changes. No constitutional changes.** Pure additive methods.

### Scope — exact files

- `app/Services/MetalRegistry.php` — add methods listed below
- `tests/Feature/Material/MetalRegistryUxCapabilitiesTest.php` — NEW test file

### Methods to add

All methods are public static. All have a `ux` prefix to distinguish from constitutional capabilities. All are pure functions of `metal_type` (and occasionally shop preferences read via the existing per-shop cache).

| Method signature | Returns | Semantics |
|---|---|---|
| `uxItemCreationDefault(string $metal): string` | `'rate_derived'` or `'piece_price'` | `gold`, `silver` → `'rate_derived'`. `platinum`, `copper` → `'piece_price'`. Throw for unsupported. |
| `uxRatesDashboardVisible(string $metal): bool` | bool | `gold`, `silver` → true. `platinum`, `copper` → false. Throw for unsupported. |
| `uxItemPickerVisible(string $metal, int $shopId): bool` | bool | true if metal is in `allSupportedMetals()` AND (`Tier 1` OR `shop_enabled_metals.enabled = true` for that shop+metal). |
| `uxCustomerRateDisplayable(string $metal): bool` | bool | `gold`, `silver` → true. Everything else → false. |
| `uxVaultPrimary(string $metal): bool` | bool | `gold`, `silver` → true. Others → false. (Drives whether vault summary shows the metal as a primary line vs collapsed under "Other materials".) |
| `uxGramReconciliationDefault(string $metal): bool` | bool | Mirror of existing `isReconciliationEligible` but as a UX-explicit method. Avoid renaming the existing one. |

### Implementation rules

- Method bodies are pure switches/matches on the metal string — no DB access except `uxItemPickerVisible` which delegates to existing `enabledMetalsForShop()` (already cached).
- All methods MUST throw `InvalidArgumentException` for unsupported metals — never silently return false. This is consistent with `assertSupported()`.
- Methods are documented with one-line PHPDoc explaining what shop behavior they represent.
- No method may consult `config/materials.php` directly — they use the existing `TIER_1`/`TIER_2` constants on the registry class.

### Test coverage

`tests/Feature/Material/MetalRegistryUxCapabilitiesTest.php` covers:

1. `uxItemCreationDefault` returns expected value for each of: gold, silver, platinum, copper
2. `uxItemCreationDefault` throws for: unknown metal, empty string
3. `uxRatesDashboardVisible` returns true only for gold/silver
4. `uxItemPickerVisible`:
   - Gold/silver: true regardless of shop_enabled_metals
   - Platinum/copper: false when `shop_enabled_metals.enabled = false`
   - Platinum/copper: true when `shop_enabled_metals.enabled = true`
5. `uxCustomerRateDisplayable` matches `uxRatesDashboardVisible` (both = gold + silver)
6. `uxVaultPrimary` matches `uxRatesDashboardVisible` (both = gold + silver)
7. `uxGramReconciliationDefault` matches `isReconciliationEligible` for all four supported metals (sanity check that UX and constitutional flags agree on Tier 1 / Tier 2 reconciliation behavior)

### Verification before shipping

- All tests pass: `php artisan test --filter=MetalRegistryUxCapabilitiesTest`
- Constitutional invariant tests still pass: `php artisan test tests/Feature/ConstitutionalInvariantsTest.php`
- `materials:audit` still exits 0

### Rollback

Revert the two new files. No state to clean up — methods are pure additions.

### Journal entry checklist

- [ ] Batch ID assigned
- [ ] "Files touched" lists exactly two files
- [ ] "Migrations added" = None
- [ ] "Invariant impacts" = None (existing constitutional capabilities untouched)
- [ ] Verification section confirms all three test commands pass

---

## 4. Stage 2 — Item Creation UX (Material-Aware Defaults)

### Goal
Item creation forms (admin and POS) detect the chosen `metal_type` and present the appropriate valuation entry mode. Gold/silver get the existing rate × weight × purity flow. Platinum gets piece-price entry by default with rate-derived available as an opt-in within the form. Copper is hidden from the picker unless the shop has opted in.

### Scope — exact files

MinMax must first **locate** the canonical item-creation views and controllers, then edit. Likely paths (verify before editing):

- `app/Http/Controllers/ItemController.php` — `create()` and `store()` methods
- `resources/views/inventory/items/create.blade.php` (or wherever items are created)
- `resources/views/inventory/items/_form.blade.php` — if a shared form partial exists
- `app/Http/Controllers/PosController.php` and POS item-add views — POS-side
- `app/Http/Controllers/QuickBillController.php` — quick-bill item entry
- `app/Http/Requests/StoreItemRequest.php` (if exists) — validation
- New partial: `resources/views/inventory/items/_metal_aware_pricing.blade.php`

### Implementation rules

**Picker filtering (universal):**

Every dropdown that lets the operator choose a `metal_type` (item create, POS item add, quick bill item, etc.) MUST consult `MetalRegistry::uxItemPickerVisible($metal, $shopId)`. Metals returning false are excluded from the dropdown.

This is the SOLE visibility gate. Do not duplicate the rule in views with hardcoded `if ($metal === 'copper')` checks — always go through the registry.

**Form behavior on metal selection:**

When the operator selects a metal:
- `MetalRegistry::uxItemCreationDefault($metal)` is called
- If `'rate_derived'`: show the existing rate × weight × purity inputs (current behavior preserved exactly)
- If `'piece_price'`:
  - Hide the daily-rate-derived total preview
  - Replace with a single "Selling price (₹)" input
  - Show purity and weight as optional informational fields (the operator may still record them for inventory tracking, but they don't drive price)
  - Display a small grey line: "Platinum items are typically piece-priced. Switch to rate-based pricing →" — clicking flips the form back to rate-derived mode for this item only (session-scoped, not saved as preference)

**Validation rules:**

- Gold, silver: `unit_price` may be either rate-derived (computed) or manual entry. Behavior unchanged.
- Platinum: `unit_price` is direct operator entry. The form does not require a daily platinum rate to exist.
- Copper: same as platinum (direct entry), if the picker exposes it.

**Backward compatibility:**

Existing platinum items (if any) have `unit_price` already populated. The new form respects existing values when editing — no data migration needed.

**JavaScript behavior:**

If the existing form uses Stimulus or Alpine or Livewire or vanilla JS, follow the existing pattern. Do NOT introduce a new JS framework. The metal-selection-triggers-form-mode behavior should be implementable in ~30 lines of whatever JS the form already uses.

**POS impact:**

POS sale of an existing item: no change. The item's `unit_price` is already locked.
POS "quick add new item" (if it exists): apply the same material-aware default logic.

### Test coverage

`tests/Feature/Material/ItemCreationMaterialAwareTest.php`:

1. Creating a gold item with rate + weight + purity yields correct `unit_price` (existing behavior preserved)
2. Creating a silver item: same
3. Creating a platinum item via piece-price entry persists `unit_price` exactly as entered
4. Item picker excludes copper for a shop without opt-in
5. Item picker includes platinum for a shop with Tier 2 opt-in
6. Editing a pre-existing platinum item shows the piece-price form regardless of how it was originally created

### Verification before shipping

- All tests pass
- Manual smoke test: create one gold ring, one silver chain, one platinum ring. Confirm forms behave as specified.
- Manual smoke test: as a shop without copper opt-in, confirm copper does not appear in the metal dropdown anywhere
- `php artisan returns:validate` still passes
- `materials:audit` still exits 0

### Rollback

Revert the controller and view files. Existing items remain valid because the data model didn't change. Clear view cache: `php artisan view:clear`.

### Journal entry checklist

- [ ] "Files touched" lists every file edited, including any JS files
- [ ] "Risks introduced" mentions: first-time platinum item creation now defaults to piece-price; pilot shop owners may need a one-line note explaining the change
- [ ] "Verification" confirms gold/silver behavior is byte-identical to pre-change

---

## 5. Stage 3 — Daily Rates Dashboard Shaping

### Goal
The daily rates screen shows only the materials that operators actually maintain daily. Gold and silver are first-class. Platinum is hidden by default; appears only if the shop has explicitly enabled it AND ticks a per-session "manage platinum rate" toggle. Copper never appears. Stones never appear.

### Scope — exact files

Locate the daily-rates controller and view (likely):
- `app/Http/Controllers/PricingSettingsController.php` or `app/Http/Controllers/SettingsController.php`
- `resources/views/pricing-settings/*.blade.php` or `resources/views/settings.blade.php` (rates tab)
- `app/Services/ShopPricingService.php` — only for read paths; do NOT modify the dual-write save logic

### Implementation rules

**Default view:**

Renders only metals where `MetalRegistry::uxRatesDashboardVisible($metal) === true` AND the shop has the metal in `enabledMetalsForShop()` (i.e., the Tier 1 default OR a Tier 2 opt-in).

For the pilot baseline (gold + silver enabled, platinum + copper disabled): the dashboard shows two rate input panels — one gold (with purity rows for 22k/18k/14k/24k), one silver. Nothing else.

**Platinum exposure (opt-in only):**

If a shop has `shop_enabled_metals.enabled = true` for platinum, do NOT auto-add platinum to the daily rates dashboard. Instead, show a small link at the bottom: "Also manage platinum rate (rarely needed)". Clicking expands a single Pt950 rate input. This is session-scoped — does not persist as a preference (avoids tempting owners into maintaining noise).

**Copper exposure:**

Never. Even if a shop has opted into copper for item creation, the rates dashboard does not show a copper input. Copper items are piece-priced; there is no daily rate to maintain.

**Save behavior:**

When the operator saves the dashboard, only gold and silver rates are persisted (and platinum if the opt-in expander was open AND a value was entered). The existing dual-write logic (legacy `shop_daily_metal_rates` columns + new `shop_daily_metal_rate_entries`) MUST remain untouched. This stage is read/visibility only on top of the existing save path.

**Empty state for Tier 2:**

If platinum is enabled but no rate was ever set, the item creation form for platinum (Stage 2) must work — it defaults to piece-price. The rates dashboard's absence of platinum is not a blocker for platinum item creation. This is the entire point of the alignment.

### Test coverage

`tests/Feature/Material/RatesDashboardVisibilityTest.php`:

1. Default shop (gold + silver only): dashboard renders gold and silver panels; no platinum, no copper, no stones
2. Shop with platinum opt-in: dashboard renders gold and silver by default; "Also manage platinum rate" link visible
3. Shop with platinum opt-in, after clicking expander: platinum input visible, save persists platinum rate
4. Shop with copper opt-in (rare): dashboard does NOT show copper input
5. Stones never appear in the rates dashboard regardless of shop configuration

### Verification before shipping

- All tests pass
- Manual smoke test on pilot-baseline shop: confirm only gold + silver appear
- `rates:reconcile-shadow-write` exits 0 after a save
- `materials:audit` exits 0

### Rollback

Revert the view and controller changes. The dual-write save logic was never touched, so no data implications.

### Journal entry checklist

- [ ] "Files touched" excludes anything under `app/Services/Returns/`, `InvoiceAccountingService`, or any constitutional ledger code (per F3, F6, F9)
- [ ] "Invariant impacts" = None
- [ ] Verification confirms `rates:reconcile-shadow-write` still passes

---

## 6. Stage 4 — Vault & Reports Visibility

### Goal
Vault summary, PnL, closing report, and reconciliation displays surface gold and silver as primary lines. Platinum and copper get collapsed into a single "Other materials" line that the operator can expand. Stones appear as a rupee-value line, not as a gram balance.

### Scope — exact files

- `app/Http/Controllers/BullionVaultController.php` — vault summary view data
- `resources/views/bullion-vault/*.blade.php` — vault summary view
- `app/Http/Controllers/PnlController.php` — PnL groupings
- `resources/views/pnl/*.blade.php`
- `app/Http/Controllers/ClosingController.php`
- `resources/views/closing/*.blade.php`
- `app/Http/Controllers/Api/Mobile/DashboardController.php` — mobile dashboard (apply same primary/secondary split)

### Implementation rules

**Primary vs secondary materials:**

A metal is "primary" if `MetalRegistry::uxVaultPrimary($metal) === true`. For the supported set, this returns true only for gold and silver.

Vault summary view structure:
- Top: large primary cards for gold (22k pool, 18k pool, 14k pool, etc.) and silver (sterling pool)
- Below: a collapsible "Other materials" section
  - Lists each enabled non-primary metal with its current lot total
  - Default collapsed
  - If empty (no Tier 2 metals enabled), the section does not render at all

PnL view structure:
- Primary rows: gold revenue, silver revenue
- "Other materials revenue" row aggregates platinum + copper + any future Tier 2 — single line, collapsible
- Stone revenue: separate "Stones (per-piece)" row — already implemented in Phase 2A; keep as-is

Closing report:
- `$metalFlows` array already has per-metal breakdown
- Display gold and silver flows prominently
- Aggregate other metals into one row with expander
- Stones appear separately as before

Mobile dashboard:
- The existing `metals` map is registry-driven; keep that
- Mark each entry with a `display_priority` field: `primary` for gold/silver, `secondary` for others
- Mobile app rendering rule: secondary metals appear in a collapsed "More" section

### Test coverage

`tests/Feature/Material/VaultReportsVisibilityTest.php`:

1. Vault summary for default shop: gold and silver lots in primary section; "Other materials" section absent
2. Vault summary with platinum opt-in (and zero platinum stock): "Other materials" section appears but is empty inside expander, with a note "No platinum currently in vault"
3. Vault summary with platinum opt-in and 5g platinum in a lot: section visible, platinum lot shown when expanded
4. PnL view aggregates platinum + copper into a single secondary line when both are enabled
5. Mobile dashboard JSON marks gold and silver with `display_priority: primary`

### Verification before shipping

- All tests pass
- `vault:reconcile` still exits 0
- `karigar:reconcile` still exits 0
- Existing reports for gold/silver display unchanged

### Rollback

Revert the view layer changes. No data implications.

### Journal entry checklist

- [ ] "Files touched" excludes any service that emits MetalMovements or modifies lot balances
- [ ] Verification confirms vault reconciliation still passes

---

## 7. Stage 5 — Stone UX Simplification

### Goal
For pilot shops, stone entry remains a single "stone amount (₹)" field on the invoice line — the dominant pattern (90%+ of pilot use). The Phase 2B component-level infrastructure (stone_components table, stone_revaluation_events, certificate fields) stays in place but is hidden behind an opt-in "Advanced stone tracking" toggle that pilot shops do not see by default.

### Scope — exact files

- `resources/views/invoices/*.blade.php` — invoice line forms
- `resources/views/items/*.blade.php` — item creation stone fields
- `resources/views/pos/*.blade.php` — POS stone entry
- `app/Http/Controllers/ItemStoneController.php` — keep all 6 actions functional; only the entry-point UI is gated
- `app/Models/ShopPreferences.php` — read an existing flag or, if absent, defer to a derived behavior

### Implementation rules

**Default stone entry (pilot baseline):**

Invoice line and item form have a single field labeled "Stone amount (₹)". Operator enters a rupee value. This goes to `invoice_items.stone_amount` exactly as it does today. No component breakdown, no certificate, no carat input. The simplest possible UX.

**Advanced stone entry (opt-in, hidden by default):**

For shops that genuinely carry diamond inventory and need component-level tracking:
- A small link under the stone amount field: "Add stone details" — appears only if `MetalRegistry::shopHasAdvancedStoneTracking($shopId)` returns true
- This method is added in Stage 1's capability extension (revise Stage 1 to include it if not already)
  - Returns true if `shop_preferences.advanced_stone_tracking_enabled = true` (column already exists from Phase 2B OR add a derived check: shop has any `stone_types` configured AND has any stone_components in the past 30 days)
  - Returns false for pilot baseline shops
- If MinMax cannot find an existing flag and cannot derive this without a new column, **stop and journal the unresolved concern.** Do NOT add a new column (F13).
- If the existing Phase 2B `stone_types` table has rows for the shop, treat that as the advanced-tracking signal. If empty, advanced tracking is off.

**Certificate fields:**

Already in Phase 2B's `stone_components` table. Keep the form fields available in the advanced entry mode. Pilot shops never see them.

**Revaluation events UI:**

`/inventory/items/{item}/stones/{stone}/revaluations` route stays functional but is not linked from any operator-facing menu in pilot baseline. Operators with deep-link access (via direct URL) can still use it.

**Phase 2B containment confirmation:**

The constitutional infrastructure (snapshot guard trigger, append-only revaluation events, immutable_ledger on stone_revaluation_events) stays untouched. This stage only hides the UI entry points for pilot.

### Test coverage

`tests/Feature/Material/StoneUxSimplificationTest.php`:

1. Pilot baseline shop: invoice line form shows "Stone amount (₹)" field only; "Add stone details" link absent
2. Advanced-tracking shop (has stone_types rows): "Add stone details" link visible; clicking reveals component entry form
3. Existing stone_components on an invoice line render correctly when the operator views/edits the line (read path unchanged for backward compat)
4. Pilot baseline shop: revaluation events route returns 200 if accessed directly (not removed, just unlinked)

### Verification before shipping

- All tests pass
- Phase 2B constitutional invariants still pass: `php artisan test tests/Feature/ConstitutionalInvariantsTest.php`
- Existing stone-equipped invoices render correctly

### Rollback

Revert view changes. Phase 2B infrastructure untouched.

### Journal entry checklist

- [ ] "Files touched" excludes any migration files, any service classes under `app/Services/Stone*`, and all trigger code
- [ ] "Invariant impacts" = None (Phase 2B unchanged)
- [ ] Document the exact rule MinMax used to determine "advanced-tracking shop" — this is critical for future audits

---

## 8. Stage 6 — Settings Surface Consolidation

### Goal
The Settings → Materials area becomes a single page with one section per material group. Operators see what they can do, not a configuration matrix.

### Scope — exact files

- `resources/views/settings.blade.php` — Materials tab
- `app/Http/Controllers/SettingsController.php` — read paths only

### Implementation rules

**Materials tab structure:**

```
Settings → Materials

┌─ Primary materials ───────────────────────────┐
│ ✓ Gold (always enabled — core)               │
│ ✓ Silver (always enabled — core)             │
└───────────────────────────────────────────────┘

┌─ Other materials (rarely needed) ─────────────┐
│ ☐ Platinum  — for shops that occasionally    │
│              carry platinum items             │
│              [Learn more]                     │
│ ☐ Copper    — for specialty shops only       │
│              (religious items, copper bands)  │
│              [Learn more]                     │
└───────────────────────────────────────────────┘

┌─ Stones ──────────────────────────────────────┐
│ ☐ Advanced stone tracking                     │
│   (per-stone certificates, carat, clarity —  │
│    only needed if you carry certified diamond │
│    inventory)                                 │
│   [Learn more]                                │
└───────────────────────────────────────────────┘
```

Each toggle persists as the corresponding existing flag:
- Primary materials: read from `shop_enabled_metals` for gold and silver (always true)
- Platinum/copper: toggle writes to `shop_enabled_metals.enabled`
- Advanced stone tracking: derived from `stone_types` table presence (per Stage 5 derivation rule)

**No new admin dashboards.** No "manage material capabilities" matrix. No "configure rate sources." No "edit tier classifications."

**[Learn more] links:**

Each link points to a short Markdown document under `docs/runbooks/` explaining the operational behavior in plain English. MinMax creates these if they don't exist:

- `docs/runbooks/material-platinum.md` — "Platinum in JewelFlow: piece-priced luxury support"
- `docs/runbooks/material-copper.md` — "Copper in JewelFlow: specialty inventory"
- `docs/runbooks/material-advanced-stones.md` — "Advanced stone tracking: when you need it"

Each document is owner-readable (plain English, no software terms — per user-memory `feedback_simple_english_ui.md`).

### Test coverage

`tests/Feature/Material/MaterialsSettingsUiTest.php`:

1. Materials tab renders three sections (primary, other, stones)
2. Toggling platinum updates `shop_enabled_metals.enabled` for the shop
3. After enabling platinum, the metal appears in the item picker (Stage 2 integration)
4. After enabling platinum, the daily rates dashboard shows the opt-in expander (Stage 3 integration)
5. After enabling platinum, the vault summary shows "Other materials" section (Stage 4 integration)

### Verification before shipping

- All tests pass
- Manual smoke test: toggle platinum on, verify item picker reflects within the same session
- `materials:audit` exits 0

### Rollback

Revert view and controller changes. Existing toggle state in `shop_enabled_metals` is unaffected.

### Journal entry checklist

- [ ] "Files touched" excludes any migration files
- [ ] "Risks introduced" mentions the Settings page reorganization may surprise owners on first visit; suggest a one-line release note for pilot shops
- [ ] Verification confirms cross-stage integration: enabling platinum here causes Stage 2/3/4 behaviors to surface correctly

---

## 9. Rollout Sequencing

| Order | Stage | Why this order | Can ship independently? |
|---|---|---|---|
| 1 | Stage 1 (capability extension) | Pure additive, no UX impact, prerequisite for everything else | Yes |
| 2 | Stage 2 (item creation) | The most user-visible operational alignment — highest pilot value | Yes (consumes Stage 1) |
| 3 | Stage 3 (rates dashboard) | Cleans up the second-most-touched screen | Yes (consumes Stage 1) |
| 4 | Stage 4 (vault & reports) | Aligns reporting with primary/secondary mental model | Yes (consumes Stage 1) |
| 5 | Stage 5 (stone simplification) | Pilot UX simplification; advanced infrastructure stays quiet | Yes (no dependencies on 2/3/4) |
| 6 | Stage 6 (settings consolidation) | Final layer; ties everything together | Yes (consumes 2/3/4/5) |

Each stage ships independently. Do not bundle stages into a single PR. One stage = one PR = one journal entry.

**Minimum time between stages: 24 hours.** Allows verification to surface unexpected behavior before the next stage adds compounding changes.

---

## 10. Verification Protocol (Per-Stage and Cumulative)

Before declaring any stage shipped:

### Per-stage automated checks
- `php artisan test tests/Feature/Material/` — all tests for this stage pass
- `php artisan test tests/Feature/ConstitutionalInvariantsTest.php` — all constitutional invariants pass
- `php artisan returns:validate` — exits 0
- `php artisan materials:audit` — exits 0
- `php artisan vault:reconcile` — exits 0
- `php artisan karigar:reconcile` — exits 0
- `php artisan rates:reconcile-shadow-write` — exits 0

### Per-stage manual smoke
- Open the relevant screen on a clean pilot baseline shop
- Confirm the expected behavior matches the plan exactly
- Take a screenshot, link in the journal entry's "Verification performed" section if non-obvious

### Cumulative regression
- After every stage, repeat ALL prior stages' manual smokes
- If any prior behavior regressed, stop the stage and journal as "Unresolved concern"

### Cache hygiene reminder
After every deployment: `php artisan cache:clear && php artisan view:clear && php artisan route:clear` — run as `www-data` to avoid the cache-permission issue documented in conversation history.

---

## 11. Permanently Out of Scope

These items are listed explicitly so MinMax does not drift into them:

| # | Out of scope | Reason |
|---|---|---|
| O1 | New top-level navigation entries for materials, stones, or metals | Anti-ERP |
| O2 | A "Materials Management" admin section beyond the Settings tab | Anti-ERP |
| O3 | Per-metal performance dashboards | Premature optimization; no pilot demand |
| O4 | Customer-facing material price feeds | Permanently banned |
| O5 | Live diamond price integration | Permanently banned |
| O6 | Per-karigar metal capability flags | Wait for pilot friction report |
| O7 | Multi-karigar repair routing | Wait for pilot friction report |
| O8 | Memo/consignment inventory support | Post-pilot; high-end shops only |
| O9 | Refinery send-out workflow | Post-pilot |
| O10 | Cross-shop material marketplace | Permanently banned |
| O11 | AI-driven valuation suggestions | Permanently banned |
| O12 | Stone certificate OCR | Post-pilot |
| O13 | Materials onboarding wizard | Pilot shops don't need it; Settings tab is sufficient |
| O14 | Rate forecasting / historical rate charts | Premature; no pilot demand |
| O15 | Alloy decomposition | Permanently banned |
| O16 | Renaming `MetalRegistry` or tier constants | Constitutional |
| O17 | Adding new artisan commands beyond what this plan specifies | Operational surface is intentionally narrow |

If MinMax encounters a feature request from any source (user, code review, perceived need) that falls into this list, the response is **decline + journal entry under "Unresolved concerns"**, not implementation.

---

## 12. MinMax Quick Reference (Cheat Sheet)

**Before starting any stage:**
1. Read this plan section for the stage
2. Read the journal (if it exists)
3. Confirm the file paths match the current codebase (paths may have shifted)
4. Append a journal entry with status: in-progress

**During implementation:**
1. Touch only files listed in the stage's "Scope" section
2. If a file outside scope needs changes, stop and journal as "Unresolved concern"
3. Use `MetalRegistry::ux*` methods for all material-aware behavior — never hardcode metal names in view or controller logic

**Before shipping the stage:**
1. Run all verification commands from §10
2. Run the per-stage tests
3. Manual smoke test
4. Update journal entry status: shipped
5. Include `Refs: journal entry UX-[date]-[NN]` in the commit message

**If something breaks:**
1. Stop. Do not patch around it.
2. Journal as "Unresolved concern"
3. Wait for human review

**Forbidden words in code:**
- Do not write `metal_type === 'gold'` or `in_array($metal, ['gold','silver'])` in view or controller code — always go through `MetalRegistry::ux*` methods
- Do not write `if ($metal !== 'gold')` to hide non-gold materials — that's tier inversion; use `uxVaultPrimary($metal)` etc.

**Forbidden words in journal:**
- "refactor" without specifying behavior change
- "cleanup" without explanation
- "minor change" — there are no minor changes that don't get journaled

---

## 13. Closing Statement

The accounting and material foundation is **frozen**. This plan covers only the operator-facing UX layer that sits on top of that foundation. Every decision in this document follows from the operational behavior audit dated 2026-05-28.

When MinMax has shipped all six stages, JewelFlow will:
- Feel like a gold-and-silver business with optional platinum and stone support
- Default operators into the workflows they actually use daily
- Keep advanced infrastructure available but quiet
- Maintain every constitutional invariant unchanged
- Have a complete journal trail for Claude or future audits to inspect

If MinMax cannot achieve a stage within these constraints, the correct action is to stop, journal the blocker, and wait for human re-approval. **Do not invent. Do not extend the architecture. Do not modify the constitution.**

---

**End of plan.**
