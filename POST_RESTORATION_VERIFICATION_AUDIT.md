# POST-RESTORATION VERIFICATION AUDIT
*Trust-establishment sweep against the CURRENT remediated system — before pilot.*

**Date:** 2026-06-02
**Subject:** The 17-milestone operational-restoration pass (commits `7435a3d → 85b1972`, findings M1–M17 + the late discoveries A2b & OBS1).
**Posture:** Hostile. Nothing trusted on assertion — not the prior fixes, not green tests, not compile success. Every claim re-traced against current code with `file:line` evidence; headline accounting/security claims independently spot-verified by the lead auditor.
**Method:** 4 parallel hostile-verification sweeps (financial integrity · permission/security · UI-surface/lifecycle · infrastructure/triggers/observers) + direct lead-auditor verification of the highest-risk items + live `returns:validate` / `reports:validate` / `vault:reconcile` / `karigar:reconcile` runs and live `pg_trigger`/`pg_proc` queries.
**Constraint honoured:** verification only. No redesign, no refactor, no feature work. A fix would have been applied only for a genuinely dangerous regression — **none was found**, so no code was changed in this phase.

---

## 1. Verdict

> **The restoration did NOT destabilise the platform. The accounting core holds. No workflow silently regressed. JewelFlow is safe for pilot-shop operations**, subject to a short list of documented LOW/MEDIUM items (none blocking, none affecting default roles or financial correctness).

All 17 restored milestones are **verified working end-to-end** — not merely "implemented" or "tests pass," but traced route → gate → service → DB effect → UI visibility → redirect target, with the reverse/close/recovery paths exercised. The two infra bugs discovered late in restoration (A2b broken trigger, OBS1 dormant observers) are **confirmed correctly handled** in the current state. The hostile sweep surfaced **zero dangerous regressions**, **zero accounting drift**, **zero tenant-isolation/IDOR holes**, and **zero silent no-ops in the remediated flows**. The residual findings are one MEDIUM custom-role UX dead-end and a handful of LOW/cosmetic items, all documented below with recommended (non-urgent) fixes.

---

## 2. Confidence scores

| Dimension | Score | Basis |
|---|---|---|
| **Accounting integrity** | **96 / 100** | All 9 money/ledger flows verified-safe; correct cash signs; no finalized-Invoice or ImmutableLedger mutation; A2b trigger correct; `returns:validate` 12/12, `reports:validate` GST-1..7 + PAY-1..3, `vault:reconcile` all-balanced — all exit 0. −4 for reconcile *coverage* not directly asserting the new reverse-flows (they pass indirectly). |
| **Operational continuity** | **92 / 100** | Every restored surface visible + reachable; all 5 lifecycle walk-throughs land on the correct new status; no unclearable queue; no wrong redirect. −8 for one MEDIUM custom-role Edit dead-end + minor nav edition-gating. |
| **Security / permissions** | **93 / 100** | No IDOR; tenant isolation holds (scope + explicit shop checks); default-role gate matrix correct; mobile has no unguarded parity path; `account.active` revokes sessions live. −7 for the custom-role void/create edge + 'terminated' clobber + legacy-KYC fallback (0 live rows). |
| **Infrastructure (triggers/observers/events/scheduler)** | **94 / 100** | A2b trigger correct + enabled; all constitutional triggers present + `tgenabled='O'`; UserObserver registered + fires (runtime-tested); scheduler wired + fault-isolated; DB guards intact. −6 because OBS1 entity-event observers remain dormant (documented deferral; degrades gracefully). |

**Overall production-readiness: GO for pilot** with the documented items tracked as known, non-blocking.

---

## 3. Verified-safe restorations (M1–M17)

Each verified at the depth the trust phase demanded (route + gate + role-holds + service DB effect + UI visibility + redirect/continuity). All **VERIFIED-SAFE**.

