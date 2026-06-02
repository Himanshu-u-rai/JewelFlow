# JewelFlow — Product Surface Integrity Audit

> **Date:** 2026-06-02
> **Nature:** Deep diagnosis only. **Nothing rebuilt, redesigned, or fixed in this pass** (per constraints). This is the authoritative integrity map of the JewelFlow product surface.
> **Method:** Evidence-backed cross-referencing, not a grep glance — every finding below is backed by a reproducible command. Key techniques:
> - All 460 registered route names (`route:list --json`) diffed against all 376 `route('…')` references in `resources/views` + `app/Http` → **broken links / dead actions**.
> - All 130 controllers diffed against route-list controller usage → **orphaned controllers**.
> - All `can:`/`@can` permission references diffed against the seeded `permissions` table → **impossible gates**.
> - Git `-S` history on every orphaned surface → **never-committed vs deleted**.
> - Live `storage/logs/laravel.log` as ground truth for what actually 500s.

---

## 0. Executive Summary

JewelFlow's **backend and accounting core are intact and healthy.** The integrity problems are concentrated in the **product surface wiring** — routes, navigation, and a few configuration UIs — and they cluster into a single, consistent root cause:

> **A band of feature surfaces had their controllers/services/views committed during big sprints, but their route registrations, navigation links, settings UIs, and (in some cases) controller methods were never committed — they lived only in the working tree and were lost in the earlier production reset / fresh-start.** The returns disconnection you already found was not unique; it is one member of a **lost-work cluster**.

Numbers: **524 routes / 460 named / 130 controllers / 376 distinct `route()` references.**

**The good news (verified healthy):**
- **Permissions/RBAC**: only `cleanup` is referenced-but-unseeded (a maintenance gate); every real operational permission is seeded. No impossible RBAC gates.
- **Mobile route files**: zero references to missing controllers (the old `RegistryController` log error is stale — the class exists now).
- **Broken `route()` references**: only **8** distinct, and they all belong to the same lost-work cluster (returns-adjacent). The other 368 references resolve.
- **Accounting integrity**: `returns:validate` 12/12, `reports:validate` green — untouched.

**The bad news (the cluster):**
1. **Returns policy gate is a dead end** — returns are reconnected but still **unusable** because the policy-config UI doesn't exist (§3.A, highest priority).
2. **Repair/Rework job-work flow** — views exist, **controller methods + routes never built** (§3.B).
3. **Store Credit adjustment** — controller + view built, **zero routes** (orphaned, §3.C).
4. Several smaller orphaned controllers/views (§3.D).

---

## 1. The Killer Evidence (three cross-reference diffs)

### 1.1 Broken `route()` references (dead actions / "Something Went Wrong" sources)
8 distinct route names are referenced in views/controllers but **not registered**:

| Referenced route | Used in | Reality |
|---|---|---|
| `job-orders.repair.create` | `Returns\ReturnsController` | no route, **no controller method** |
| `job-orders.repair.store` | `job-orders/create-repair.blade.php` | no route, no method |
| `job-orders.repair.receipt` | `job-orders/receive-repair.blade.php` | no route, no method |
| `job-orders.rework.create` | `returns/show`, `returns/control-center` | no route, no method |
| `job-orders.rework.store` | `job-orders/create-rework.blade.php` | no route, no method |
| `store-credit.adjust.store` | `store-credit/adjust.blade.php` | no route (controller method **exists**) |
| `customers.invoices` | `customers/invoices.blade.php` (self-ref) | no route, no controller method |
| `slug` / `token` | catalog middleware / reset-password | **false positives** (route params, not names) |

Every one of these throws `RouteNotFoundException` ("Something Went Wrong") the moment the containing view renders or the link is clicked.

### 1.2 Orphaned controllers (zero registered routes)
6 controllers have no route (excluding the base `Controller.php`):

| Controller | Methods | Assessment |
|---|---|---|
| `StoreCreditController` | adjustCreate, adjustStore, applyToInvoice | **Orphaned — fully built, unrouted** (like returns was) |
| `GstCategoryController` | store, update, destroy | Built, unrouted (admin GST categories — plan-deferred Phase F) |
| `ItemStoneController` | index, store, update, destroy, revalue, revaluations | Built, unrouted (item stone-component management) |
| `MobileDeviceSessionController` | destroy, destroyAllForUser | Built, unrouted (revoke mobile sessions from web) |
| `AuditController` | index | **Dead** — superseded by the Settings → Audit tab |
| `Controller` | (base) | N/A |

### 1.3 Permissions — clean
Referenced-but-unseeded: **only `cleanup`**. All operational permissions (`sales.*`, `returns.*`, `inventory.*`, `karigar*`, `reports.*`, `settings.*`, `staff.*`, etc.) are seeded. RBAC is healthy post the returns.view/create seed.

