# MASTERS PART 4 — PURITY ACCESS AUDIT (PHASE 1 FINDING)

Date: 2026-08-07. Repo: /home/himanshu/Desktop/JewelFlow (Laravel web SaaS).
Branch: `feature/masters-purity-access` @ parent `f52336dd033370a2ea4eaa4cb798781d4c4d5f55`.
Status: **STOPPED AT PHASE 1 AUDIT GATE — business decision required before any implementation.**
No code changed. No push. No deploy. No DB touched. Read-only audit only.

## Phase 0 — state gate (PASS)
- Repo = correct Laravel web repo.
- Branch `feature/masters-purity-access` created directly from `f52336d`; HEAD = `f52336d`.
- Tracked tree/index clean (only pre-existing untracked artifacts: QA reports, output/, .claude/launch.json — preserved).
- production/staging branches are server-side (not present in this local clone) — cannot re-verify their HEADs locally; per baseline they are `f52336d`.
- Rejected historical chain `183d466 → 1b07aa9 → f52336d` are all ancestors of HEAD (expected — that is the deployed Part 3 chain, the required parent).

## HEADLINE FINDING
**There is NO "Purity master" in this application.** The task premise — "harden the
*existing* Purity master: route/middleware auth, Masters Hub navigation, list/create/edit/delete
views, action visibility" — describes a screen that does not exist.

Evidence (all read-only):
- No `PurityController` (any namespace). No purity master routes in `routes/web.php`.
- No purity master Blade views (`resources/views/**` grep for `purit` → none except the Pricing tab).
- **Masters Hub** (`MastersController` + `resources/views/masters/index.blade.php`) has exactly 6 cards:
  Customers, Vendors/Suppliers, Karigars, Categories & Subcategories, Product/Design Master, GST & Tax.
  **No Purity card exists** and there is no route it could link to.

## What Purity ACTUALLY is — MIXED ARCHITECTURE
Three distinct, already-gated surfaces:

### 1. `ShopMetalPurityProfile` — shop-owned pricing config (closest thing to a "master")
- Model `app/Models/ShopMetalPurityProfile.php`, table `shop_metal_purity_profiles`,
  uses `BelongsToShop` → **tenant-scoped** (global `shop_id` scope). Columns:
  `metal_type, code, label, purity_value, basis, is_active, sort_order`.
- **Administered inside Pricing Settings, NOT Masters Hub** — editor is
  `resources/views/partials/settings/pricing-tab.blade.php`.
- Write routes (all gated `can:pricing.update`):
  - `POST /settings/pricing/purity-profiles`  → `settings.pricing.profiles.store`
  - `PATCH /settings/pricing/purity-profiles/{profile}` → `settings.pricing.profiles.update`
  - `POST /settings/pricing/purity-profiles/{profile}/override` → `settings.pricing.overrides.store`
- Controller `PricingSettingsController@storeProfile/updateProfile/storeOverride`.
- **No delete/destroy anywhere** (route or code): profiles are deactivated via `is_active`, never hard-deleted.
- View gating already correct: every mutation control wrapped `@can('pricing.update')`;
  inputs `@cannot('pricing.update') disabled`. Hidden buttons are NOT the boundary — route middleware is.
- Consumed read-only as a selector by: retailer item create/edit, product create/edit, quick-bill form,
  vault create-lot, onboarding.

### 2. Server-driven purity presets (read-only reference)
- `app/Http/Controllers/Api/Mobile/V1/RegistryController.php` + `app/Data/Mobile/V1/MaterialRegistrySnapshot.php`;
  mobile `GET /repairs/options` (metal-aware purity scale). Gated by the CONSUMER's permission
  (`inventory.view`, `repairs.view`, etc.). No mutation surface.

### 3. Purity as transaction snapshot data (immutable per document)
- `purity` / `purity_value` columns on Item, Repair, JobOrder*, CustomerGoldTransaction,
  StockPurchaseItem, KarigarInvoiceLine, InvoicePayment, MetalLot, etc. Historical values are
  saved-on-document and continue resolving from the row itself — independent of any master.

