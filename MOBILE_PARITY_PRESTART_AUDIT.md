# Mobile Parity — Pre-Start Audit

*Date: 2026-06-06 · Read-only diagnosis. No code, routes, API, or migrations changed.*

Goal: determine actual web vs mobile parity and identify pilot blockers.

**Mobile surface = two APIs:**
- `routes/mobile.php` (13 controllers) — the **retail / POS counter app**: auth, bootstrap, dashboard, items, vendors, customers, stock, repairs, invoices, quick-bills, catalog, pos, scan, pricing.
- `routes/mobile_v1.php` (10 V1 controllers) — a newer **operations app**: sessions/seats, uploads, registry, items (read+edit), customers (read+edit), karigars, job-orders, returns, reference-prices.

Web surface = `routes/web.php` (full ERP). Permission enforcement on both sides is Laravel's `can:` `Authorize` middleware over the **same** RBAC gate ([Kernel.php:31](app/Http/Kernel.php#L31)); the mobile `CapabilityResolver` gates the bootstrap by plan-feature **and** `user->hasPermission()`, with owners bypassing — identical to web.

---

## Section 1 — Feature Parity Matrix

| Feature | Web | Mobile | Status | Parity % |
|---------|-----|--------|--------|---------:|
| Dashboard | Full KPIs + drill-downs | Read-only KPI summary (`/dashboard`, `reports.view`) | **PARTIAL** | ~60% |
| Customers | CRUD + context + loyalty | index/search/context/store (`mobile`) + show/update (`v1`); no delete, no KYC | **PARTIAL** | ~70% |
| Suppliers (vendors) | CRUD + ledger (7) | CRUD + ledger (full) | **FULL** | ~95% |
| Inventory (items) | 30 routes (CRUD, bulk, adjust) | search/barcode/store/show/update; no bulk/adjustment screens | **PARTIAL** | ~65% |
| Catalogue | 10 routes | items/categories/template/collections | **PARTIAL** | ~70% |
| Sales (POS/invoices) | pos(9)+invoices(5)+quick-bills(8) | pos bootstrap/preview/sell/quote, invoices index/show/template/payments, quick-bills full | **PARTIAL (high)** | ~85% |
| Purchase | stock-purchases CRUD+confirm+stock+reverse (retailer) | stock **view** only; no purchase creation | **MISSING** | ~15% |
| Returns | returns(14)+exchanges(7) | v1 returns index/show/store/approve; **no exchanges, no disposition/recovery** | **PARTIAL (low)** | ~40% |
| Reports | 49 report routes (spine) | **none** — only dashboard KPIs; no report screens, no exports | **MISSING** | ~5% |
| Vault | vault(6)+lots+ledger | **none** | **MISSING** | ~0% |
| Karigar | karigars(8)+job-orders(11)+karigar-invoices(10) | v1 karigars(read), job-orders index/show/store/receipt; **no karigar-invoices/settlement** | **PARTIAL** | ~45% |
| Dhiran (pawn/loans) | dhiran, loans, customer-loans, forfeit, notices | **none** | **MISSING** | ~0% |
| KYC | kyc-documents (3) | **none** | **MISSING** | ~0% |
| Settings | 30 routes | pricing/today + catalog template only | **PARTIAL (low)** | ~15% |
| Staff | staff (7) | **none** | **MISSING** | ~0% |
| Notifications | minimal (dhiran send-notice only) | **none** | **MISSING** (feature thin on web too) | ~0% |

**Aggregate mobile parity ≈ 45%** — strong on counter operations (sales, suppliers, customers, catalogue, inventory read/write), weak-to-absent on management (reports, vault, settings, staff, dhiran, KYC) and back-office (purchase, exchanges).

---

## Section 2 — Screen / Endpoint Inventory (exact counts)

- **Web routes (named): ~370** across ~40 feature areas (top: report 49, settings 30, inventory 30, returns/exchanges 21, schemes 13, job-orders 11, karigar-invoices 10, customers 10, catalog 10).
- **Mobile endpoints: 67** total — `mobile.php` **44** + `mobile_v1.php` **23**.
- **Mobile controllers: 23** (13 `Api/Mobile` + 10 `Api/Mobile/V1`).

| Category | Count | Examples |
|----------|------:|----------|
| Mobile endpoints with a web equivalent | ~67 | items, customers, pos, invoices, repairs, vendors, returns, job-orders |
| **Web feature areas with NO mobile presence** | **9** | reports, vault, dhiran, kyc, staff, schemes, installments, loyalty, exchanges |
| Mobile-only constructs (no direct web analog) | 4 | bootstrap, capabilities, mobile **sessions/seats**, **uploads** intent/token |
| Partial mobile (subset of web) | 7 | dashboard, customers, inventory, catalogue, returns, karigar, settings |

