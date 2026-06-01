# JewelFlow — Returns / Exchange System Diagnostic

> **Date:** 2026-06-01
> **Nature:** Diagnosis only. **Nothing rebuilt, no semantics changed, no accounting integration touched** (per constraints). This document establishes exactly what disappeared, why, and whether the data/logic still exists underneath.

---

## 0. Verdict (the answer to rt4)

> **The returns/exchange system is NOT gone. The backend logic, services, models, observer, views, mobile API, DB schema, and the `returns:validate` integrity suite all exist and are committed. What is missing is the WEB ROUTE LAYER and its navigation — the web returns/exchange UI is fully built but has zero registered routes, so it is unreachable from the product surface.**

This is a **disconnection from the web surface**, not a deletion of logic and not damage to accounting truth.

- ✅ **Accounting truth: intact.** `credit_notes`, `return_orders`, `return_line_items`, `exchange_orders`, `returned_item_dispositions` tables exist; the DB triggers and the 12-check `returns:validate` suite pass (12/12 green this session).
- ✅ **Backend logic: intact and committed.** Every service, model, controller, observer, and the mobile API survive at HEAD.
- ✅ **Mobile returns API: live and registered** (`/api/mobile/v1/returns` index/store/show/approve).
- ❌ **Web routes: absent.** No `returns.*` or `exchanges.*` route is registered anywhere.
- ❌ **Web navigation: absent.** No sidebar link, no "start a return" entry point on the invoice page.
- ⚠️ **Tables are empty** — but because of the earlier production data-wipe/fresh-start incident, **not** because of anything returns-specific.

---

## 1. Current State (component-by-component)

| Component | Exists? | Registered/Reachable? | Evidence |
|---|---|---|---|
| `ReturnsController` (14 methods) | ✅ committed at HEAD | ❌ no routes | `git cat-file -e HEAD:...ReturnsController.php` ok; `grep ->name('returns.` = none |
| `Returns\ExchangeController` (7 methods) | ✅ committed | ❌ no routes | same |
| `ReturnService`, `CreditNoteService`, `ExchangeService`, `RefundPolicyResolver`, `ReturnApprovalService`, `GoldValuationService` | ✅ | n/a (called by controllers/mobile) | `app/Services/Returns/*` present |
| Models: `ReturnOrder`, `ReturnLineItem`, `CreditNote`, `ExchangeOrder`, `ReturnedItemDisposition` | ✅ | n/a | `app/Models/*` present |
| `ReturnOrderObserver` | ✅ | (observer — fires on model events) | present |
| Views: `returns/{inbox,create,show,control-center,approve,recover}.blade.php`, `returns/exchanges/*`, `returns/partials/*` | ✅ | ❌ unreachable; `route('returns.*')` calls inside them would throw `RouteNotFoundException` if rendered | `resources/views/returns/` present |
| Web routes `returns.*` / `exchanges.*` | ❌ | ❌ | `php artisan route:list` shows none; `grep ->name('(returns|exchanges).` across `app/` + `routes/` = 0 |
| Sidebar nav links | ❌ | ❌ | only `report.metal-exchange` present in `layouts/app.blade.php` |
| Invoice "start a return" entry point | ❌ | ❌ | `invoices/show.blade.php` has no returns/exchange link |
| Mobile V1 returns API | ✅ committed | ✅ **registered** | `routes/mobile_v1.php` → `api/mobile/v1/returns` (index/store/show/approve), gated `can:returns.approve` |
| POS exchange (manufacturer) | ✅ | ✅ registered | `routes/web.php:310` `POST /pos/exchange` (`edition:manufacturer`) — a separate POS-side path |
| DB tables (5 + CN number events) | ✅ schema | ✅ | all present; row counts 0 (data-wipe, not returns-specific) |
| `returns:validate` (12 checks) | ✅ | ✅ runs, **passes 12/12** | run this session |
| Permissions `returns.*` | ⚠️ partial | — | only `returns.approve` + `exchanges.override_rate` seeded; **`returns.view`/`returns.create`/`returns.manage` absent** |

