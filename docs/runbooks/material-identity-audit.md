# Material Identity Audit

> **Status: APPROVED 2026-05-28.** Operational truth-modeling. The conclusions here are the authority for the Material Identity Alignment Plan (`material-identity-alignment-plan.md`). Companion to the Material Behavior Audit.

## Thesis

"Purity" is not one concept. It is **four different identity systems** wearing the same word. Gold's purity is *accounting truth*; platinum's purity is a *hallmark specification*; copper has *no purity concept*; a diamond's identity is *not purity at all* (it's quality attributes). Forcing one model onto all four is technically tidy and operationally dishonest.

The current architecture already leans correct: `shop_metal_purity_profiles` is gold/silver-only, stones use `stone_amount`, and Stage 2 made platinum/copper piece-priced. The residual risk is the shared `items.purity` numeric column and any future temptation to build "purity profiles for all metals."

## The four identity systems

| Class | Materials | Identity is… | Accounting role |
|---|---|---|---|
| **A — Purity-as-accounting** | gold, silver | Fine-weight multiplier (karat / millesimal) | Drives fine weight → vault, exchange, melt, reconciliation, rate |
| **B — Purity-as-specification** | platinum | Hallmark grade (Pt950/Pt900) | Display/trust only; piece-priced |
| **C — Attribute / value** | diamonds, stones | ₹ value (pilot) + 4Cs/cert (advanced) | Per-piece value; never fine weight |
| **D — Manual / grade** | copper, specialty | Piece price; optional coarse type | None |

## Per-material conclusions

- **Gold / Silver (A):** purity IS accounting truth. `fine = gross × purity/24` (or `/1000`). Vault balance, old-gold exchange, melt, reconciliation, karigar issue/receive, and daily rate selection all break without it. Grades in daily use: gold 24K/995-999, 22K/916 (dominant), 18K/750, 14K/585; silver 999, 925, 900/800. The "916"/"925" stamp is both internal accounting truth and an external trust promise. Keep first-class, reconciliation-relevant, exchange-relevant.

- **Platinum (B):** NOT "gold with different labels." Pt950 dominant (often the only grade in stock). No daily platinum rate — piece-priced from supplier tag + markup. Purity is a hallmark **specification** shown to the customer, not a pricing lever. Exchange rare; volume too low for a real vault balance. Record/show/hallmark the grade — never reconcile or rate-derive from it.

- **Copper (D):** operators NEVER think in copper purity. Pooja/religious articles, Ayurvedic bands, costume/craft — sold per piece. At most an optional free-text type ("pure copper" vs "brass/alloy"), usually blank. No purity profile, ever. Alloy decomposition is anti-ERP and banned.

- **Diamonds / Stones (C):** no purity concept exists. Identity = ₹ value (pilot) optionally decomposing into per-stone carat/clarity/color/cut/certificate/shape/origin (advanced, certified diamonds only). **Carat is weight, not purity** — conflating them is a category error. Moissanite/lab-grown must be *disclosed* but are piece-priced. Ruby/emerald/pearl/kundan/polki are per-piece/set valued. The pilot model is `stone_amount`; Phase 2B `stone_components` is the advanced path.

## Constitutional alignment

Forcing one generic purity system would violate:
- **Explainability** — a platinum price is "the owner set it," not a fabricated rate×weight×Pt950 derivation.
- **Operational honesty** — copper has no purity; a stone "purity %" is nonsense an auditor can't reconcile.
- **Historical truthfulness** — if platinum's stored `95` were ever treated as a fine-weight multiplier by future "all metals reconcile by purity" code, historical platinum records would be silently re-interpreted — the forbidden "historical meaning must never change" violation.

The per-metal separation already in place IS the constitutionally honest design. The task is to *codify* it so it can't be silently universalized.

## Anti-ERP boundary

Do NOT drift into: gemological grading engines, industrial metallurgy / alloy decomposition, infinite material-attribute (EAV) engines, or material-science classification. Model **how a shop owner thinks** ("22K gold, Pt950 platinum, a ₹3,500 stone, a copper kalash"), not the periodic table. Four fixed identity classes — no fifth "unifying" abstraction.

## One-sentence verdict

JewelFlow already models material identity more honestly than a single "purity profile" suggests; the correct next step is to **make the four identity systems explicit capabilities**, never to unify them into one generic purity engine.