---

## Section 3 — Workflow Parity

| Workflow | Web | Mobile | Classification |
|----------|-----|--------|----------------|
| Customer creation | full | `mobile` store (name/mobile) | **Partial** (no full profile fields) |
| Customer KYC | kyc-documents upload/verify | **none** | **Missing** |
| Sales (POS) | full POS + finalize + payments | pos preview/sell/quote/persist + invoice payments | **Full parity** (counter) |
| Purchase (stock intake) | create→confirm→add-to-inventory→reverse | **none** (stock view only) | **Missing** |
| Returns | create→settle→disposition→melt/rework→credit note | v1 create + approve | **Partial** (no fate decision / recovery / exchange) |
| Inventory adjustments | edit, bulk, status, reprice | item update | **Partial** |
| Vault operations | add lot, issue, receive, melt, reconcile | **none** | **Missing** |
| Catalogue usage | template, collections, share, WhatsApp | items/categories/template/collections | **Partial (high)** |
| Order lifecycle (quick-bill→invoice) | full | quick-bills CRUD/void + invoices | **Partial (high)** |
| Karigar workflow | job order issue→receipt→settle (invoice) | v1 job-orders store/receipt | **Partial** (no settlement/invoice) |
| Dhiran workflow | loan→interest→forfeit | **none** | **Missing** |

---

## Section 4 — API Parity