---

## 2. The Lost-Work Cluster (root cause, git-verified)

`git log -S` on `routes/web.php` shows these route names were **never committed** at any point: `store-credit.adjust`, `job-orders.repair`, `job-orders.rework`, `customers.invoices` (and previously `returns.*`/`exchanges.*`). None were deleted — they simply never reached a commit. The controllers/views WERE committed. This is the same signature as the returns disconnection: **working-tree-only route/nav/settings wiring lost in the reset.**

Members of the cluster, by completeness:
- **Returns/Exchanges** — controllers+services+views committed; routes/nav/perms lost → **reconnected this session** (but see §3.A).
- **Store Credit adjust** — controller+view committed; routes/nav lost.
- **Repair/Rework job-work** — only views committed; **controller methods + routes never existed**.
- **Return-policy settings UI** — only the banner + breakdown partials committed; **the settings tab/form never existed**.
- **Customer invoices sub-page** — only the view committed; route + method never existed.

---

## 3. Module-by-Module Surface Map

Legend: ✅ reachable & whole · 🟠 reachable but degraded/partial · 🔴 unreachable/blocked · ⚪ orphaned (built, unrouted) · 🧩 unfinished (views without backend)

### A. 🔴 Returns / Exchanges — RECONNECTED but BLOCKED at the policy gate *(highest priority)*
Routes/nav/permissions restored this session (commits `a35a47d`, `9e0d15b`), and three missing model symbols fixed. **But the flow is still operationally dead** because:
- `ReturnsController::create()` redirects any shop **that has a `shop_preferences` row** to `settings.edit?tab=return-policy` until `hasConfiguredReturnPolicy()` is true.
- **There is no `return-policy` settings tab** (tabs are: general, shop, preferences, pricing, materials, billing, website, payment-methods, roles, staff, audit). The banner and the redirect both point at a tab that doesn't render.
- **Nothing anywhere sets `return_policy_configured_at`** and `updatePreferences()` does **not** validate/save any `refund_*`/`return_window`/`restocking_fee` fields. So `hasConfiguredReturnPolicy()` is permanently `false`.
- Net effect: shops **with** preferences → infinite redirect to a non-existent tab (cannot return). Shops **without** preferences → `create()` proceeds and would refund 100% silently. Either way, broken or dangerous.
- **Exchanges** ride the same gate (`ExchangeController::createUnified` also calls `hasConfiguredReturnPolicy()`).

**Classification: operational breakage + dangerous silent failure.** The returns reconnection is necessary but insufficient until the return-policy settings surface is restored.

### B. 🧩 Repair / Rework Job-Work — unfinished migration *(breaks returns→rework)*
- Views exist: `job-orders/create-repair.blade.php`, `receive-repair.blade.php`, `create-rework.blade.php`.
- **No controller methods** (`JobOrderController` has only create/store/receiveForm/storeReceipt; no repair/rework). **No routes.**
- The returns Control Center + `returns/show` "Start Karigar Job" rework action links to `job-orders.rework.create` → **dead link** → RouteNotFoundException.
- The `JobOrder::JOB_TYPE_REPAIR/REWORK` constants now exist (added this session) and the DB CHECK allows `repair`/`rework`, but the operational flow to create them was never built.
- **Classification: unfinished migration + operational breakage (dead action from returns).**

### C. ⚪ Store Credit — orphaned (built, unrouted)
- `StoreCreditController` (adjustCreate, adjustStore, **applyToInvoice**) + `store-credit/adjust.blade.php` exist; **zero routes**, not in nav.
- `applyToInvoice` suggests POS store-credit redemption was intended — currently unreachable.
- Store-credit *accounting* (the `store_credit_movements` ledger + its DB guard) is intact; only the **adjustment/redemption UI** is disconnected.
- **Classification: orphaned infrastructure (accounting-adjacent — manual credit corrections impossible via UI).**

### C2. ✅/🟠 Customers, Loyalty, Installments, Schemes
- Customers CRUD ✅ reachable. **`customers/invoices.blade.php` is orphaned** (no `customers.invoices` route/method) — a customer-invoices sub-page that can't be opened (🧩).
- Loyalty (`loyalty.*`) and Installments/EMI (`installments.*`) routes exist but are **not in the main sidebar** — reachable only via contextual links (customer/invoice pages). Verify those contextual entry points exist (🟠 exposure risk, not breakage).
- Schemes ✅ reachable (sidebar, retailer edition).

