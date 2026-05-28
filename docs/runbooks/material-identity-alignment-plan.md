# Material Identity Alignment Plan

> **Status:** Approved for execution. **Date:** 2026-05-28.
> **Authority:** `material-identity-audit.md` (the four identity systems).
> **Nature:** Formalization of operational truth already discovered — NOT a redesign. The accounting/material foundation already survives.
> **Executors:** Claude (architecture-critical) + MinMax M2.7 (bounded tasks only). The split is defined in §9 and tagged on every task.

This plan makes material identity semantics **explicit, safe, and impossible to silently reinterpret**, without destabilizing pilot operations (gold/silver shops).

---

## 0. Goal & non-goals

**Goal:** four identity systems (A purity-as-accounting, B purity-as-specification, C attribute/value, D manual/grade) become explicit capabilities; forms, pricing, reconciliation, and reporting derive behavior from those capabilities instead of scattered `=== 'gold'` assumptions; platinum purity can never enter gold-style accounting; stones never gain a purity field.

**Non-goals (forbidden — anti-ERP, §8):** no schema redesign, no column split for pilot, no generic material/attribute engine, no gemological grading, no alloy decomposition, no universal purity abstraction, no new constitutional articles, no trigger changes.

**Pilot invariant:** a gold/silver shop must see **zero behavior change** from this entire plan. Every phase is verified against that.

---

## 1. Phase-based rollout

| Phase | Title | Executor | Depends on | Pilot-visible? |
|---|---|---|---|---|
| **P1** | Identity-class capability formalization | **Claude** | — | No |
| **P2** | `items.purity` semantic boundary (fine-weight guard) | **Claude** | P1 | No |
| **P3** | Material-aware item-creation UX | **MinMax** (bounded) | P1, P2 | Yes (non-gold/silver only) |
| **P4** | Platinum specification hardening | **Claude** boundary + **MinMax** labels | P1, P2, P3 | Yes (platinum only) |
| **P5** | Stone identity containment | **MinMax** (bounded) | P1 | No (pilot unchanged) |
| **P6** | Documentation & journaling | **MinMax** writes, **Claude** reviews | all | No |

**Rollout order is strict.** P1 → P2 must land before any UX phase, because P3/P4/P5 consume P1 capabilities and rely on the P2 guard. One phase = one PR = one journal entry (continue `docs/journals/material-ux-alignment-journal.md`, batch IDs `ID-2026-..-NN`). Minimum 24h between phases for regression surfacing.

**Invariant checkpoint after every phase** (the existing gate):
- `php artisan test tests/Feature/Material/`
- `php artisan test tests/Feature/ConstitutionalInvariantsTest.php` (must not regress beyond the known carried-forward `materials audit command runs clean`)
- `php artisan returns:validate` (all pass)
- `php artisan vault:reconcile`, `karigar:reconcile`, `rates:reconcile-shadow-write` (exit 0)
- `php artisan materials:audit` (same 3 carried-forward violations, **no new ones**)
- Manual smoke on a gold/silver-only shop: **no change**.

---

## 2. The four identity systems — behavioral contract

This table is the contract every later phase implements. **Do not collapse these back into one abstraction.**

| Dimension | A: gold, silver | B: platinum | C: stones | D: copper |
|---|---|---|---|---|
| Identity field | purity (multiplier) | purity (spec) | ₹ value (+attrs adv.) | piece price (+type opt.) |
| Purity is accounting truth | **yes** | no | n/a | no |
| Purity is specification | no | **yes** | n/a | no |
| Fine-weight derived from purity | **yes** | **never** | never | never |
| Reconciliation-relevant (gram) | **yes** | no | no | no |
| Exchange-relevant (gram) | **yes** | no (rare/manual) | no | no |
| Hallmark-relevant | yes | **yes** | no (cert instead) | no |
| Daily-rate-driven price | **yes** | no (piece) | no | no (piece) |
| Purity selector in form | **mandatory** | lightweight/optional (spec) | **none, ever** | hidden/optional type |
| Stone attribute identity | no | no | **yes** | no |

Implications carried into later phases:
- **Capability** (§3): each row above becomes a derivable flag.
- **Reconciliation:** unchanged — only A participates (already true; P1 formalizes the gate).
- **Reporting:** A shows per-purity fine weight; B/D show per-piece value; C shows stone value. (Stage 4 already split primary/secondary; no further work required for pilot.)
- **Pricing:** A = rate×weight×purity; B/D = direct price (Stage 2 done); C = stone amount (done).
- **UX:** §5.

---

## 3. P1 — Capability Formalization  **[CLAUDE ONLY]**

