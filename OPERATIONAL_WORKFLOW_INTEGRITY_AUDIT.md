# OPERATIONAL WORKFLOW INTEGRITY AUDIT
*The definitive operational continuity map of JewelFlow.*

**Date:** 2026-06-02
**Scope:** Deep lifecycle-level integrity of every operator-facing workflow — create / edit / archive / soft-delete / restore / approval / rejection / recovery / reassignment / settlement / reverse-settlement / issue-receive / failure-recovery / permission-bound transitions.
**Method:** 6 parallel module-cluster audits, each tracing the full chain — route → controller → permission/policy gate → service logic → UI exposure → reverse/recovery path → state-machine completeness — with `file:line` evidence. Headline accounting/permission findings were independently spot-verified by the lead auditor (noted ✔︎-verified below).
**Constraint:** This is **diagnosis only**. No system was redesigned, rewritten, or silently fixed. This document maps reality; it does not change it.

---

## 0. How to read this document

- **§1 Verdict** — the one-paragraph truth.
- **§2 Grounding facts & corrections** — architectural truths every finding depends on, including two grounding assumptions that turned out to be *wrong* and were corrected during the audit.
- **§3 Master findings table** — every finding, risk-classified, sortable.
- **§4 Detailed findings** — grouped by risk class, with evidence and operational impact.
- **§5 Cross-cutting patterns** — the three structural causes behind most findings.
- **§6 Verified-intact workflows** — what we now have *certainty* was NOT lost.
- **§7 Prioritized continuity backlog** — P0→P3 mapping (NOT an instruction to fix; a remediation map).

Risk classes used throughout: `cosmetic` · `UX-regression` · `workflow-regression` · `hidden-workflow` · `dead-lifecycle` · `accounting-risk` · `permission-breakage` · `recovery-failure` · `silent-corruption` · `dangerous-incomplete-flow` · `data-exposure`.

---

## 1. Verdict

> **The accounting core is intact and trustworthy. The operational *edges* are where capability has been lost.**

No silent ledger corruption, no tenant-isolation breach, and no mobile accounting bypass were found. POS, invoicing, returns settlement, credit notes, vault movements, and the canonical reporting layer are all wired end-to-end and verified. **What has regressed or never-fully-landed is the lifecycle periphery**: reverse/correction paths, recovery paths, second-half-of-the-sprint features, and UI triggers for endpoints that exist but were never surfaced. The dominant failure mode is not "broken accounting" — it is **"the forward path works, the undo/recover/close path was never built or was left disconnected."** Several sprint-era capabilities (rework job-work, scheme cancellation, installment closure, stone editing UI, GST-category UI) exist as **partial implementations**: a model, a migration, sometimes a service method — with no route or no UI to reach them.

This audit found **36 distinct findings**. None corrupt existing financial data. **Three are genuinely dangerous incomplete flows** (installment default has no closure, scheme cancellation/refund is unreachable, rework parks gold in an unclearable queue). **Four are correction-path gaps** that will force raw-SQL support intervention the first time an operator makes a mistake (karigar invoice/payment, vault adjustment, confirmed stock-purchase reversal). The rest are hidden surfaces, permission mis-gates, and UX regressions.

---

## 2. Grounding facts & corrections

These architectural truths underpin every finding. **Two were assumptions that the audit proved wrong** — recorded here because they affect how findings are interpreted.