| M | Flow | Money/lifecycle correctness | Gate / role | UI reachable | 
|---|---|---|---|---|
| M1 | Admin deactivate → suspended | writes is_active+employment_status in lockstep; login blocked + per-request `account.active` | admin + super_admin | admin panel |
| M2 | Installment write-off → defaulted | invoice & payments untouched; remaining preserved; no cash entry; active-only; audited | sales.void | installments.show (active-only) |
| M3 | Scheme cancel + refund | refund = ledger balance (bonus excluded); cash-OUT; reversing debit zeroes ledger; lock+writable guards; active-only | sales.void | enrollment-show (active-only) |
| M4 | Scheme date maturity | bonus iff fully paid; idempotent (no double-accrual); under-paid matures w/o bonus | command (scheduled 02:00, per-shop, fault-isolated) | n/a (scheduler) |
| M5 | Karigar payment reversal | compensating negative entry; original immutable untouched; double-reverse + reverse-a-reversal blocked; reopens to unpaid | karigar_invoice.manage | invoice show, per positive row |
| M5/A2b | Finalized-guard trigger | no `OLD.status`; blocks DELETE; freezes totals+identity once paid but allows payment_status/amount_paid (reversal works) | constitutional trigger, enabled | n/a |
| M6 | Vault adjustment | signed delta moves remaining; `vault_adjustment` movement w/ correct direction; reason required; non-negative; reconcile stays clean | vault.manage | vault lot page |
| M7 | Stock-purchase reversal | confirmed→draft; stocked→draft only if items still in_stock & no bullion vaulted; deletes in-stock items + nulls lines; blocks otherwise | inventory.edit | purchase show (confirmed/stocked) |
| M8 | Invoice finalize vs void | route can:sales.create; cancel branch abort_unless sales.void (verified L124); only HTTP caller; mobile void gated | sales.create / sales.void | edit page (see MEDIUM-1) |
| M9 | Bulk import re-gate | all 9 routes can:imports.manage; export keeps reports.export; imports.access used nowhere | imports.manage (owner+manager) | imports nav (now gated) |
| M10 | KYC privacy | private 'local' disk; authed shop-scoped stream (IDOR guard L76); destroy deletes file; 0 legacy public rows | customers.view / customers.edit | customer KYC UI |
| M11 | Rework retired | "Send to Karigar" removed; sent_to_rework dropped from both Rule::in; replacement guidance present; legacy items shown non-clickable | n/a | control-center (no dead-end) |
| M12 | Store-credit apply | debits ledger + records wallet InvoicePayment; capped at min(outstanding, available); finalized+customer guards | sales.create | invoice show (conditional) |
| M13 | Hidden controllers | deferred + documented (owner decision); remain unrouted | n/a | n/a |
| M14 | Delivered repairs | index respects ?status; default hides delivered; KPI links to filter; View-Invoice works | repairs.view | repairs KPI link |
| M15 | UserObserver registered | fires at runtime (tested: is_active reverts to match employment_status); never throws; no save path broken | n/a | n/a |
| M16 | Turbo frames | all 8 settings/logout/staff forms carry data-turbo-frame="_top" inside the real frame | n/a | settings tabs |
| M17 | Invoice badge + P3 | badge colours by real status; DC3 confirmed NOT a live crash (write-off sets 'returned') | n/a | invoice show |

**Independent lead-auditor spot-checks (corroborated the agents):** M8 `abort_unless(...sales.void)` at `InvoiceController.php:124`; KYC IDOR guard at `KycDocumentController.php:76`; M8 edit-button condition at `invoices/show.blade.php:23`; `reports:validate` PAY-2 sums the mode breakdown (incl. wallet/scheme) and passes.

---

## 4. Regressions found

**Dangerous regressions: NONE.** No accounting drift, no broken reverse-flow, no tenant breach, no broken constitutional trigger, no silent no-op in any remediated flow, no unclearable queue, no nav link to a dead route.

The remediation touched `routes/console.php` only once across all 17 milestones (the maturity schedule line) — no other scheduled command was altered or removed.

---

## 5. Risks (documented; none blocking)

### MEDIUM
- **MEDIUM-1 — Invoice "Edit" 403 dead-end for a non-default custom role (self-introduced in M8).**
  `invoices/show.blade.php:23` shows the Edit button on a *finalized* invoice when the user `can('sales.void')`, but the `invoices.edit`/`invoices.update` routes are gated `can:sales.create` (`routes/web.php:494,497`). A custom role with **`sales.void` but NOT `sales.create`** would see the button and hit a 403.
  **Not reachable with default roles** (owner & manager hold both; staff has create-not-void, so the button is correctly hidden on finalized invoices). Verified real by the lead auditor.
  **Recommended fix (post-pilot, not applied here per the no-refactor constraint):** make `invoices.edit`/`invoices.update` reachable by `sales.create` OR `sales.void` (the page legitimately serves finalize *and* reversal), e.g. a small either-permission gate; the per-action `abort_unless` in `update()` already enforces the correct ability per branch.