### Why Claude-only
This defines the capability architecture every other phase derives from. Getting the source-of-truth shape wrong propagates everywhere. Not a bounded task.

### What to build
A single **source-of-truth** identity classifier on `MetalRegistry`, with thin derived capability accessors. No DB, no tier changes, no edits to existing constitutional capability methods (add alongside).

Proposed surface (final names decided at implementation):
- `MetalRegistry::identityClass(string $metal): string` → `'purity_accounting' | 'purity_spec' | 'attribute_value' | 'manual_grade'`. Throws for unsupported.
  - gold, silver → `purity_accounting`
  - platinum → `purity_spec`
  - copper → `manual_grade`
  - (stones are not in the metal set; their class is `attribute_value` and is asserted at the stone layer, not the metal registry — see §6.)
- Derived boolean accessors (each a one-line read of `identityClass`):
  - `purityIsAccountingTruth($metal)` — true only for class A
  - `purityIsSpecification($metal)` — true only for class B
  - `hallmarkRelevant($metal)` — true for A and B
  - `puritySelectorMode($metal)` → `'mandatory' | 'lightweight' | 'hidden'`
- Reuse existing flags for reconciliation/exchange/rate (`isReconciliationEligible`, `isLiveRateEligible`, `uxGramReconciliationDefault`, `uxItemCreationDefault`). P1 adds an **assertion test** that these existing flags AGREE with `identityClass` (e.g., `isReconciliationEligible` is true iff class A). If they disagree, that's a bug to resolve before proceeding.

### Backward compatibility
Purely additive methods. No caller changes in P1. Existing behavior identical.

### Validation
New test `tests/Feature/Material/MaterialIdentityClassTest.php`:
1. `identityClass` returns the correct class for gold/silver/platinum/copper; throws for unknown/empty.
2. Each derived accessor matches the contract table in §2.
3. **Consistency:** `purityIsAccountingTruth($m) === isReconciliationEligible($m)` for all supported metals; `uxItemCreationDefault($m) === 'rate_derived'` iff class A; `'piece_price'` iff class B/D.

### Checkpoint
Full invariant gate (§1). No pilot-visible change.

---

## 4. P2 — `items.purity` Semantic Boundary  **[CLAUDE ONLY]**

### The problem (audit §pl8/pl9)
`items.purity` is one numeric column carrying different meanings: gold's `22` (karat multiplier), platinum's `95` (hallmark %), null for copper/stones. The danger: future code assuming "all metals reconcile by purity" silently turns platinum's `95` into a fine-weight multiplier → corrupt accounting + historical reinterpretation (constitutional violation).

### Strategy — guard, don't redesign (pilot-safe, no migration)
**Do NOT split the column.** Instead make it **structurally impossible** for a non-accounting metal's purity to become a fine-weight multiplier, by funneling ALL fine-weight derivation through one authoritative function.

1. **Single fine-weight authority.** Introduce/define one method (e.g. `MetalRegistry::fineWeightMultiplier(string $metal, float $purity): ?float`) that returns the multiplier **only** when `purityIsAccountingTruth($metal)` is true, and returns `null` (or throws, decided at impl) otherwise. Any code computing fine weight from purity MUST call this — never inline `purity/24`.
2. **Sweep + reroute (Claude).** Find every place fine weight is derived from purity (`costPriceFromResolvedRate`, lot creation fine-weight calc, vault, karigar issue/receive, reconciliation commands). Confirm each is only reachable for class-A metals; route the derivation through the authority. This is architecture-critical (touches accounting paths) → Claude only.
3. **Guard test.** Assert `fineWeightMultiplier('platinum', 95)` / `'copper'` / unknown returns null (or throws) and that NO fine-weight is ever produced for a non-A metal. Add a test that constructs a platinum item and asserts its accounting never produces a purity-derived fine weight.
4. **Labeling (display meaning).** Define a per-class purity **label/scale** so the stored number is never ambiguous in UI: gold→"Karat (K)", silver→"Fineness (e.g. 925)", platinum→"Hallmark grade (Pt950)", copper/stones→none. The label function is a capability lookup (P1). *Applying* the label in views is MinMax-safe (P3); *defining* the semantic mapping is Claude (here).

### Future-proofing (explicitly out of pilot scope)
If real demand ever requires platinum's grade stored separately from gold's multiplier, the escape hatch is a **future additive migration** adding e.g. `items.hallmark_grade` — never a destructive rewrite, never in pilot. Documented as the sanctioned path so no one "fixes" the shared column under pressure.

### Checkpoint
Full invariant gate. Critically: gold/silver fine-weight math byte-identical; platinum/copper produce no purity-derived fine weight; `vault:reconcile` unchanged.

---