### D. ⚪ Other orphaned controllers
- **GstCategoryController** (store/update/destroy) — admin-controlled GST categories; plan-deferred (Phase F). Built, unrouted. *Low priority (intentionally deferred).*
- **ItemStoneController** (index/store/update/destroy/revalue/revaluations) — item stone-component management with revaluation; built, unrouted → the stone-management UI (if any links to it) is dead. *Needs decision: wire or retire.*
- **MobileDeviceSessionController** (destroy/destroyAllForUser) — revoke a user's mobile sessions from the web; unrouted → **session revocation from web is unavailable** (mobile session mgmt may exist elsewhere; verify). *Security-adjacent.*
- **AuditController::index** — dead; the audit log is served by the Settings → Audit tab. *Safe to retire.*

### E. ✅ Verified-healthy modules (reachable, routed, in nav)
POS, Quick Bill, Invoices, Inventory (items + gold + purchases), Repairs (standalone module), Karigar + Karigar Invoices, Job Orders (manufacture path), Vendors, Vault/Metal + Vault Ledger, Cashbook, Dashboard, Reports (gold/daily/cash/pnl/gst/closing/repairs/metal-exchange + the new GSTR-1/3B/CN-register/payment-recon/day-book/inventory-valuation), Exports, Settings (existing tabs), Staff/Roles (settings tabs), Subscription/Billing, Dhiran (loan module, subdomain-redirected), Admin platform console (`admin.*` — separate route file, all registered), Mobile v1 API. No broken refs, controllers routed.

### F. Mobile parity
- Mobile v1 (`/api/mobile/v1/*`) and legacy mobile (`/api/mobile/*`) route files reference **no missing controllers**.
- **Inverted returns parity persists**: mobile has returns endpoints; web returns are reconnected but gated (§3.A). Mobile returns bypass the web policy-tab gate (they use `ReturnService` directly), so mobile may currently be the only working returns path — worth confirming mobile doesn't hit the same `hasConfiguredReturnPolicy` gate.

---

## 4. Navigation & Exposure Audit