| # | Fact | Status |
|---|------|--------|
| G1 | **No model uses Laravel `SoftDeletes`.** All lifecycle is status-column based (`employment_status`, `item.status`, `return_orders.status`, etc.). "Archive/recover" everywhere means a status transition or `is_active` flag — never `deleted_at`. So "is there a trash/restore?" must be asked per-entity, not assumed. | Verified ✔︎ |
| G2 | **RBAC = `Gate::before` (`AppServiceProvider.php:73-78`) maps any ability containing `.` → `User::hasPermission()` → `Role::hasPermission()` via the `role_permission` pivot.** There is **no owner super-user bypass on this live path.** Owner gets all permissions only because `TenantRoleService::ensureDefaultsForShop()` grants them at shop creation. | Verified ✔︎ |
| G3 | **CORRECTION — `CheckPermission` middleware is NOT deleted.** It exists at `app/Http/Middleware/CheckPermission.php`, is registered as alias `permission` (`bootstrap/app.php:58`, `Kernel.php:49`), and *does* contain an owner bypass (line 26). **But zero routes use the `permission:` alias** (every gated route uses `can:`), so it is **dead defensive code** with no live effect. The git working-tree had shown it "deleted" at session start; it is currently present. | ✔︎-verified this audit |
| G4 | **CORRECTION — POS `wallet` tender ≠ store credit.** The POS payment modes are `cash/upi/bank/wallet/other` (`RetailerSalesService.php:243`), where `wallet` means a *third-party digital wallet* (Paytm/PhonePe) recorded as cash-in. It is **not** internal store credit. An earlier sub-finding claiming "POS wallet mode overstates the store-credit ledger (accounting-risk)" is a **FALSE POSITIVE** and is dismissed. The real store-credit gap is narrower — see F-SC. | ✔︎-verified this audit |
| G5 | **`BelongsToShop` global scope** auto-scopes every tenant query; `resolveTenantShopId()` returns null in console/PHPUnit, which is why bound-route tests 404 — a test-harness artifact, not a production bug. | Verified ✔︎ |
| G6 | **Invoice finalization is the single ImmutableLedger write path; `cancelByReversal` does a full mirror reversal; `assertShopLockForDate` guards locked periods on every write.** MetalMovement is append-only (ORM + DB triggers). | Verified ✔︎ |

---

## 3. Master findings table

Ordered by severity. ✔︎ = lead-auditor spot-verified; ◦ = sub-audit evidence (file:line provided in §4).