## 5. P3 — Material-Aware Item-Creation UX  **[MINMAX — BOUNDED]**

### Why MinMax-safe
Once P1 capabilities and P2 labels exist, this is conditional rendering + labels + visibility driven by capability lookups. No accounting, no schema. **MinMax must consult `MetalRegistry::puritySelectorMode()` / `purityLabel()` — never hardcode metal names.**

### Bounded tasks
1. Purity selector visibility by `puritySelectorMode($metal)`:
   - `mandatory` (gold/silver) → required selector, current behavior unchanged.
   - `lightweight` (platinum) → optional "Hallmark grade" selector (Pt950/Pt900), labelled as a spec, never blocking, never feeding price.
   - `hidden` (copper) → no purity field (optional free-text "type" allowed, off by default).
2. Purity field **label** from the capability (Karat / Fineness / Hallmark grade) — no inline strings.
3. Stones: ensure **no purity field renders** on any stone entry path (already true; lock with a test).
4. Reuse the Stage-2 `_metal_aware_pricing.blade.php` pattern; extend visibility only.

### Explicitly NOT MinMax (escalate to Claude)
- Any change to how `unit_price`/`cost_price`/fine weight is computed.
- Any validation that changes accounting (e.g., purity ranges that feed calculations).
- Anything requiring a new capability (must already exist from P1).

### Validation
Extend `tests/Feature/Material/ItemCreationMaterialAwareTest.php`:
- gold/silver: purity selector required, behavior unchanged.
- platinum (enabled): "Hallmark grade" optional selector shown, item still piece-priced.
- copper (enabled): no purity field.
- stone-bearing item: no purity field for the stone.

### Checkpoint
Full invariant gate. Gold/silver create form unchanged (screenshot smoke).

---

## 6. P5 — Stone Identity Containment  **[MINMAX — BOUNDED]**

> (Numbered P5 per §1; placed here next to the UX phase for reading flow. Execute in the §1 order.)

### Goal
Stones stay value/attribute-based, never purity-driven; advanced Phase 2B `stone_components` stays unexposed; prevent accidental purity leakage into stones.

### Bounded tasks
1. Confirm + lock: stone entry is `stone_amount` (₹) only on every path (invoice, item, POS). Test asserts no purity field appears for stones.
2. Assert containment: the advanced stone component routes remain unrouted (the Stage 5 test already does this — extend if needed).
3. Add a capability guard test: stones are `attribute_value` class and `purityIsAccountingTruth` is irrelevant/false for them; ensure no code path asks a stone for purity.