### Answers to the explicit questions (rt1)
- **Did it get deleted?** No. All code is committed at HEAD on `main`.
- **Is it hidden?** Yes — unreachable because routes + nav are absent.
- **Edition-gated?** No (no edition gate is even present to blame; the routes simply don't exist).
- **RBAC-blocked?** No — there is no route to block. (Separately, the gating permissions `returns.view/create` were never seeded.)
- **Disconnected from navigation?** Yes — no nav links and no invoice entry point.
- **Partially migrated?** Effectively yes: backend + views + mobile committed; web route layer not.
- **UI broken while backend exists?** Precisely — the web UI is intact in source but has no routes wiring it in.

---

## 2. Returns Architecture — what exists / broken / orphaned / disconnected

```
                          WEB SURFACE                         BACKEND (intact)                 DB (intact schema)
 Invoice show ──(MISSING link)──X
 Sidebar ──────(MISSING links)──X
 returns.* routes ─────(ABSENT)─X ─────▶ ReturnsController ─▶ ReturnService ───────────┐
 exchanges.* routes ───(ABSENT)─X ─────▶ ExchangeController ─▶ ExchangeService ─────────┤
                                                              CreditNoteService ────────┼─▶ return_orders
                                                              RefundPolicyResolver       │   return_line_items
                                                              ReturnApprovalService      │   credit_notes (+ number_events)
                                                              GoldValuationService       │   exchange_orders
                                         ReturnOrderObserver ─────────────────────────────┘   returned_item_dispositions
 MOBILE  /api/mobile/v1/returns ─(LIVE)▶ Api\Mobile\V1\ReturnController ─▶ ReturnService ─┘
```

| Flow | Status |
|---|---|
| Invoice return (full / partial) | **Disconnected (web)** — `ReturnsController::create/store` exist, no route. Mobile `store` works. |
| Exchange (2-step + unified) | **Disconnected (web)** — `ExchangeController::create/store/createUnified/storeUnified` exist, no route. |
| Credit note issuance | **Logic intact** — `CreditNoteService` callable; reachable only via mobile return store today. |
| Stock re-entry / disposition | **Disconnected (web)** — `redisposeItem`, `showRecover`, `storeRecover`, `inlineRecover`, `fixOrphanStatus`, `batchRestock` exist, no route. |
| Settlement adjustment | **Logic intact** — `RefundPolicyResolver` / override path present. |
| GST reversal | **Logic intact** — CN carries cgst/sgst/igst; computed in service at issue time. |
| Inventory correction | **Logic intact** — disposition → `MetalMovement` recovery path present (service-level). |
| Accounting/reporting integration | **Intact** — see §3. |
| Control Center (4 queues) | **Disconnected (web)** — `controlCenter()` + view exist, no route. |

**Orphaned (committed but unreachable from web):** both web controllers, all returns/exchange views, the Control Center, the recovery/disposition flows. **Not orphaned:** the services (used by mobile), the mobile API, the observer, `returns:validate`.

---

## 3. Reporting & GST Dependency Check (rt2 — is accounting truth damaged?)

**Accounting truth is NOT damaged.** The dependency is one-directional: reports **read** the returns/credit-note tables; they do not require the web UI to exist.

| Reporting surface | Depends on returns data | Status |
|---|---|---|
| GST report (net-of-CN) | reads `credit_notes` | ✅ functions; shows ₹0 reversals only because no CNs exist (empty DB) |
| CN/DN register (M1) | reads `credit_notes` | ✅ functions; empty set |
| GSTR-1 / GSTR-3B (M1) | CN section reads `credit_notes` | ✅ functions |
| Payment reconciliation (M2) | reads `invoices`/`invoice_payments` | ✅ independent of returns |
| Inventory valuation (M2) | reads `items` | ✅ independent |
| `returns:validate` | reads return tables | ✅ passes 12/12 |

**The distinction that matters:**
- **The feature vanished visually/operationally from the web** (no routes/nav).
- **Accounting truth itself is intact** (schema, triggers, semantics, services, validators all present and passing).

**Forward-looking operational risk (not stored-data damage):** because web staff cannot process returns, **no new credit notes can be created from the web**. Any web-originated return a cashier needs to do is unreachable → they will either fall back to the mobile app or process a cash refund off-system (the "skip the formal return" shadow-system risk flagged in the earlier human-behavior audit), which would leave GST reversals and inventory corrections unrecorded. So the risk is **operational completeness**, not corruption of existing records.

---

## 4. Frontend Exposure Audit

| Surface | State |
|---|---|
| Sidebar/menu | **No returns/exchange links.** Only `report.metal-exchange` (a report, not the returns workflow). |
| Route access | **No routes** → any link/bookmark to `/returns*` 404s; `route('returns.*')` in code throws. |
| Permissions | `returns.approve` + `exchanges.override_rate` seeded; **`returns.view`/`returns.create` missing** — so even after routes are added, gating must be seeded. |
| Edition checks | None present for returns (nothing to mis-gate). |
| Dead links | The returns **views** contain ~19 `route('returns.*'/'exchanges.*')` calls that would all throw until routes are registered. |
| Invoice entry point | **Absent** — `invoices/show.blade.php` does not link to "Return"/"Exchange". |

**Did recent RBAC/edition/reporting/mobile/POS work hide it?** No. Git proves the web returns routes were **never present in committed `routes/web.php` at any point** (see §6). The RBAC commit `74e9960` ("Enforce RBAC across all web and mobile routes") gated existing routes; it did not remove returns routes because there were none to remove.

---

## 5. Mobile Parity Check

| Item | State |
|---|---|
| Mobile returns endpoints | ✅ **Registered & live:** `GET/POST /api/mobile/v1/returns`, `GET /api/mobile/v1/returns/{id}`, `POST /api/mobile/v1/returns/{id}/approve` |
| Backend APIs work | ✅ `Api\Mobile\V1\ReturnController` → `ReturnService` (shared with web controllers) |
| Mobile references dead endpoints | ❌ No — endpoints exist |
| Parity drift | ⚠️ **Inverted parity:** mobile has returns; **web does not**. Mobile is currently the *only* reachable returns surface. Mobile has no exchange endpoints (web-only by design), but web exchange is also unreachable — so exchange is currently reachable from **neither** surface (except the separate manufacturer POS `/pos/exchange`). |

---

## 6. Git / Historical Trace (root cause)

**Definitive findings:**
- `git log -S 'ReturnsController' -- routes/web.php` → **empty**. The string `ReturnsController` has *never* existed in committed `routes/web.php`.
- `grep "->name('(returns|exchanges)\."` across all of `app/` and `routes/` → **0 route definitions**, at HEAD.
- `git log --diff-filter=D` for route files → **no deleted route file**.
- The returns sprint commit `4118935` ("Material consistency hardening") and `defb62c` ("WIP bulk") **added the controllers/services/views** (e.g. `ReturnsController.php` +898 lines) and are **ancestors of HEAD on `main`** — but `git show 4118935:routes/web.php | grep returns` = **0**. The route registrations were never in those commits either.
- `ReturnsController.php` **is committed at HEAD**.

**Root cause (most defensible reading of the evidence):**

> The web returns/exchange **route registrations were never committed** to `routes/web.php`. The controllers, services, models, observer, views, mobile API, and migrations were committed during the returns sprint; the web route layer + nav links + entry points + gating permissions were not. They lived only in the working tree and were lost during the earlier production reset / fresh-start (the same data-loss incident that emptied the DB). Git confirms there was **no deletion event** — the web routes simply never reached a commit.

This was **not** caused by the RBAC, edition, reporting, mobile-parity, or POS work. None of those removed returns routes (there were none to remove). It is an **uncommitted-work-loss / never-wired** gap, surfaced now that everything around it is stable.

---

## 7. Classification (per rt3)

### 🟣 Visual disappearance
- No sidebar nav links for Returns / Exchanges / Control Center.
- No "Return / Exchange" entry point on the invoice show page.

### 🔴 Operational breakage
- **Entire web returns/exchange workflow is unreachable** — return creation, partial returns, exchanges (2-step + unified), Control Center (4 queues), approvals, dispositions, melt recovery, batch restock: all have controller methods and views but **no routes**.
- Gating permissions `returns.view` / `returns.create` are not seeded.

### 🟠 Accounting-risk breakage
- **No damage to stored accounting truth** (schema, triggers, `returns:validate` 12/12, reporting all intact).
- **Forward risk only:** web staff cannot create credit notes → web-originated GST reversals / inventory corrections cannot be recorded → drives off-system cash refunds (shadow system). This is a *completeness* risk, reachable today only via mobile.

### ⚪ Orphaned infrastructure (committed, unreachable from web)
- `ReturnsController`, `Returns\ExchangeController`, all `resources/views/returns/**`, the Control Center, recovery/disposition flows.
- (Services, mobile API, observer, validators are **not** orphaned — they're exercised by mobile and the integrity suite.)

---

## 8. Exact Recovery Recommendation (NOT executed here)

The fix is **re-connection, not reconstruction** — register the route layer the committed controllers/views already expect, restore nav + entry point, and seed the gating permissions. No semantics, services, or accounting integration need to change.

### 8.1 Re-register web routes (map to existing controller methods)

| Route name | Verb / path | Controller method | Suggested gate |
|---|---|---|---|
| `returns.index` | GET `/returns` | `ReturnsController@index` | `can:returns.view` |
| `returns.create` | GET `/invoices/{invoice}/returns/create` | `create(Invoice)` | `can:returns.create` |
| `returns.store` | POST `/invoices/{invoice}/returns` | `store(Request,Invoice)` | `can:returns.create` |
| `returns.show` | GET `/returns/{returnOrder}` | `show(ReturnOrder)` | `can:returns.view` |
| `returns.control-center` | GET `/returns/control-center` | `controlCenter()` | `can:returns.approve` |
| `returns.approve-review` | GET `/returns/{returnOrder}/approve-review` | `showApprove(ReturnOrder)` | `can:returns.approve` |
| `returns.approve` | POST `/returns/{returnOrder}/approve` | `approve(...)` | `can:returns.approve` |
| `returns.reject` | POST `/returns/{returnOrder}/reject` | `reject(...)` | `can:returns.approve` |
| `returns.items.redispose` | POST `/returns/items/{item}/redispose` | `redisposeItem(...)` | `can:returns.approve` |
| `returns.items.fix-orphan-status` | POST `/returns/items/{item}/fix-orphan-status` | `fixOrphanStatus(...)` | `can:returns.approve` |
| `returns.items.recover` | GET `/returns/items/{disposition}/recover` | `showRecover(...)` | `can:returns.approve` |
| `returns.items.recover.store` | POST `/returns/items/{disposition}/recover` | `storeRecover(...)` | `can:returns.approve` |
| `returns.disposition.recover-inline` | POST `/returns/{returnOrder}/dispositions/{disposition}/recover-inline` | `inlineRecover(...)` | `can:returns.approve` |
| `returns.batch-restock` | POST `/returns/batch-restock` | `batchRestock(...)` | `can:returns.approve` |
| `exchanges.index` | GET `/exchanges` | `ExchangeController@index` | `can:returns.view` |
| `exchanges.create` | GET `/exchanges/create/{returnOrder}` | `create(ReturnOrder)` | `can:returns.create` |
| `exchanges.store` | POST `/exchanges/{returnOrder}` | `store(...)` | `can:returns.create` |
| `exchanges.unified.create` | GET `/invoices/{invoice}/exchange` | `createUnified(Invoice)` | `can:returns.create` |
| `exchanges.unified.store` | POST `/invoices/{invoice}/exchange` | `storeUnified(...)` | `can:returns.create` |
| `exchanges.show` | GET `/exchanges/{exchange}` | `show(ExchangeOrder)` | `can:returns.view` |
| `exchanges.receipt` | GET `/exchanges/{exchange}/receipt` | `receipt(ExchangeOrder)` | `can:returns.view` |

> Exact verbs/paths must be confirmed against each view's `route(...)` calls and form methods before wiring (the names above are taken verbatim from the views/controllers). `data-turbo-frame="_top"` rules and `assertShopLockForDate` calls already live in the controllers — re-registration does not touch them.

### 8.2 Seed missing permissions
Add `returns.view` and `returns.create` (and confirm `returns.approve` mapping) to the permission seed + default role grants. `returns.approve` and `exchanges.override_rate` already exist.

### 8.3 Restore navigation + entry point
- Sidebar: "Returns Inbox", "Operations / Control Center" (gated `returns.approve`), under Sales & Customers.
- Invoice show page: "Return" / "Exchange" buttons → `returns.create` / `exchanges.unified.create`.

### 8.4 Verify after re-connection
- `php artisan route:list | grep -E 'returns|exchanges'` shows the full set.
- Render each returns view (no `RouteNotFoundException`).
- `php artisan returns:validate` still 12/12.
- Process one web return end-to-end → confirm a `credit_note` is created and appears in the GST report / CN register / day-book.
- Confirm mobile returns still work (no regression).

---

## 9. Constraints Honoured
- ❌ No returns rewrite. ❌ No semantics redesign. ❌ No accounting integration removed.
- ✅ Credit-note semantics, GST-reversal integrity, reporting semantics, inventory-correction semantics, and backend accounting authority are all **untouched and verified present**.

## 10. Bottom Line
The returns/exchange system is **merely disconnected from the web product surface, not gone.** All logic, data schema, services, views, mobile API, and the integrity suite are intact and committed. Recovery is a bounded **re-wiring** task (routes + permissions + nav + entry point), not a reconstruction — and it carries no accounting-truth risk because the accounting layer was never the thing that broke.