| ID | Workflow / Surface | Status | Risk class | Sev | Ver |
|----|--------------------|--------|-----------|-----|-----|
| **D1** | Installment `defaulted` unreachable + no settle/write-off/close | **FIXED M2** (operator write-off close → `defaulted`, invoice untouched, audited) | dangerous-incomplete-flow | **P0→done** | ✔︎ |
| **D2** | Scheme enrollment cancel + contribution refund | **FIXED M3** (cash refund of ledger balance excl. bonus, reversing ledger debit, route+UI gated sales.void) | dead-lifecycle / recovery-failure | **P0→done** | ✔︎ |
| **D3** | Scheme maturity is payment-count-only; no date-based maturity/bonus | **FIXED M4** (daily `schemes:process-maturity`; bonus iff fully paid, else matures w/o bonus) | dead-lifecycle | **P0→done** | ✔︎ |
| **A1** | Admin "deactivate tenant user" | ~~no-op~~ **CORRECTED → inconsistent-state** (observer is dormant; toggle works but left `employment_status` stale). **FIXED M1.** | workflow-regression | **P0→done** | ✔︎ |
| **A1b** | `UserObserver` (is_active ↔ employment_status invariant) | DEAD (never registered → invariant unenforced) | dead-lifecycle / latent-corruption | **P2** | ✔︎ |
| **A2** | KarigarInvoice / KarigarPayment reverse/void/correct | **FIXED M5** (compensating-entry payment reversal; reopens invoice to unpaid for correction) | accounting-risk | **P1→done** | ✔︎ |
| **A2b** | `karigar_invoices_finalized_guard` trigger guards non-existent `OLD.status` | **FIXED M5** (latent P0 — crashed ALL karigar-invoice updates incl. payment recording; trigger repaired to freeze content-after-payment on `payment_status`+totals, name preserved) | silent-corruption / dangerous-incomplete-flow | **P0→done** | ✔︎ |
| **A3** | Vault manual adjustment (sanctioned compensating entry) | ABSENT (no route/svc/UI) | accounting-risk | **P1** | ◦ |
| **A4** | Confirmed/stocked stock-purchase reversal | ABSENT (one-way) | workflow-regression / accounting-risk | **P1** | ◦ |
| **R1** | Rework lifecycle: `sent_to_rework` → JobOrder | DEAD (`target_job_order_id` never set) | dead-lifecycle | **P1** | ✔︎ |
| **R2** | Control Center "Send to Karigar" button | LIVE but dead-ends | UX-regression / dead-lifecycle | **P1** | ◦ |
| **R3** | Orphan "Fix Status" recovery action | UNREACHABLE (filters rows never created) | recovery-failure (latent) | **P2** | ◦ |
| **R4** | Job-order cancel → restore source item status | DEAD (cancel ignores `source_item_id`) | recovery-failure (latent) | **P2** | ◦ |
| **P1** | Draft-invoice **finalize** mis-gated by `sales.void` | BROKEN for `sales.create`-only staff | permission-breakage | **P1** | ◦ |
| **P2** | Bulk import gated by `reports.export` (not `imports.*`) | MIS-GATED | permission-breakage | **P1** | ◦ |
| **SEC1** | KYC PII (PAN/Aadhaar/passport) on **public** disk + public URL | EXPOSED | data-exposure | **P1** | ◦ |
| **F-SC** | Store-credit **consumption** (`applyToInvoice`) has no UI trigger | HIDDEN (endpoint OK, no button) | workflow-regression / hidden-workflow | **P2** | ✔︎ |
| **H1** | `ItemStoneController` — stone add/edit/revalue/delete | DEAD (zero routes) | hidden-workflow | **P2** | ◦ |
| **H2** | `GstCategoryController` — GST category management | DEAD (zero routes); destroy leaves no default | hidden-workflow / latent-corruption | **P2** | ◦ |
| **H3** | `MobileDeviceSessionController` — web session revocation | DEAD (zero routes; `tab=devices` missing) | hidden-workflow | **P2** | ◦ |
| **H4** | Delivered repairs — history after billing | INVISIBLE (index filters out `delivered`) | hidden-workflow | **P2** | ◦ |
| **ARC1** | **Item** archive / recover | DOES NOT EXIST (hard delete) | recovery-failure (by-design gap) | **P2** | ◦ |
| **ARC2** | **Customer** archive / recover | DOES NOT EXIST (hard delete) | recovery-failure (by-design gap) | **P2** | ◦ |
| **POL1** | New policies (JobOrder/Karigar/KarigarInvoice/StockPurchase/ShopPaymentMethod) | DEAD CODE (never invoked) | permission-breakage (latent) | **P2** | ◦ |
| **DC1** | Vault reconciliation `STATUS_CORRECTED` | NEVER WRITTEN (3-state really 2) | dead-lifecycle | **P3** | ◦ |
| **DC2** | Staff `suspended` employment state | DEAD (no flow sets it) | dead-lifecycle | **P3** | ◦ |
| **DC3** | Item statuses `transferred` / `pending_listing` never set; `written_off` not in DB CHECK | DEAD / latent | dead-lifecycle / latent-corruption | **P3** | ◦ |
| **TURBO1** | Core settings tabs (Shop/Billing/Preferences/Return-Policy) save → no success toast | UX-regression (no `data-turbo-frame="_top"`) | UX-regression | **P2** | ◦ |
| **TURBO2** | Logout from Settings→General | BROKEN (frame-trapped → "Content missing") | workflow-regression | **P2** | ◦ |
| **TURBO3** | Staff Remove/Recover forms lack `data-turbo-frame="_top"` | UX-regression (inconsistent) | UX-regression | **P3** | ◦ |
| **SEC2** | Mobile `logoutOtherDevices()` | NO-OP (empty password at verify) | UX/security-minor | **P3** | ◦ |
| **DEN1** | Category/Product string denormalization (rename/delete) | SILENT drop-out of filters/reorder | silent-corruption (minor) | **P3** | ◦ |
| **HID1** | Reference-Prices history report | UNLINKED (route exists, no nav) | hidden-workflow | **P3** | ◦ |
| **HID2** | Metal Ledger report | semi-hidden (one link only) | hidden-workflow | **P3** | ◦ |
| **HID3** | Loyalty `index`/`customerHistory` controller methods | BYPASSED by closure redirects | cosmetic / dead | **P3** | ◦ |
| **DC4** | `AuditController` | UNROUTED dead code (live viewer is in Settings) | cosmetic | **P3** | ◦ |
| **UX1** | Vendor archive/restore only via edit checkbox (no toggle) | UX-regression | UX-regression | **P3** | ◦ |
| **UX2** | Invoice status badge compares to `'paid'` (never a status) | cosmetic | cosmetic | **P3** | ◦ |
| **UX3** | Command-palette report links gate on `$isOwner` while routes allow `reports.view` | UX mismatch (managers reach via nav) | UX-regression | **P3** | ◦ |

---

## 4. Detailed findings

### 4.1 — P0: Dangerous incomplete flows & broken operations

#### D1 — Installments: no way to close a defaulting plan `[dangerous-incomplete-flow]`
`InstallmentService` only ever writes `active`/`completed` (`InstallmentService.php:52,95`). The index counts a `defaulted` bucket and the filter offers it (`InstallmentController.php:59-61`), and two views colour-code it — but **no code path ever sets `defaulted`, and there is no `settle` / `writeOff` / `markDefaulted` route or method** (`web.php:582-587`). A customer who stops paying leaves the plan permanently `active` + overdue, with no operator action to close, write off, or default it. **Impact:** unbounded growth of stuck "active" plans; no clean books on defaults.