### LOW
- **LOW-1 — M1 activate clobbers a shop's HR `terminated` status.** A platform super-admin "activate" transitions a shop-terminated user straight to `active` (audited). Asymmetric with deactivate (which preserves `terminated`). Recommend requiring a reason when transitioning out of `terminated`. Audited, super-admin-only.
- **LOW-2 — Legacy KYC public-disk fallback.** `KycDocument::url()`/`show` still serve `file_disk='public'` rows directly. **0 such rows exist** on this instance — theoretical only; a one-off migration of any future legacy rows would close it.
- **LOW-3 — Nav links edition-gated, not permission-gated (pre-existing, not a restoration regression).** Job Work / Schemes / Repairs / Stock Purchases nav entries are wrapped only in `@if($hasRetailer/Manufacturer)`; their routes ARE permission-gated, so a custom role with a view-permission revoked would see the link and 403. Inconsistent with the `@can`-gated Returns/Installments/Imports links. Cosmetic for default roles.
- **LOW-4 — Reconciliation coverage gap.** `returns:validate`/`reports:validate`/`vault:reconcile` pass and the new flows reconcile *indirectly*, but none of the validators *directly* asserts the new reverse/refund/adjust flows (e.g. "every scheme cancellation has a matching cash-out + zeroed ledger"). Recommend adding targeted checks post-pilot. Not a correctness gap today.

### COSMETIC
- **COS-1 — Store-credit apply dead-ends silently when applicable balance is 0** (`StoreCreditController.php:108-112`) — but M12's UI only renders the form when applicable > 0, so an operator never hits it.

---

## 6. Deferred risks (explicit, carried forward — not regressions)

- **OBS1 — entity-event observers (Item/JobOrder/ReturnOrder/Invoice) remain unregistered**, so the EntityEvent activity-timeline feeds do not populate. Confirmed to **degrade gracefully** (`entity-timeline.blade.php` uses `@if($feed->isNotEmpty())` → empty, never broken). Phase-F feed work; registering 4 event-emitting observers is a behaviour change needing its own validation.
- **H1/H2/H3 — hidden controllers (ItemStone editor, GstCategory CRUD, MobileDeviceSession web-revocation)** remain unrouted by owner decision (building their UIs = feature expansion). Replacement paths documented in the restoration audit §3.
- **ARC1/ARC2** item/customer hard-delete accepted (deletion only permitted for history-less records). **POL1** uninvoked policies accepted (route `can:` + `authorizeShop()` are the real gates).
- **Rework job-work** retired (never built); replacement is melt→vault→karigar-job.

---

## 7. Integrity confirmations (the trust requirements, explicitly cleared)

- **Accounting core survived.** No new flow writes a finalized Invoice or any ImmutableLedger row via `forceFill`/`update`; reverses/refunds are compensating appends; cash signs correct (refund/reversal = `out`, payment = `in`); GST/credit-note totals untouched by the new reverse paths. Validators green.
- **A2b trigger** (constitutional #15) live, enabled, correct: blocks DELETE; once `payment_status != 'unpaid'` freezes monetary totals + identity while allowing the payment lifecycle — so both `recordPayment` and `reversePayment` work and a wrongful content edit is rejected.
- **All constitutional triggers present + `tgenabled='O'`** (none dropped/disabled/renamed by any restoration migration); non-negative lot balance enforced by CHECK constraints (stronger than a trigger); append-only guards (metal_movements, karigar_payments, audit_logs, store_credit) intact.
- **UserObserver registered and firing** (runtime-verified), enforcing is_active ↔ employment_status; never throws; no legitimate save path broken.
- **Tenant isolation / IDOR**: every new route is BelongsToShop-scoped AND carries an explicit shop check; KYC stream blocks cross-shop fetch; no mobile/API endpoint reaches the new reverse/refund/adjust services without the same guards.
- **Scheduler**: `schemes:process-maturity` registered + scheduled (02:00, `withoutOverlapping`), per-shop via `TenantContext`, fault-isolated per shop.

---

## 8. Production-readiness verdict

**GO for pilot-shop operations.**

- The restoration is **trustworthy**: every milestone independently re-verified working, not assumed.
- The **accounting core is intact** — the single most important trust requirement — confirmed by flow-level tracing + all three reconciliation commands + trigger/guard verification.
- **No dangerous regression, no silent breakage, no permission leak, no tenant breach** was introduced.
- The residual items are **one MEDIUM custom-role UX dead-end** (default roles unaffected) and **LOW/cosmetic polish** — all documented with recommended fixes, none blocking a pilot.

**Recommended (non-blocking) before/early in pilot:** address MEDIUM-1 (either-permission gate on invoice edit) and LOW-1 (reason on un-terminate); schedule OBS1 + reconciliation-coverage (LOW-4) as post-pilot hardening.

*— Verification phase complete. No code changed; this document maps the verified trust state of the remediated system.*