### Explicitly NOT MinMax
- Wiring up the advanced `stone_components` UI (that's a future, separately-approved activation — needs the opt-in design from the original UX plan §7 and is Claude-reviewed).

### Checkpoint
Full gate; pilot stone UX unchanged.

---

## 7. P4 — Platinum Specification Hardening  **[CLAUDE boundary + MINMAX labels]**

### Goal (audit §pl12/pl13)
Platinum feels luxury-piece-priced, never gold-lite. Purity is a hallmark spec, provably excluded from vault reconciliation, purity-derived pricing, exchange gram accounting, and gram-balance assumptions.

### Claude (architecture-critical)
- **Exclusion proof.** Using the P2 fine-weight authority, prove (by test) that a platinum item/lot produces no purity-derived fine weight and does not enter `vaultBalances` as a reconciled gram position by purity-multiplier. (Stage 4 already shows platinum under "Other materials"; P4 adds the *accounting* exclusion guarantee, not just display.)
- Confirm exchange/melt paths never gram-value platinum via purity (rare manual handling only).

### MinMax (bounded)
- "Hallmark grade" lightweight selector + label (Pt950/Pt900), optional, in the create form (this overlaps P3 — do it within P3's batch for platinum).

### Validation
- Test: platinum item finalize → `unit_price` is the entered price; no purity-derived fine weight anywhere; not present as a purity-reconciled vault line.

### Checkpoint
Full gate.

---

## 8. Anti-ERP Boundary Plan (hard rules for ALL phases)

Permanent prohibitions — apply to Claude and MinMax equally:
1. **No generic material engine / EAV attribute soup.** Four fixed identity classes only. No "any material, any attribute" table.
2. **No metallurgy / alloy decomposition.** Already constitutionally banned.
3. **No gemological grading engine.** Record a certificate number + value; never compute 4C scores or light performance.
4. **No universal purity abstraction.** `identityClass` is a *discriminator*, not a unifier — it exists to keep the systems apart.
5. **No material-science modeling.** Model the counter conversation, not the periodic table.
6. **No schema redesign in pilot.** Column meaning is enforced by the P2 guard, not by splitting storage. Any future split is additive + separately approved.

If any task seems to require crossing these lines, **stop and escalate to Claude with a journal "Unresolved concern."**

---

## 9. MinMax Execution Boundary  **[GOVERNANCE — READ BEFORE ASSIGNING WORK]**

MinMax must **never become architectural authority.** Task classification:

### A — Claude only (architecture-critical)
- P1 capability architecture (identity-class source of truth + derived flags).
- P2 fine-weight authority + accounting-path sweep/reroute.
- P4 platinum accounting-exclusion proof.
- Any reconciliation / exchange / pricing / fine-weight semantics.
- Any new capability, any trigger, any schema, any constitutional logic.

### B — MinMax safe (bounded, capability-consuming)
- Form field **visibility conditions** driven by existing capabilities.
- Field **labels** from capability lookups (Karat / Fineness / Hallmark grade).
- **Conditional UI rendering** (show/hide purity selector by `puritySelectorMode`).
- **Capability lookup replacements** — swapping a hardcoded `=== 'gold'` for an existing capability call, where the mapping is mechanical and the capability already exists.
- **Documentation** updates and journal entries.
- **Small validation additions** ONLY with an explicit, written per-field spec that does not affect any calculation.

### Hard rules for MinMax
- Never invent a capability — if one is missing, stop and request Claude add it in a P1-style batch.
- Never hardcode metal names in views/controllers — always go through `MetalRegistry`.
- Never touch files under `app/Services/Returns/*`, `InvoiceAccountingService`, `JobOrderService`, `BullionVaultService` accounting cores, or any migration/trigger.
- Every batch: a journal entry + verification output + `Refs: journal entry ...` in the commit. Run the full invariant gate; "pass" means same carried-forward `materials:audit` violations and **no new ones**.
- If verification can't be honestly reported as passing, mark the batch `in-progress` and stop. (Two prior MinMax batches falsely claimed passing — this is a watch item.)

---

## 10. Persistent Operational Documentation

### Permanent docs (where things live)
| Doc | Purpose | Status |
|---|---|---|
| `docs/runbooks/material-identity-audit.md` | The four identity systems — the WHY | Created (this batch) |
| `docs/runbooks/material-identity-alignment-plan.md` | This plan — the HOW | Created (this batch) |
| `docs/runbooks/material-behavior-audit.md`* | Operational behavior per material | (from earlier audit; create if absent) |
| `docs/runbooks/material-ux-alignment-plan.md` | The shipped 6-stage UX plan | Exists |
| `docs/journals/material-ux-alignment-journal.md` | Append-only implementation log | Exists — continue it |

(* If the behavior audit was only delivered in conversation, save it for survivability.)

### Companion reference for future contributors
Create (MinMax-safe, Claude-reviewed) `docs/runbooks/material-identity.md` — a short, plain-English contributor guide:
- The four classes and which metals belong to each.
- "Why platinum is not gold-lite" and "why stones never have purity."
- The rule: **fine weight comes ONLY from `MetalRegistry::fineWeightMultiplier()`; never inline `purity/24`.**
- Pointer to the audit + this plan.

### Documentation rule (permanent)
Any future change touching material behavior MUST document, in its journal entry: (a) which identity class(es) it affects, (b) any capability added/changed, (c) operator-facing implication in plain English, (d) invariant impact. A material change with no identity-class note is incomplete.

---

## 11. Verification matrix (per phase)

| Phase | Key proof |
|---|---|
| P1 | identity-class tests pass; existing flags agree with class; zero behavior change |
| P2 | `fineWeightMultiplier` null/throws for non-A metals; gold/silver fine weight byte-identical; `vault:reconcile` unchanged |
| P3 | gold/silver form unchanged; platinum shows lightweight hallmark selector; copper no purity; stones no purity |
| P4 | platinum produces no purity-derived fine weight; not a purity-reconciled vault line; piece price preserved |
| P5 | stone entry is ₹-only on all paths; advanced routes still unexposed; no purity for stones |
| P6 | docs present; contributor guide accurate; journal entries complete |

All phases additionally pass the standard gate (§1).

---

## 12. One-paragraph summary

This plan formalizes the four material-identity systems the audit proved real — without redesigning anything. Claude builds the capability source-of-truth (P1) and the fine-weight boundary that makes it structurally impossible for platinum/copper/stone "purity" to enter gold-style accounting (P2). MinMax then aligns forms to operator mental models (P3/P5) using those capabilities, never inventing architecture. Platinum is hardened as luxury-piece-priced with a hallmark-spec selector (P4), stones stay value-based (P5), and everything is documented so the *why* survives (P6). A gold/silver shop sees no change; the system simply becomes incapable of silently reinterpreting what "purity" means.