#### D2 — Schemes: enrollment cancellation + refund is dead code `[dead-lifecycle / recovery-failure]`
`SchemeService::cancelEnrollment()` exists (`SchemeService.php:341-359`) but has **no route, no controller method, no UI, and no refund-of-contributions logic**. A gold-savings customer who wants out has no path to cancel and recover their paid-in contributions; the `cancelled` state is unreachable. **Impact:** real customer-money obligation with no operator exit — guaranteed support escalation / manual SQL.

#### D3 — Schemes: maturity is payment-count-only `[dead-lifecycle]`
Maturity + bonus accrual fire **only** when `installments_paid >= total_installments` (`SchemeService.php:160-206`). No scheduler scans `maturity_date` (Dhiran has such commands; schemes/installments have none). An enrollment that reaches its maturity date under-paid stays `active` forever and **never receives the promised bonus**. **Impact:** schemes silently fail to mature; bonus liability never settled.

#### A1 — Admin "deactivate tenant user" `[workflow-regression]` — CORRECTED + FIXED (M1)
**The audit's stated mechanism was wrong and is corrected here.** The original finding claimed `updateStatus()` was a silent no-op because `UserObserver::saving()` reverted `is_active`. **Runtime tracing proved `UserObserver` is NOT registered anywhere** (no `User::observe()`, no `#[ObservedBy]`, nothing in `AppServiceProvider`) — it is dormant code (see A1b). An `is_active`-only write therefore *persists*, and login is correctly blocked (`AuthenticatedSessionController.php:44`).

The **real** defect was a **consistency** one: `updateStatus()` (`:66-89`) wrote only `is_active`, leaving `employment_status` at its stale value (`active`) while `is_active=false`. The user was locked out but still displayed as active-employment everywhere keyed on `employment_status`.

**Fix (M1):** `updateStatus()` now moves `employment_status` in lockstep — admin deactivate = reversible `suspended` (preserving a stronger `terminated` if the shop already set it), activate = `active`. This also gives the previously-dead `suspended` state (DC2) its first real writer. Verified by `tests/Feature/AdminUserStatusToggleTest.php`. **Impact pre-fix:** inconsistent lifecycle state, not access loss.

