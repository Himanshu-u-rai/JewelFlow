# API Semantic Audit

*Date: 2026-06-06 · Read-only diagnosis. No code changes.*

Goal: verify that web, mobile, services, controllers, policies, permissions, and
database semantics **agree**. Findings are categorized and severity-ranked; every
finding is evidence-backed.

---

## Summary of findings

| # | Category | Finding | Severity |
|---|----------|---------|:--------:|
| 1 | Duplicate concept | Two classes named `SalesService` in different namespaces | **HIGH** |
| 2 | Permission drift | Owner-bypass implemented two different ways (capabilities vs gate) | **HIGH** |
| 3 | Permission naming | Three inconsistent permission-name conventions coexist | **MEDIUM** |
| 4 | Naming drift | `vendor` (domain) vs `supplier` (mobile capability/UI) | **MEDIUM** |
| 5 | Contract drift | Mobile customer-create captures a different field set than web | **MEDIUM** |
| 6 | Contract drift | Bootstrap advertises capabilities with no backing endpoint | **MEDIUM** |
| 7 | Surface drift | Two mobile APIs with different envelopes/conventions | **MEDIUM** |
| 8 | Authz mechanism | Dotted-permission `can:` gate vs model Policy classes coexist | **MEDIUM** |
| 9 | Meaning drift | Status vocabularies inconsistent across domains | **LOW** |
| 10 | Hidden assumption | `BelongsToShop` scope needs explicit `TenantContext` in non-HTTP code | **LOW** (known) |

No finding indicates a security bypass; these are consistency/maintainability and
contract-clarity risks.

---

## 1. Duplicate concept — two `SalesService` classes · HIGH

```
app/Reporting/SalesService.php   → payment reconciliation, metal exchange (reporting reads)
app/Services/SalesService.php    → the operational POS sales service
```
Two unrelated classes share the name `SalesService` in different namespaces. Any
reference (`use App\…\SalesService`) is ambiguous at a glance, and the reporting
one (`App\Reporting\SalesService`) does **not** sell anything — it reconciles. This
is a genuine duplicate-concept hazard: a contributor importing "the SalesService"
can easily wire the wrong one.

**Evidence:** both files exist; `App\Reporting\SalesService::paymentReconciliation()`
(reporting) vs `App\Services\SalesService` (POS). **Recommendation (later):** rename
the reporting one (e.g. `SalesReportingService`) to match its siblings
(`GstReportingService`, `ProfitReportingService`, `ReceivablesService`).

---

## 2. Permission drift — owner-bypass implemented two ways · HIGH