## Consumer matrix (read vs master mutation)
| Surface | Route/action | R / Mutation | Existing gate | Tenant | UI visibility | Hist vs new | Change? |
|---|---|---|---|---|---|---|---|
| Purity profile create | `settings.pricing.profiles.store` | Mutation | `can:pricing.update` | BelongsToShop | `@can(pricing.update)` in pricing tab | new | none — already gated |
| Purity profile edit | `settings.pricing.profiles.update` | Mutation | `can:pricing.update` | BelongsToShop + `{profile}` bind | `@can` / `@cannot disabled` | new | none — already gated |
| Purity override | `settings.pricing.overrides.store` | Mutation | `can:pricing.update` | BelongsToShop | `@can(pricing.update)` | new | none — already gated |
| Purity profile deactivate | via update `is_active` | Mutation | `can:pricing.update` | BelongsToShop | `@can` | new | none (no hard delete exists) |
| Mobile purity presets | `GET /repairs/options`, registry snapshot | Read | consumer perm (`repairs.view`, `inventory.view`) | tenant | selector only | both | none |
| Item/Product/QuickBill/Vault purity select | forms | Read | consumer create/edit perm | tenant | selector | new | none |
| Transaction purity snapshot | Item/Repair/JobOrder/CustomerGold rows | Read | consumer view perm | tenant | rendered value | historical | none |
| `quick-add-purity` | `inventory.items.quick-add-purity` | Mutation | `can:inventory.create` | tenant | inline in item create | new | none — already gated |

## Architecture determination
Purity = **MIXED**: (1) shop-owned tenant config (`ShopMetalPurityProfile`) administered under
Pricing Settings and gated `can:pricing.update`; (2) read-only server-driven presets gated by the
consuming resource; (3) immutable per-transaction snapshot columns. **It is not a Masters-Hub master
and has no dedicated admin CRUD.**

## Why I stopped (per task's own gates)
- Phase 1: "If architecture or ownership is ambiguous, **stop and report before implementation**."
- Phase 3: scope is to harden the **existing** master — none exists to harden.
- OUT OF SCOPE forbids adding/changing purity values, formulas, and (without separate approval) schema.
- Building a net-new Purity master (controller + routes + list/create/edit/delete views + Masters Hub
  card) is scope creation, not hardening — a genuine business-policy decision, not an autonomous call.

## Existing access posture is already sound (nothing insecure found)
- All three purity-profile mutations are server-gated `can:pricing.update` at the route.
- Tenant isolation via `BelongsToShop` global scope; `{profile}` route binding resolves within the
  shop scope, so cross-shop IDs 404 rather than leak.
- View controls hide/disable without `pricing.update`; the route is the real boundary.
- No hard-delete path exists → no destructive-delete dependency risk.

## DECISION NEEDED (pick one)
- **A. Accept current architecture (recommended, zero-change):** Purity administration already lives
  under Pricing Settings, correctly gated `can:pricing.update`, tenant-scoped, read-only-safe. Close
  Part 4 as "no hardening required — audit confirms posture." Optionally add regression tests only
  (no behavior change) to lock the gates.
- **B. Add a Masters Hub "Purity" card** linking to the existing pricing purity-profiles editor
  (additive nav only, mirrors MastersController pattern, reuses `pricing.update` + retailer edition).
  Small, in-scope-ish, no new CRUD.
- **C. Build a net-new dedicated Purity master screen** (new controller/routes/views/card). Largest
  scope, overlaps Pricing Settings, needs product sign-off. Not recommended.

## DECISION RECORDED — Option B chosen
Business decision: **Option B**. Purity is administered through the existing Pricing Settings
profile editor. Masters provides a discoverability shortcut while Pricing Settings remains the
single source of truth.

Implementation is navigation + permission lock-in only — no new controller, CRUD routes, model,
table, migration, or permission name. The Masters Hub "Purity" card:
- deep-links to `route('settings.edit', ['tab' => 'pricing']) . '#purity-profiles'` (the existing
  Purity Profiles section anchor in the pricing tab);
- reuses the existing `pricing.update` permission and the `retailer` edition gate, exactly mirroring
  the pricing entry point (`@if($shop->isRetailer()) @can('pricing.update')`);
- renders only when the user can legitimately reach the editor; unauthorized direct route access is
  still server-rejected by the pre-existing route middleware and controller `abort_*` guards;
- read-only shops still see the navigation card but cannot mutate (blocked by
  `EnsureSubscriptionIsActive` on non-GET) — proven by test.

Lock-in regression coverage added in `tests/Feature/Masters/PurityMastersAccessTest.php` and the
updated `tests/Feature/Masters/MastersHubTest.php` golden lists.

Awaiting the A/B/C decision before Phase 2+. No implementation performed.
