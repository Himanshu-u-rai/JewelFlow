# Phase 2B — Advanced Stone Infrastructure — Governance Doc

> **Status: ACTIVATED 2026-08-01** by founder direction. Implementation shipped: certificate/grade/supplier/photo columns on `stone_components`, `stone_revaluation_events` append-only ledger (Constitutional trigger #23), `StoneRevaluationService` (Article XIV-bound), `ItemStoneController::revalue` + `revaluations` endpoints, snapshot guard extended to lock Phase 2B fields on snapshotted rows.
>
> Custody chain (stone-tracking-by-karigar) and supplier-lineage tables remain deferred — they were not part of the activation scope. They may be added incrementally under this same governance doc when a real shop requests them.

This document is **not** an implementation plan. It is the governance specification that gates Phase 2B work. Phase 2A (structured stone separation) is the foundation; Phase 2B is the optional advanced layer that should never be built speculatively.

## Activation criteria (ALL must be met)

1. **Documented shop demand:** a specific pilot shop has provided a written workflow description showing they need certificate-tracked, custody-chained, or grade-attributed stones.
2. **Volume commitment:** the shop's transaction volume includes at least **50 certified-stone sales per month**, sustained for 3+ months.
3. **Beta commitment:** the shop has agreed to use the features for at least **90 days** as a pilot.
4. **Manual-valuation fit:** none of the requested features create live-rate-driven stone valuation. All proposed additions must respect Article XIV (Commodity vs Manual Valuation Boundary).
5. **Constitutional review:** a founder-signed review confirms the additions do not weaken append-only doctrine, snapshot doctrine, or deterministic reconstruction.

If any one criterion is unmet, the proposal is rejected. No exceptions.

## Modular boundary

Phase 2B adds **augmentations** to `stone_components`, never structural rewrites. Specifically:

| Phase 2B addition | Allowed to affect accounting totals? | Constitutional rationale |
|---|---|---|
| `certificate_id` (e.g. GIA-12345) | NO | Identity metadata. Cannot affect `line_total` / `total_value`. |
| `certificate_authority` (GIA, IGI, shop) | NO | Same. |
| `grade` (VVS1, SI2, etc.) | NO | Descriptive metadata. May NOT participate in any pricing formula. |
| `custody_chain` (which karigar holds the stone) | NO | Operational tracking; affects `RepairOrder` / `JobOrder` accountability but not financial totals. |
| `stone_revaluation_events` ledger | YES — but ISOLATED | Each revaluation produces a NEW row in this dedicated ledger; the linked `stone_components.unit_value` updates only while item is in stock; after sale, all values lock at trigger level. Must be append-only with its own constitutional trigger (would become trigger #23+). |
| Photo / scan attachments | NO | Pure operator reference data. |
| Supplier-stone lineage | NO | Audit trail; no financial effect. |

## What Phase 2B must NEVER do

Permanently forbidden, regardless of business pressure:

- Build a diamond market price feed (RapNet, GIA price ticks)
- Use AI to suggest stone values
- Auto-revalue stones based on any external data source
- Create cross-shop stone marketplaces
- Add certificate validation against external authorities (GIA API)
- Any feature that lets an automated process write to `stone_components`

## Isolation enforcement

Every Phase 2B PR must demonstrate:

1. **No new column on `stone_components` participates in `SUM` aggregations for invoice totals.** Grades, certificates, photos cannot affect `line_total`.
2. **`stone_revaluation_events` is append-only** with a constitutional trigger registered in CONSTITUTION.md Article IX.A.
3. **Custody tracking creates a parallel ledger to `MetalMovement`** — it does not modify `MetalMovement` schema. Stone custody movements have their own `stone_movements` table if needed.
4. **Certificate fields are nullable everywhere** so a Tier 1/Tier 2 stone (no certificate) continues to function.
5. **Backward compatibility:** every Phase 2A `stone_components` row continues to satisfy its snapshot guard and compute correct refund amounts.

## Removability test

> If we delete every column and table introduced in Phase 2B, do existing Phase 2A `stone_components` rows still satisfy their snapshot guard trigger and continue to compute correct refund amounts?

If yes — the addition is constitutionally safe.
If no — the addition has leaked into core accounting and must be redesigned before merge.

## Activation procedure (when criteria are met)

1. Founder signs off on the constitutional review document
2. A detailed implementation plan is drafted, following the per-phase plan structure (migrations in strict order, backfill safety, trigger pre-validation, etc.)
3. The plan is reviewed against this governance doc
4. If approved, Phase 2B work begins as its own scoped sprint
5. The first PR adds the new constitutional trigger to Article IX.A registry

## Until activation

- No code work is permitted on Phase 2B concepts
- No table named `stone_certificates`, `stone_revaluation_events`, `stone_movements`, etc. may exist in `database/migrations/`
- No reference to certificate / grade / custody in `StoneComponent` model
- This document is the boundary; crossing it requires founder sign-off

**The default position is: Phase 2B does not exist as work.**