- **Same RBAC gate** for web and mobile (`can:` = `Authorize`). No second permission engine.
- **Mobile-only endpoints** (no web analog, correctly mobile-specific): `/bootstrap`, `/pos/bootstrap`, mobile **sessions** (lock/unlock/seats), **uploads** (intent/token), `/scan/*`, capabilities envelope, `mobile.idempotency` / `mobile.envelope` middleware.
- **Web-only domains (no mobile endpoint):** reports, vault, dhiran, kyc, staff, schemes, installments, loyalty, exchanges, purchase-create, cashbook/expenses.
- **Contract drift (MEDIUM):** the bootstrap `CapabilitiesData` advertises **`purchases, expenses, schemes, loyalty, installments, cashbook`** ([CapabilityResolver.php:21-43](app/Services/Mobile/CapabilityResolver.php#L21)), but **no mobile endpoints exist** for any of them. The app may render tiles/flows that dead-end → support risk.
- **Two-API split:** `mobile.php` (POS/retail) and `mobile_v1.php` (operations) use **different conventions** (v1 adds `mobile.envelope`, `mobile.idempotency`, seat sessions; permission names like `job_order.view` with an underscore). This is itself a semantic-consistency item for the next audit.

---

## Section 5 — Permission Parity (classified)

| Finding | Detail | Severity |
|---------|--------|:--------:|
| Mobile writes are gated | Every `mobile.php` business write carries a `can:` gate (only `/auth/login`, `/auth/logout` ungated — correct); v1 writes gated (`inventory.create/edit`, `customers.edit`, `sales.create`, `job_order.view`, `settings.view`) | **LOW (good)** |
| Same gate engine | `can:` → `Authorize` over the same RBAC for web + mobile; `CapabilityResolver` adds plan-feature gating; owners bypass on both | **LOW (good)** |
| Capability-vs-endpoint drift | Bootstrap advertises capabilities with no backing endpoint (§4) — not a bypass, but a contract inconsistency | **MEDIUM** |
| Permission-name drift | v1 uses `job_order.view` (underscore) while web job-order routes use other conventions; needs the semantic audit to confirm the canonical permission name | **MEDIUM** |
| Read-gate nuance | v1 returns read gated on `sales.view` "because all sales staff have it" (documented in-file) rather than a returns-specific permission | **LOW** |

No HIGH permission findings: no missing gate on a mobile write, no weaker gate than web, no observed bypass. (Consistent with the recent mobile authz security review on this branch.)

---

## Section 6 — Report Parity

| Report class | Web | Mobile |
|--------------|-----|--------|
| Compliance (GST, GSTR-1/3B, CN register) | spine, full export | **No mobile access** |
| Accounting (cash flow, daily closing, payment recon, daily summary, metal liability, inventory valuation, metal ledger) | spine, full export | **No mobile access** |
| Owner (P&L, Gold Balances) | spine, CONFIDENTIAL | **No mobile access** |

**Mobile report access = none.** The only reporting-adjacent mobile surface is the
`/dashboard` KPI summary (gated `reports.view`). No report screens, **no export
path** (`report_exports` audit is web-only), no per-report permissions surfaced on
mobile. For a pilot owner who works mobile-first, **all reports require web**.

---

## Section 7 — Navigation & UX Parity

- **Web:** deep, feature-area navigation (~40 areas, ~370 routes); every workflow reachable; report hub; settings hub.
- **Mobile:** capability-driven home (bootstrap advertises tiles), shallow task-first flows for counter work (POS, scan, quick-bill, customer, repair).
- **Friction points:**
  1. Tiles advertised by capabilities (purchases/schemes/loyalty/installments/cashbook) with no working endpoint → dead-ends.
  2. Returns on mobile can be created/approved but not **dispositioned** (melt/rework/restock) — the operator must finish on web.
  3. No mobile reports → owner context-switches to web for any figure beyond the dashboard KPI.
  4. Two mobile apps with different envelopes/conventions → inconsistent behavior between POS app and operations app.
  5. Customer creation on mobile captures only name/mobile; full profile + KYC needs web.

---

## Section 8 — Pilot Blockers (ranked)

**1. What prevents mobile users from operating fully?** Management & back-office
domains are absent: reports, vault, purchase intake, exchanges, dhiran, KYC,
staff, settings, retailer-finance (schemes/installments/loyalty/cashbook).

**2. What prevents staff from completing daily work?** Mostly nothing for a **sales
counter** (POS, customers, repairs, quick-bills, invoices all present). Gaps that
*do* interrupt counter staff: returns **disposition/recovery** (must finish on
web), **stock intake** (purchase) on mobile, and **exchanges**.

**3. What causes web/mobile inconsistency?** The two-API split (different
envelopes/permission-name conventions) and the capability-vs-endpoint drift.

**4. What would create support tickets?** Dead-end capability tiles; "where are my
reports on mobile?"; "I can't finish this return on the app"; "I can't add stock
purchase on mobile"; customer/KYC fields missing on mobile.

| Rank | Blocker |
|------|---------|
| **CRITICAL** | *(none that hard-block a POS-counter pilot)* — but **if the pilot expects mobile-first management**, the absence of mobile **reports** + **vault** is critical |
| **HIGH** | Returns disposition/recovery missing on mobile; capability-vs-endpoint dead-ends; no mobile reports/exports |
| **MEDIUM** | Purchase intake, exchanges, KYC, full customer profile, settings on mobile; two-API convention drift |
| **LOW** | Staff, dhiran, schemes/loyalty/installments on mobile (owner/desktop tasks); dashboard drill-down |

---

## Section 9 — Recommended Execution Order (no estimates)

1. **Remove dead-ends first (cheapest, highest support-ticket payoff):** reconcile
   `CapabilitiesData` with actual endpoints — either hide unimplemented tiles or
   implement their endpoints. (Quick win.)
2. **Close the counter-workflow gaps (pilot-facing):** returns
   disposition/recovery on mobile; then exchanges.
3. **Mobile report access (highest management value):** a read-only mobile report
   surface over the existing spine datasets (screen + export), reusing
   `reports.view` / `report_exports` — leverages the now-frozen spine, no new
   report logic.
4. **Stock purchase intake** on mobile (back-office parity).
5. **Customer profile + KYC** on mobile.
6. **Settings essentials** on mobile (the high-use subset, not all 30).
7. **Converge the two mobile APIs** onto one convention (envelope, idempotency,
   permission names) — overlaps with the API semantic audit.
8. **Defer:** vault ops, dhiran, staff, schemes/loyalty/installments — owner/desktop
   tasks, lowest mobile demand.

(Highest-risk gaps and pilot blockers — items 1–3 — are sequenced first; item 1 is
the quickest win.)

---

## Final Verdict

- **Mobile parity: ≈ 45%** (counter operations strong; management/back-office weak-to-absent).
- **Critical gap count: 0** for a POS-counter pilot · **2** if the pilot is mobile-first management (reports, vault).
- **High-risk gap count: 3** (returns disposition/recovery, capability dead-ends, no mobile reports/exports).
- **Pilot readiness:** Mobile is a capable **counter / POS + light-operations companion**, not a full web replacement. For a pilot where **mobile = counter operations and web = management**, the core daily flows are present and permission-safe; the named HIGH gaps should be closed before relying on mobile for returns-heavy or report-driven work.

# READY FOR MOBILE PARITY IMPLEMENTATION

The parity surface, gaps, and blockers are fully identified and evidence-backed;
nothing prevents planning/implementing mobile parity. Sequence per §9 — quick win
(capability dead-ends) first, then the counter-workflow and report-access gaps.

*(Separate, per standing instruction: no reporting work and no dashboard work were
performed in this audit.)*