- **Mobile bootstrap capabilities** explicitly short-circuit owners:
  `CapabilityResolver::userAllows()` → `if ($user->isOwner()) return true;` else
  `hasPermission()` ([CapabilityResolver.php:127-132](app/Services/Mobile/CapabilityResolver.php#L127)).
- **Route enforcement** (web **and** mobile `can:`) goes through
  `Gate::before(fn) → $user->hasPermission($ability) ? true : null`
  ([AppServiceProvider.php:79-81](app/Providers/AppServiceProvider.php#L79)) — **no**
  `isOwner()` bypass. It defers entirely to the owner role's permission list.

**Consequence:** the two paths agree **only while the owner role is
permission-complete**. If a new permission is added without backfilling the owner
role (the program has been adding permissions — `imports.manage`, `billing.view`),
then for an owner: the **bootstrap advertises the capability** (isOwner→true) but the
**API route 403s** (`can:newperm` → hasPermission false). Capability says yes,
enforcement says no.

**Hidden assumption:** "owner role always holds every permission." It is implicit,
unenforced, and the two owner-bypass implementations will diverge the moment it
breaks. **Recommendation (later):** make owner-bypass a single source of truth —
either add `isOwner()` to `Gate::before`, or drop the explicit bypass in
`CapabilityResolver` and rely on a guaranteed-complete owner role.

---

## 3. Permission naming — three conventions coexist · MEDIUM

| Convention | Examples |
|------------|----------|
| `plural_noun.verb` (dominant) | `customers.view`, `inventory.edit`, `vendors.view`, `repairs.create`, `returns.approve`, `sales.pos`, `staff.manage`, `vault.manage` |
| `singular_snake.verb` | `job_order.view`, `job_order.manage`, `karigar_invoice.view`, `karigar_invoice.manage`, `karigar.view` |
| `noun.snake_verb` | `reports.daily_closing` |

The same system mixes plural/singular nouns and snake-cased multi-word segments.
A developer cannot predict whether the karigar-job permission is `job_orders.view`
or `job_order.view` without grepping. Mobile v1 uses the singular_snake form
(`job_order.view`), reinforcing the split. **Recommendation (later):** pick one
convention (recommend `plural_noun.verb`) and alias the rest.

---

## 4. Naming drift — vendor vs supplier · MEDIUM

The domain is **vendor** end-to-end: `Vendor` model, `VendorController`,
`vendors.*` permissions, `vendors.*` web routes, `mobile.vendors.*` endpoints — but
the mobile **capability flag is `suppliers`** ([CapabilityResolver.php:29](app/Services/Mobile/CapabilityResolver.php#L29),
gated on the `vendors` plan feature). So the app surfaces "Suppliers" while every
other layer says "Vendors." Two words, one concept. **Recommendation (later):**
standardize on one term in user-facing + capability naming.

---

## 5. Contract drift — customer creation differs by surface · MEDIUM

| Surface | `store` accepts |
|---------|-----------------|
| Web `CustomerController` | full profile incl. `anniversary_date`, … (many fields) |
| Mobile `Api/Mobile/CustomerController` | `first_name`, `last_name`, `mobile` only |
| Mobile v1 `CustomerController` | `update` only (no create) |

A "Customer" created on mobile is a **different shape** than one created on web
(minimal vs full profile). Same entity, two creation contracts. Acceptable if the
extra fields are nullable, but it is undocumented contract drift and a source of
"why is this customer missing data?" confusion. (Three `CustomerController`s and
three `ItemController`s exist — expected for multi-surface, but their validation/
response contracts are not centrally specified.)

---

## 6. Contract drift — capabilities advertised without endpoints · MEDIUM

`CapabilitiesData` advertises `purchases`, `expenses`, `schemes`, `loyalty`,
`installments`, `cashbook` — **none have a mobile endpoint** in `mobile.php` or
`mobile_v1.php`. The bootstrap contract promises features the API does not serve.
(Detailed in `MOBILE_PARITY_PRESTART_AUDIT.md` §4.) **Recommendation (later):**
the capability set must be the truth of what the API serves — hide or implement.

---

## 7. Surface drift — two mobile APIs, two conventions · MEDIUM

| Aspect | `routes/mobile.php` | `routes/mobile_v1.php` |
|--------|---------------------|------------------------|
| Envelope | none | `mobile.envelope` |
| Idempotency | none | `mobile.idempotency` |
| Sessions | token only | seat-based `sessions` (lock/unlock/destroy) |
| Permission names | `customers.view`, `sales.pos` | `job_order.view`, `inventory.create` |
| Uploads | n/a | intent/token flow |

Two coexisting mobile API generations with different middleware, response
envelopes, and conventions. A mobile client must know which API a given feature
lives in and adapt its envelope/error handling accordingly. **Recommendation
(later):** converge on the v1 conventions (envelope + idempotency) as the standard.

---

## 8. Authorization mechanism — `can:` gate vs Policy classes · MEDIUM

Two authorization mechanisms coexist:
- **Dotted-permission gate:** route `can:inventory.edit` → `Gate::before` →
  `hasPermission` (bypasses policy resolution for dotted abilities).
- **Model policies:** 17 Policy classes (`ItemPolicy`, `JobOrderPolicy`,
  `VendorPolicy`, …) invoked via `$this->authorize('update', $model)`, which
  internally map model abilities to the same permission strings
  (`inventory.edit`, `job_order.manage`, …).

Because `Gate::before` returns `true` for any held dotted permission, a route
`can:` check and a policy check can both guard the same action through different
code. They agree **today** (policies defer to the same `hasPermission`), but the
dual mechanism means future divergence is possible (e.g., a policy adds an
ownership/tenant nuance the route `can:` does not). **Recommendation (later):**
document which layer is authoritative per resource; prefer policies for
model-scoped actions, route `can:` for collection/feature gates.

---

## 9. Meaning drift — status vocabularies · LOW

Each domain defines its own status set with no shared convention:
- Repairs: `received → in_repair → ready → delivered` (no `pending`).
- Return orders: `draft → pending_approval → submitted → settled → cancelled`.
- Items: `in_stock, sold, returned, melted, transferred, reversed, pending_listing, pending_restock, with_karigar`.

"pending" means different things (or nothing) across domains — the dashboard
open-repairs KPI bug (counting a non-existent `pending` repair status) was a direct
symptom of this drift. Acceptable per-domain, but there is no shared status
vocabulary or naming guideline. **Recommendation (later):** a documented status
glossary per entity.

---

## 10. Hidden assumption — tenant scope outside HTTP · LOW (known)

`BelongsToShop` resolves the shop from `TenantContext::get() ?? Auth::user()?->shop_id`
and adds the `where shop_id` only when that is non-null. In **HTTP** the `tenant`
middleware sets context; in **CLI / queued / service** code it is null unless
`TenantContext::runFor()` wraps the call. This already bit the Gold Balances
validator (`vaultBalances()` returned 0 in the CLI) and was fixed by wrapping in
`TenantContext::runFor`. The assumption "a scoped Eloquent query always knows its
shop" is false off the request path. **Recommendation (later):** services that may
run off-HTTP should take an explicit `shopId` and use `withoutTenant()->where(...)`
(the reporting layer's established pattern) rather than relying on the global scope.

---

## Cross-layer agreement scorecard

| Layer pair | Agreement |
|------------|-----------|
| Web routes ↔ RBAC permissions | **Consistent** (all `can:` over one gate) |
| Mobile routes ↔ RBAC permissions | **Consistent** (same `can:` gate; all writes gated) |
| Mobile capabilities ↔ mobile endpoints | **Drift** (§6) |
| Mobile capabilities ↔ route enforcement (owners) | **Drift** (§2) |
| Permission names ↔ naming convention | **Drift** (§3) |
| Domain naming ↔ UI/capability naming | **Drift** (§4 vendor/supplier) |
| Service names ↔ responsibility | **Collision** (§1 SalesService) |
| Web ↔ mobile entity contracts | **Drift** (§5 customer) |
| Policies ↔ route gates | **Aligned today, dual mechanism** (§8) |
| Eloquent scope ↔ non-HTTP execution | **Assumption** (§10) |

---

## Verdict

The **enforcement layer is sound** — web and mobile share one RBAC gate, every
mobile write is permission-gated, and policies defer to the same permission
strings. There is **no security bypass**. The drift is in **semantics and
contracts**: a duplicate `SalesService` name (HIGH), a two-way owner-bypass that
assumes a permission-complete owner role (HIGH), three permission-naming
conventions, vendor/supplier naming, surface/contract drift between the two mobile
APIs and between mobile and web entity creation, and capability-vs-endpoint
promises.

**These are consistency and maintainability risks, not pilot blockers.** They are
the right backlog for a "semantic convergence" pass that should accompany — and
inform — the mobile parity work (especially §2, §3, §6, §7, which directly touch
the mobile contract). Recommend resolving §1 (rename) and §2 (single owner-bypass
source of truth) first, as they carry the highest divergence risk.

*No code changed. Diagnosis only.*
