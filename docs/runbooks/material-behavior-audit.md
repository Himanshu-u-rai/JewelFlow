# Material Behavior Audit

> **Status: APPROVED 2026-05-28.** Operational truth-modeling of how metals and stones actually behave in Indian jewelry shop operations. Foundation for the Material Identity Audit and the UX/Identity alignment plans.

## Framing

JewelFlow had been modeling materials as variants of one concept ("a supported metal"). They are not — gold, silver, platinum, copper, and stones are five operationally different businesses sharing a counter. The Tier 1 / Tier 2 grouping is a software grouping, not a shop grouping. This audit grounds material behavior in what owners actually do, want, and ignore.

## 1. Per-material behavior

**Gold — the business the shop is built around.** Daily rate (often twice daily), posted publicly, customer rate-aware. Valuation = rate × fine weight + making + stones + GST. Per-piece, hallmarked (HUID), purities 22K (dominant), 18K, 14K, 24K. **Gram-perfect reconciliation** — the deepest operational truth. Old-gold exchange every day (weigh → test → value at fine weight → melt). Routine repairs.

**Silver — secondary line, behaves like gold with less attention.** Daily rate tracked loosely (often reused). 1/70th the rupee value per piece. More articles (anklets, idols, pooja) than jewelry. Purities 999/925/900. Gram tracking with wider tolerance. Exchange common but often sold to refiner in bulk rather than melted in-house.

**Platinum — niche luxury, not a commodity.** Owner does NOT check platinum rate daily (weekly/monthly/never). Piece-priced from supplier tag + markup. 1–10 display pieces, often zero. Pt950 dominant. Not gram-reconciled in practice. Exchange rare. Specialized repair (many karigars decline; 2–4 week turnaround). Customer rate-unaware ("how much for this ring?").

**Copper — not a mainstream jewelry material.** Never daily-rated; piece-priced. Religious items (kalash, lota), Ayurvedic bands, costume alloys — specialty stores only. No reconciliation, no exchange, no repair workflow. Closer to a kitchenware product than jewelry.

**Diamonds/Stones — different valuation universe.** No daily rate at shop level (Rapaport is wholesale-only). 4Cs + certificate for large stones; bulk-priced melee. Per-piece rupee value. Memo/consignment common. Never gram-reconciled; carat ≠ fine weight. Buyback at heavy discount; stones extracted, gold melted, stones reset — diamonds never melted. Specialized stone-setter repairs.

### Which only LOOK similar
- Gold ↔ Silver: genuinely similar (silver = gold with less attention).
- Gold ↔ Platinum: NOT similar (platinum is a piece-priced luxury product).
- Platinum ↔ Copper (the current Tier 2 grouping): nothing in common operationally.
- Copper ↔ Stones: surprisingly similar — both piece-priced, neither gram-reconciled, neither rate-tracked.

## 2. Daily pricing reality
Live/daily rate: gold ✓, silver ✓ (loose), platinum ✗ (rarely), copper ✗ (never), stones ✗ (never). Building a symmetric multi-material rate dashboard would produce fields owners ignore and that go stale. **Gold and silver are the only daily-rate materials.**

## 3. Exchange & gram-accounting truth
Gram-based reconciliation is for **gold and silver only**. Platinum technically has purity but volume is too low to run a vault balance. Copper never. Stones never (rupee value, not grams). The clean rule: `isReconciliationEligible` defaults false for platinum, not true.

## 4. Repair reality
Gold/silver: routine, any karigar. Platinum: specialized, many decline, long turnaround. Copper: no repair workflow. Stones: separate stone-setter; multi-karigar sequences happen but pilot handles them as single-karigar.

## 5. Item-creation reality (operator mental model)
- Gold/silver (~90% of items): category → weight → purity → making → optional stone amount. Must be ~30 seconds. Rate-derived.
- Platinum: category → weight → **type the price directly** (rate × weight derivation is noise). Piece-priced.
- Copper: category → piece price.
- Stones: a gold/platinum item with a **stone amount (₹)** field. Component breakdown only for high-end certified diamond shops.

Forcing rate × weight × purity on platinum/copper frustrates operators. Item creation must be metal-aware.

## 6. Capability reality (three operational groups)
- **Group A (gold, silver):** commodity-rated, gram-reconciled. Full support.
- **Group B (platinum):** piece-priced luxury. Capability flags default OFF; opt-in.
- **Group C (copper, stones):** per-item, non-commodity. Inventory/value only.

The Tier 1/Tier 2 model approximates this but conflates B and C as one tier. Capability flags should follow the real groups.

## 7. UX / settings reality
Build: daily gold rate, daily silver rate, stone-type config (once), per-metal making defaults. Do NOT build: daily platinum rate, copper rate maintenance, a unified materials dashboard, per-material reconciliation toggles surfaced to operators. Anything an operator won't touch monthly belongs in a setup area, not daily use.

## 8. Pilot reality (minimum viable)
Pilot shops are 90–100% gold/silver. Must work fully: gold/silver (rate, vault, exchange, dhiran, repair, reconciliation, reporting), stones as a rupee value. Limited-support (no errors, quiet UX): platinum piece-priced, stone certificate fields stored-if-entered. Not in pilot: copper in retail flows, diamond live pricing, multi-karigar repair routing, platinum dhiran.

## 9. Final classification
- **Required now:** gold/silver full support; stones as rupee value; platinum piece-priced; copper hidden from item picker.
- **Optional later:** stone component breakdown (Phase 2B), platinum daily rate (opt-in), per-karigar metal capabilities, multi-karigar repair, memo/consignment, certificate management.
- **ERP overengineering (never):** auto platinum rate fetch, alloy decomposition, AI valuation, live diamond feeds, customer price feeds, stone certificate OCR, cross-shop marketplace.

## One-sentence verdict
JewelFlow is a gold-and-silver business with optional platinum and stone support and intentionally limited copper presence — and the daily UI should look like that, not like a five-material symmetric dashboard.