- **Sidebar** is the primary nav; it now includes Returns & Exchanges + Operations (added this session) and the CA report pack.
- **Not in the main sidebar (reachable only contextually or by URL):** `loyalty.index`, `installments.index`, `store-credit*` (orphaned anyway), `staff.index` (lives in Settings). Loyalty + Installments are legitimate operator surfaces — confirm their contextual entry points (customer page, invoice page) actually render, or they're effectively hidden.
- **Dead actions in nav/views:** the returns Control Center rework button (`job-orders.rework.create`), store-credit adjust submit, customer-invoices link — all throw on click.
- **Settings tabs:** no `return-policy` tab (the returns gate's redirect target) — the single most damaging exposure gap.

---

## 5. UX Regression Audit (evidence-limited — flagged, not assumed)

This pass is static; true visual regression needs the running app. What's statically evident:
- **Returns/exchange operator flow** regressed from "fully built" to "unreachable/gated" (now partially restored).
- **Repair/Rework** intended flow (per the Material Flow plan) regressed to views-without-backend.
- **No evidence** of POS / Quick Bill / customer-creation / inventory-registration view regressions in the route/symbol layer — those modules are routed and their `route()` refs resolve. A focused visual pass on POS payment + Quick Bill is recommended but no integrity defect is detectable statically.
- Recommend a follow-up *rendered* audit (authenticated click-through) for the 🟠 contextual surfaces and the POS/Quick-Bill payment screens, since static analysis cannot see layout/ergonomics regressions.

---

## 6. Permission / Edition Audit
- **Permissions:** healthy. Only `cleanup` referenced-but-unseeded (trace it — likely a console/maintenance `can:cleanup`). No operational gate is impossible to pass.
- **Edition gates:** `edition:retailer` / `edition:manufacturer` applied to schemes, catalog, metal-exchange report, POS exchange, etc. No evidence of a module accidentally stranded behind an edition gate (the orphans have *no* routes at all, so edition isn't the cause).
- **RBAC after the returns seed:** owner/manager/staff all receive `returns.view`+`returns.create`; `returns.approve` owner/manager-only. Correct.

---

## 7. Git / Historical Trace (per finding)
| Surface | History verdict |
|---|---|
| Returns/Exchanges routes | **never committed** in web.php; controllers/views committed (4118935/defb62c). Reconnected this session. |
| Store-credit routes | **never committed**; controller+view committed. |
| job-orders.repair/rework routes | **never committed**; only views committed; controller methods never existed. |
| customers.invoices | **never committed**; only view committed. |
| return-policy settings tab | **never committed/never existed**; banner+partials committed. |
| ShopPreferences return methods | never committed (fixed this session). |
| JobOrder JOB_TYPE_*/sourceItem, Item latestReturnDisposition | never committed (fixed this session). |

Consistent signature across all: **partial commits during sprints, with the wiring/config layer lost in the reset.**

---

## 8. Operational Risk Classification

| Finding | Classification | Priority |
|---|---|---|
| Return-policy gate → non-existent settings tab; `configured_at` never set | **Operational breakage + dangerous silent failure** | **P0** |
| Repair/Rework job-work: views without backend; dead rework action from returns | Unfinished migration + operational breakage | **P1** |
| Store Credit adjust/redeem: orphaned controller+view | Orphaned infrastructure (accounting-adjacent) | **P1** |
| `customers.invoices` orphaned view | Dead surface | P2 |
| MobileDeviceSession revoke unrouted | Orphaned (security-adjacent) | P2 |
| ItemStone management unrouted | Orphaned (feature decision needed) | P2 |
| Loyalty/Installments not in sidebar | UX/exposure regression | P2 |
| GstCategory unrouted | Orphaned (intentionally deferred) | P3 |
| AuditController dead | Low-priority cleanup | P3 |
| `cleanup` permission unseeded | Low-priority cleanup | P3 |
| `slug`/`token` route refs | False positives | — |

**Accounting-risk:** none to stored data anywhere in this audit. All findings are surface/wiring. The accounting substrate (ledgers, triggers, validators) is untouched and passing.

---

## 9. The Real Product-Surface Map

```
REACHABLE & WHOLE (✅)
  POS · Quick Bill · Invoices · Inventory(items/gold/purchases) · Repairs(module)
  Job Orders(manufacture) · Karigar · Karigar Invoices · Vendors · Vault + Ledger
  Cashbook · Dashboard · Reports(all incl. new CA pack) · Exports · Schemes
  Customers(CRUD) · Subscription/Billing · Settings(existing tabs) · Staff/Roles
  Dhiran(loan) · Admin console · Mobile v1 API

REACHABLE BUT GATED/DEGRADED (🟠/🔴)
  Returns/Exchanges → reconnected but BLOCKED at non-existent return-policy gate (P0)
  Loyalty / Installments → routed, not in main sidebar (contextual only)

UNFINISHED — VIEWS WITHOUT BACKEND (🧩)
  Repair job-work (create-repair/receive-repair) — no methods/routes
  Rework job-work (create-rework) — no methods/routes; dead link from Returns
  customers/invoices sub-page — no method/route

ORPHANED — BUILT BUT UNROUTED (⚪)
  StoreCreditController (adjust/redeem) + view
  ItemStoneController (stone components + revaluation)
  GstCategoryController (deferred Phase F)
  MobileDeviceSessionController (web session revoke)
  AuditController (dead; superseded)

MISSING CONFIG SURFACE
  Return-policy settings tab/form (blocks all of Returns/Exchanges)

ACCOUNTING SUBSTRATE (untouched, healthy)
  ImmutableLedger · DB triggers · returns:validate 12/12 · reports:validate green
```

---

## 10. Recovery Priorities (diagnosis only — NOT executed)

**P0 — Unblock returns (makes the reconnection actually usable):**
1. Build the **Return Policy settings tab/form** (the redirect target) + extend `SettingsController::updatePreferences` to validate/save `refund_*`, `wear_loss_pct`, `restocking_fee_pct`, `return_window_days`, `return_settlement_mode`, and **stamp `return_policy_configured_at` on save**. Until this exists, returns/exchanges cannot be processed on the web.
   - *(Interim option to assess: if the policy gate was meant to be optional, the alternative is to relax `create()`'s redirect — but per the Phase-A plan the gate is intentional, so the config UI is the correct fix.)*

**P1 — Reconnect / finish the next cluster members:**
2. **Store Credit** — register `store-credit.adjust.*` (+ `applyToInvoice` for POS redemption) routes + nav + permission, mirroring the returns reconnection.
3. **Repair/Rework job-work** — decide: build the missing `JobOrderController` repair/rework methods + routes (completing the Material-Flow plan), or remove the dead views + the rework action from Returns. Currently a dead link from the Returns Control Center.

**P2 — Smaller orphans / exposure:**
4. Decide wire-or-retire for `customers.invoices`, `ItemStoneController`, `MobileDeviceSessionController`.
5. Add Loyalty + Installments to nav (or confirm contextual entry points).

**P3 — Cleanup:** retire `AuditController`; trace/seed-or-remove `cleanup` permission; GstCategory remains deferred.

**Then:** a rendered (authenticated) click-through pass for true UX-regression coverage that static analysis cannot provide.

---

## 11. Bottom Line
The SaaS surface is **mostly whole**, but a **lost-work cluster** — returns policy config, repair/rework job-work, store-credit UI, and a few orphaned views/controllers — sits disconnected on top of a healthy backend. The pattern is uniform (working-tree wiring lost in the reset), so the recoveries are uniform too: **re-wire routes/nav/settings against already-built controllers/views; build the few genuinely-missing pieces (return-policy form, repair/rework methods).** None of it threatens the accounting substrate. The single most important fix is the **return-policy settings surface** — without it, the entire returns/exchange reconnection remains operationally inert.
