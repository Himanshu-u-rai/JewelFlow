# Shop Environment Classification — Plan

> **Status:** Approved 2026-05-28. **Nature:** additive metadata for operational clarity. **Hard rule:** demo/test shops run the IDENTICAL accounting engine, reconciliation, triggers, immutability, and audit logging as production. `shops.environment` is read for labels/annotations ONLY — it never branches accounting logic.

## Metadata model
Single column `shops.environment VARCHAR(20) NOT NULL DEFAULT 'production'`. One enum, not multiple booleans. Default `production` (fail-safe toward scrutiny).

## Allowed classes (three — `pilot` deliberately excluded)
| Value | Meaning | Data trust |
|---|---|---|
| `production` (default) | Real shop, real books | Real — full scrutiny |
| `demo` | Seeded showcase data (Goldlux / JF-0001) | Not real |
| `internal_test` | Dev/QA scratch | Not real |

`pilot` is NOT an environment value — pilot shops have REAL accounting data; classifying them as a non-production environment would risk treating real corruption as "demo noise." Pilot is a commercial/lifecycle attribute tracked elsewhere if needed.

## MAY affect (display/annotation only)
Admin shop-list badge; reconciliation contextual note; support filtering; onboarding/demo banners; audit/architecture-review clarity. All are READS of the column.

## Must NEVER affect
Accounting rules, reconciliation EXECUTION, trigger enforcement, ImmutableLedger, audit logging, validation integrity, exit codes. Reviewer grep rule: `environment`/`isDemo` must not appear in any `app/Services/` money/weight path.

## Reconciliation behavior — annotate, never hide
Discrepancies computed/reported identically for all environments. Only addition: a context note when `environment !== 'production'`. Exit code unchanged by environment.

**Distinction from acknowledgement (already shipped):** acknowledgement (`--acknowledge`) is explicit, signature-bound human review that suppresses a specific known discrepancy and changes the exit code (self-expires if data changes). Environment annotation suppresses nothing and changes no exit code — it only explains. A demo discrepancy STILL needs an acknowledgement to stop failing the run.

## Historical survivability
Additive column (default production); no existing row changes meaning. Setting shop 4 → demo is a metadata UPDATE on the `shops` row (mutable, not a ledger) — no compensating entries, no append-only mutation, no retroactive reinterpretation.

## Who sets it — platform admins only
Set via platform admin tooling / seed / migration — NEVER shop-owner self-service (a shop must not self-label demo to dodge scrutiny).

## Phases
- **E1 [Claude]** — migration adds `shops.environment` (default production, CHECK in production/demo/internal_test); `Shop` helpers `isProduction()`/`isDemo()`/`isNonProduction()`; set shop 4 (JF-0001) → demo as a one-off metadata update on this deployment (not a global data migration that assumes JF-0001 is demo everywhere).
- **E2 [Claude, display-only]** — `vault:reconcile`/`karigar:reconcile` print a context note for non-production shops. No logic/exit-code change. Tests: note present for demo, absent for production, exit code identical.
- **E3 [bounded UI]** — platform admin shop-list badge; optional `--environment=` support filter; onboarding/demo banner.

Rollout E1 → E2 → E3, one PR + journal entry each.

## Verification checklist
1. Migration additive; existing shops read production; no other table touched.
2. Demo shop accounts identically (same triggers/audit/returns:validate).
3. `vault:reconcile --shop=4` still reports discrepancies + shows demo note; exit code unaffected.
4. Environment alone never suppresses (un-acknowledged demo discrepancy still exits 1).
5. No accounting branch on environment (grep `app/Services/`).
6. Shop 4 ledger rows unchanged.
7. Owner cannot self-set environment.
8. Full gate green (Material, Constitutional, materials:audit, returns:validate).

## Out of scope
No environment inheritance, no per-environment permission branching, no multi-environment engine, no separate demo database. One column, three values, labels only.