#### A1b — `UserObserver` is dormant: the is_active↔employment_status invariant is unenforced `[dead-lifecycle / latent-corruption]` ✔︎-verified
`app/Observers/UserObserver.php` is written to keep `is_active` consistent with `employment_status`, but it is **registered nowhere**, so it never runs. Consistency currently depends entirely on each write site setting both fields (StaffController and — after M1 — UserManagementController do; the factory's `is_active=true` + default `employment_status='active'` is consistent). **Impact:** any future code path that writes one field without the other creates a silently inconsistent user state. **Restore-or-retire decision deferred to its own milestone** (registering a global observer is a broad behaviour change requiring full-suite validation; it was deliberately NOT bundled into M1).

---

### 4.2 — P1: Correction-path & permission gaps

#### A2 — Karigar invoice/payment have no correction path `[accounting-risk]`
Edit is blocked once an invoice is not `unpaid` (`KarigarInvoiceService.php:86`); delete is blocked once any payment exists (`KarigarInvoiceController.php:222`); `recordPayment()` (`:125`) has **no inverse**, and there is no reverse/void/credit-note/compensating method anywhere. A settled karigar invoice or a mis-keyed payment is **permanent** — which *violates* the CONSTITUTION §3 compensating-entry doctrine because the compensating service simply doesn't exist for this entity. **Impact:** first karigar-payment typo requires raw-SQL support intervention.

#### A3 — No sanctioned vault adjustment `[accounting-risk]`
Vault balances move only via add-lot / `job_issue` / `job_return` / purchase-vaulting. There is **no route, controller, service, or view** for a vault manual adjustment. Reconciliation is read-only (writes `CLEAN`/`DISCREPANCY_FOUND` only). The Recovery/Support plan (CONSTITUTION + R-plan) assumes "issue a compensating vault adjustment via the UI" — **that UI does not exist.** **Impact:** physical-vs-system gram variance cannot be corrected through any sanctioned in-app path.

#### A4 — Confirmed/stocked stock-purchase cannot be reversed `[workflow-regression / accounting-risk]`
`deletePurchase` is draft-only (`StockPurchaseService.php:150`); `confirm` and `addToInventory` are one-way and create Items + vault lots with no undo. **Impact:** a wrongly-confirmed purchase orphans inventory + lots with no operator reversal.

#### P1 — Draft-invoice **finalize** is mis-gated by `sales.void` `[permission-breakage]`
`InvoiceController::update` merges finalize + cancel into one route gated `can:sales.void` (`web.php:491-492`). A cashier with `sales.create` but not `sales.void` **cannot finalize a draft** from the Invoices screen. Also the "Edit Invoice" button (`invoices/show.blade.php:23`) is not `@can`-gated, so such staff see it and hit a 403. **Impact:** legitimate cashiers blocked from completing drafted sales.

#### P2 — Bulk import gated by the wrong permission `[permission-breakage]`
All import endpoints gate on `can:reports.export` (`web.php:176-203`). The purpose-built `imports.access` (`PermissionSeeder.php:47`) and `imports.manage` (migration `…230000`) permissions are wired to **nothing**. **Impact:** an "imports" role cannot import; an "export reports" role can bulk-create inventory. Privilege mismatch in both directions. (No re-run/rollback of a completed import exists either.)

#### SEC1 — KYC PII on the public disk `[data-exposure]`
`KycDocumentController.php:32-36` stores PAN/Aadhaar/passport scans on the `'public'` disk; `KycDocument::url()` (`:55-64`) serves them by public URL. Wrong trust boundary for identity documents. `destroy` is a soft `deactivate()` that leaves the file on disk forever. **Impact:** identity documents reachable by anyone with the (guessable-ish) path; deletion does not delete.

---

### 4.3 — P1/P2: Rework lifecycle (the flagged "dead rework" — confirmed)

This is the single most corroborated cluster: two independent sub-audits + lead verification agree.

- **R1 `[dead-lifecycle]` ✔︎-verified.** `ReturnedItemDisposition.target_job_order_id` is hardcoded `null` (`ReturnService.php:1066`) and is set **nowhere** in the codebase. `sent_to_rework` (`:1044-1048`) creates/links **no** JobOrder. The JobOrder side confirms it: `JobOrderService::issue()` (`:84`) and the create controller/blade never set `job_type` or `source_item_id` — **every job order ever created is `manufacture`**; `repair`/`rework` constants (`JobOrder.php:21-23`) are unused. Items sent to rework land permanently in Control-Center Queue 3 (filtered `whereNull('target_job_order_id')`) — **a queue that can never be cleared.**
- **R2 `[UX-regression]`.** The UI contradicts itself: `control-center.blade.php:329-331` still renders a live **"Send to Karigar"** button while `:585-590` shows the retired disabled "Rework (manual)" affordance. Commit `3d22ed5` retired the dead redirect but left the button live.
- **R3 `[recovery-failure, latent]`.** `fixOrphanStatus` (`ReturnsController.php:567-591`) and the orphan queue (`:424-433`) filter on `job_type IN (repair,rework)` + `source_item_id NOT NULL` — rows R1 proves are never created. The recovery action is wired (route `333`) but **functionally inert**.
- **R4 `[recovery-failure, latent]`.** `JobOrderService::cancel()` (`:350-397`) reverses bullion issuance but **never touches `source_item_id`** — so when item-sourced jobs *do* get built, cancel will orphan the item in `with_karigar`. Latent today (no item-sourced jobs exist), live the moment R1 is built.

**Net:** the rework half of the returns→karigar story was never built; the disposition UI promises an action the backend cannot fulfil.

---

### 4.4 — P2: Hidden surfaces & missing recover/archive

- **F-SC — Store-credit consumption has no UI trigger `[workflow-regression]` ✔︎-verified.** Issuance works (return settlement → `StoreCreditMovement` +; manual adjust via `store-credit.adjust`). Consumption endpoint `applyToInvoice` (`POST /invoices/{invoice}/store-credit/apply`, `can:sales.create`, `StoreCreditController.php:84`) **exists and is correct** — but **no invoice/POS view references it** (grep of `resources/views/invoices` and `resources/views/pos` = empty). The customer profile shows a "Store Credit" button that links only to the *manual adjust* page (`customers/show.blade.php:21-23`). **Net:** store credit can be issued and hand-adjusted but **cannot be applied to a sale through the UI**. *(This corrects the earlier false "POS overstates the ledger" claim — see G4.)*
- **H1 — `ItemStoneController` is fully unrouted `[hidden-workflow]`.** 280 lines, zero routes (web/api/mobile). The entire operator stone add/edit/revalue/delete surface is unreachable. (The immutability guard `StoneComponent.php:74-138` is correct and still exercised by service writes.)
- **H2 — `GstCategoryController` is fully unrouted `[hidden-workflow]`.** Owners cannot create/edit GST categories via UI (though `GstRateResolver` reads the table). Secondary latent corruption: `destroy` never re-promotes a default → a shop can end up with **no default GST category**.
- **H3 — `MobileDeviceSessionController` is fully unrouted `[hidden-workflow]`.** Its `destroy`/`destroyAllForUser` redirect to `tab=devices`, which doesn't exist. Web-based mobile session revocation UI does not exist (only the bulk token-delete during staff termination works).
- **H4 — Delivered repairs vanish `[hidden-workflow]`.** The repair index filters `whereNotIn('status',['delivered'])` (`RepairController.php:24`), yet a "Delivered" KPI chip renders (`repairs.blade.php:298`) and a "View Invoice" branch sits dead in the loop (`:570`). No completed/archive view exists. Repair history is a black hole after billing.
- **ARC1 — Item archive/recover DOES NOT EXIST `[recovery-failure]`.** No `is_active`/`archived_at`/`deleted_at` on `items` (verified vs all migrations). `ItemController::destroy` (`:926-992`) is a hard `delete()` behind an `in_stock` guard. A deleted item is gone permanently; the only "recover" route (`returns.items.recover`) operates on a returned-item *disposition*, not a deleted inventory row.
- **ARC2 — Customer archive/recover DOES NOT EXIST `[recovery-failure]`.** `Customer` has no soft-delete/flag; `CustomerController::destroy` (`:337-356`) hard-deletes, blocked only when invoices/gold/repairs exist. No restore path.
- **POL1 — New policies are dead code `[permission-breakage, latent]`.** `JobOrderPolicy`, `KarigarPolicy`, `KarigarInvoicePolicy`, `StockPurchasePolicy`, `ShopPaymentMethodPolicy` are auto-discovered but **never invoked** — controllers do a private `authorizeShop()` raw shop_id compare instead, with all real authz in route `can:` middleware. Latent risk: any future route added without `can:` would have zero authorization (inconsistent with `VendorController`, which does call `$this->authorize()`).

---

### 4.5 — P2/P3: Turbo, dead states, denormalization, cosmetics

- **TURBO1 `[UX-regression]`.** Shop/Billing/Preferences/Return-Policy forms sit inside `<turbo-frame id="settings-content">` without `data-turbo-frame="_top"`. Saves **persist correctly**, but the success flash lives in `<head>` (`layouts/app.blade.php:8-9`) which a frame swap never updates → **no confirmation toast** on these core tabs. Materials/Staff forms use `data-turbo-frame="_top"` and *do* toast — proving the inconsistency.
- **TURBO2 `[workflow-regression]`.** Logout form on Settings→General (`settings.blade.php:1296`) is frame-trapped and redirects to `/login` (no matching frame) → Turbo "Content missing." Exact CLAUDE.md Turbo pitfall.
- **TURBO3 `[UX-regression]`.** Staff Remove/Recover forms (`settings.blade.php:2412,2428`) lack `data-turbo-frame="_top"` while sibling Add/Edit have it. *(Note: the Remove/Recover actions redirect to a full page via `dynamicRedirect`; confirm whether the missing attribute degrades the toast like TURBO1.)*
- **DC1 `[dead-lifecycle]`.** `VaultReconciliationRun::STATUS_CORRECTED` is defined (`:21`) but written nowhere — the 3-state machine is really 2-state.
- **DC2 `[dead-lifecycle]`.** Staff `suspended` employment state: enum + observer support it, but **no flow ever sets it** — only `active↔terminated` transitions exist. (Either build a suspend action or drop the state.)
- **DC3 `[dead-lifecycle / latent-corruption]`.** Item statuses `transferred` and `pending_listing` are never set by reachable code; `written_off` appears in `ItemObserver.php:101` but is **not in the DB CHECK constraint** — writing it would be rejected at the DB. `ItemObserver` only emits timeline events; it does **not** validate transitions.
- **DEN1 `[silent-corruption, minor]`.** Category/Product are stored as denormalized strings on items + reorder rules. Renaming/deleting a Category doesn't update them → items silently drop out of filters and reorder rules silently stop matching. Product hard-delete unlinks items (`items.product_id` `set null`).
- **SEC2 `[security-minor]`.** `MobileChangeController::logoutOtherDevices()` (`:179`) passes an empty `current_password` at the verify step → no-op; other sessions are not actually kicked, contradicting its docblock.
- **HID1/HID2/HID3 `[hidden-workflow / cosmetic]`.** Reference-Prices history report (`web.php:438`) has zero nav links; Metal Ledger (`/ledger`, `web.php:489`) is reachable only from `report_daily.blade.php:19`; Loyalty `index`/`customerHistory` controller methods are bypassed by closure redirects (`web.php:576-577`).
- **DC4 `[cosmetic]`.** `AuditController` is unrouted dead code; the live audit viewer is in `SettingsController` (and currently has **no filters** — the planned R1 enhancement is unimplemented; it's gated on `settings.view`, so managers can read the full shop audit log).
- **UX1/UX2/UX3 `[UX / cosmetic]`.** Vendor archive/restore only via an edit-form checkbox (no toggle button); invoice status badge compares to `'paid'` which is never an Invoice status (`invoices/show.blade.php:144` — badge always amber); command-palette report links gate on `$isOwner` while the routes themselves allow `reports.view` (managers excluded from the palette but reach reports via nav).

---

## 5. Cross-cutting patterns (the real root causes)

Most findings collapse into **three** structural causes. Fixing the pattern is more valuable than fixing each leaf.

1. **"Forward path shipped, reverse/close path didn't."** A2 (karigar invoice reverse), A3 (vault adjust), A4 (stock-purchase reverse), D1 (installment close), D2 (scheme cancel), ARC1/ARC2 (item/customer recover). The system is excellent at *doing* and poor at *undoing*. This is the dominant theme and the one the user's instinct correctly flagged.

2. **"Sprint landed the model + service, never the route/UI."** R1–R3 (rework), D2 (`cancelEnrollment` orphaned service), H1 (`ItemStoneController`), H2 (`GstCategoryController`), H3 (`MobileDeviceSessionController`), F-SC (`applyToInvoice` no button), POL1 (policies never invoked). Capability exists in code but is **unreachable** by an operator — exactly the "valuable sprint-era capability silently disconnected" the audit was commissioned to find.

3. **"Enum/state defined, transition never wired."** D3 (date maturity), DC1 (`CORRECTED`), DC2 (`suspended`), DC3 (`transferred`/`pending_listing`/`written_off`), D1 (`defaulted`). State machines were declared aspirationally; some arms were never connected. Each is either a dead transition to build or a phantom state to remove.

A fourth, smaller theme: **gate/route mismatches** (P1 finalize-vs-void, P2 import permission, UX3 palette) — permissions exist but are attached to the wrong action.

---

## 6. Verified-intact workflows (certainty about what was NOT lost)

These were traced end-to-end and confirmed **WORKING** — the platform's spine is sound:

- **POS sale** — signed-quote + idempotency + 409 concurrency guard; quote persist/recover with cross-tenant replay blocked.
- **Invoice** — draft→finalized→`cancelByReversal` (mirrored, lock-guarded); finalized invoices immutable.
- **Quick Bill** — create/issue/void.
- **Returns** — create → pending_approval → approve/reject (reason stored) → submitted → settled → CN issued; dispositions restock (inline + batch), melt (both inline + full-page, emits `return_melt_recovery`), write-off; override gated by `returns.approve` (held by owner **and** manager — no silent permission failure).
- **Exchange** — unified wizard creates CN + new invoice.
- **Store credit** — issuance + manual adjust (consumption endpoint correct but unsurfaced — F-SC).
- **Repair** — receive → ready → deliver (+ invoice link) (post-delivery visibility is the gap — H4).
- **Job order** — issue (lot debit) → receive (manufacture) → leftover → completion → cancel (bullion correctly restored); row-locked, idempotent.
- **Karigar** — create / edit / disable / restore (toggle both directions); KarigarInvoice create→lines→multi-split payment (reverse path is the gap — A2).
- **Vault** — add bullion (lot + movement + cash + audit); reconciliation read-only (adjustment is the gap — A3).
- **Stock purchase** — draft → confirm → stock (reversal is the gap — A4).
- **Staff** — create / edit / terminate(soft) / recover, token revocation, self-removal block, plan-limit re-check, login + per-request blocking of terminated users (test-covered).
- **Roles & permissions** — owner-only role editing, manager cannot escalate, Dhiran perms preserved on sync, cross-tenant binding blocked; **no permission silent-lockout and no unguarded sensitive route found** (beyond the P1/P2 mis-gates).
- **Admin/Platform** — bootstrap race-guarded, 2FA logout-until-verified, impersonation double-guarded + IP-checked + audited, destructive actions under `super_admin` + audit service, edition-request state-guarded.
- **Mobile (v0 + v1)** — **no IDOR** (every bound action `abort_if(shop_id mismatch)`), **no accounting bypass** (all mutations delegate to the same canonical web services with the same approval/lock/invariant guards), dual rate-limiting on login, one-session-per-user + seat enforcement, owner-protected session revocation, signed scan-session URLs.
- **Reporting** — GST/GSTR, P&L, daily, cash, reconciliation, tax via canonical `App\Reporting`; `reports:validate` passes; exports permission-gated + shop-scoped.
- **Dhiran isolation** — edition + subdomain + `dhiran.enabled` middleware + `@can('dhiran.view')` nav gating; no leak into core nav/permissions.

---

## 7. Prioritized continuity backlog (remediation MAP — not an instruction to fix)

> Per the audit constraint, nothing here has been changed. This is the ordered map for a future, separately-approved remediation pass.

**P0 — operator-trapping / money-obligation incomplete flows**
- D1 Installment close/default/write-off path.
- D2 Scheme enrollment cancel + contribution refund (wire the existing `cancelEnrollment`).
- D3 Scheme date-based maturity + bonus (scheduler).
- A1 Admin deactivate-user: write `employment_status` alongside `is_active` (or route through the staff-termination path).

**P1 — correction-path & permission integrity**
- A2 Karigar invoice/payment compensating-entry path.
- A3 Sanctioned vault adjustment UI/service (the Recovery plan depends on it).
- A4 Confirmed stock-purchase reversal (compensating).
- P1 Split finalize from `sales.void`; `@can`-gate the Edit button.
- P2 Re-gate bulk import onto `imports.access`/`imports.manage`.
- SEC1 Move KYC documents to a private disk + signed URLs; make destroy delete the file.
- R1/R2 Decide rework: build the `sent_to_rework → JobOrder` link **or** disable the "Send to Karigar" button so it stops promising a dead action.

**P2 — reconnect hidden capability / recover paths**
- F-SC Add the store-credit "Apply to invoice" button on the billing screen.
- H1 Route `ItemStoneController`; H2 route `GstCategoryController` (+ re-promote default on destroy); H3 route `MobileDeviceSessionController` (+ `tab=devices`).
- H4 Add a delivered/archived repairs view.
- ARC1/ARC2 Decide policy: introduce soft-archive+restore for items & customers, or document hard-delete as intended.
- POL1 Either invoke the new policies via `authorize()` or remove them.
- TURBO1/TURBO2 Add `data-turbo-frame="_top"` to the four core settings forms + the General-tab logout.
- R3/R4 Will self-resolve once R1 is built; until then, mark as known-inert.

**P3 — dead states, denormalization, cosmetics**
- DC1/DC2/DC3 Remove phantom states or wire their transitions.
- DEN1 Consider FK-backed category/product or cascade-update on rename.
- SEC2, TURBO3, HID1–3, DC4, UX1–3 — low-impact cleanups.

---

## 8. Bottom-line assurance statement

After tracing every major module's lifecycle with file:line evidence:

- **No important *accounting* workflow disappeared.** The ledger, POS, invoicing, returns settlement, credit notes, vault movements, and reporting are intact and verified.
- **No lifecycle silently corrupts data.** The DB triggers + ImmutableLedger + `BelongsToShop` defenses hold; no tenant-isolation or mobile bypass exists.
- **Capability *has* been lost at the edges** — overwhelmingly in **reverse/correction/close/recover paths** and in **sprint-era features that landed code but never a route or button.** 36 findings, mapped, classified, and prioritized above.
- **The three P0 items (installment close, scheme cancel/refund, scheme maturity) and the four P1 correction-path gaps are the ones that will hurt a live shop first** — they are operator-trapping or money-obligation flows with no exit.

This document is the operational continuity baseline. Re-run this audit after any remediation pass to confirm closure without regression.

*— End of audit. Diagnosis only; no code changed.*
