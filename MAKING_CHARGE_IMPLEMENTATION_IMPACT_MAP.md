# Making Charges — Implementation Touchpoint & Downstream Impact Map
*Date: 2026-06-03 — Status: DESIGN ONLY. Pre-implementation audit. No code changes.*

> Companion to **MAKING_CHARGE_SEMANTICS_AND_ROLLOUT_PLAN.md** (semantic model — approved).
> That document defines *what* the model is. **This** document is the file-level *where it
> touches the codebase*, *what must change*, *what must NOT*, and *in what order* — the map you
> implement from. Everything below was traced in the actual code, not assumed.

---

## 0. Touchpoint classification key

Every touchpoint is tagged with the role it plays so the blast radius is unambiguous:

| Tag | Meaning | Change required |
|---|---|---|
| 🟥 **RESOLVE** | computes the making ₹ from mode+value | **must change** — new mode logic, server-side only |
| 🟧 **SIGN** | part of the HMAC-signed canonical quote | **change carefully** — append-only, golden-tested |
| 🟨 **PERSIST** | writes making onto a row | **must change** — snapshot type+value alongside resolved ₹ |
| 🟦 **INPUT/VALIDATE** | accepts making from operator/API | **additive** — new optional `making_charge_type`/`value` |
| 🟩 **READ-ONLY** | consumes resolved ₹ | **no change** (works because we keep persisting ₹) |
| ⬜ **UI** | display/preview/print | **additive** — show mode + "why this amount" |
| ⚫ **OUT-OF-SCOPE** | cost-side / settlement making, not customer-sale making truth | **no change this initiative** |

**The golden rule that bounds the whole effort:** 🟩 READ-ONLY consumers stay untouched
*because* 🟥/🟨 keep producing the same resolved `making_charges` ₹ they always have. If any
READ-ONLY surface is forced to change, the design has leaked — stop and re-check.

---

## 1. Full Pricing Touchpoint Map (file-level)

### 1.1 Canonical engine & quote contract (the heart)

| File | Lines / symbol | Tag | What it does today | Required change |
|---|---|---|---|---|
| `app/Services/PricingEngine.php` | `computeManufacturer()` ~L295 | 🟥 SIGN | `lineTotal = metalValue + making + stone`; `making = $input->making` (flat) | resolve `makingType/makingValue` → ₹ before `lineTotal`; emit resolved ₹ in `making` (unchanged key) + new appended canonical fields |
| `app/Services/PricingEngine.php` | `computeRetailer()` ~L240 | 🟥 | echoes `item.making_charges` into line meta; `lineTotal = selling_price` | retailer making is resolved at **registration** (§1.4) — engine still just echoes the item's already-resolved ₹; **no sale-time rate introduced** |
| `app/Services/PricingEngine.php` | `computeForExchange()` ~L165 | 🟥 | delegates to manufacturer path | inherits manufacturer resolution automatically |
| `app/Services/PricingEngine.php` | `computeRepair()` ~L120 | 🟩 | no item, no making | none |
| `app/Services/PricingEngine/QuoteInput.php` | `making: float` L30; factories `manufacturer()` L86, `fromArray()` L150 | 🟧 SIGN | flat ₹ making input | **add** `makingType='fixed'`, `makingValue` (additive params + `toArray`/`fromArray` keys); keep `making` as fixed-mode alias |
| `app/Services/PricingEngine/PricingBreakdown.php` | `toCanonicalArray()` lines builder ~L60 | 🟧 SIGN | canonical line `{item_id,line_total,gst_amount,weight,rate,making,stone}` — **frozen order** | **append** `making_type`,`making_value` **after** `stone` in the line object; never reorder; `money()`/string formatting for value |
| `app/Services/PricingEngine/PricingBreakdown.php` | `toApiArray()` ~L120 | 🟦 | display array (numbers) | add `making_type`/`making_value` (safe — not signed) |
| `app/Services/PricingEngine.php` | `sign()/verify()/quote()/recompute()` L180–230 | 🟧 SIGN | HMAC over canonical JSON; `recompute()` replays input for drift | see §5 — feature-flag the canonical extension; golden byte-test fixed mode |
| `app/Models/PosQuote` + table | cols `input_payload, breakdown_json, breakdown_hash, signature` | 🟧 SIGN | stored signed quote | historical rows verify against stored bytes — never re-serialise |

### 1.2 Persistence write-paths (snapshot the triple)

| File | Lines | Tag | Today | Change |
|---|---|---|---|---|
| `app/Services/SalesService.php` | invoice_items write ~L130–132 (`making_charges => $making`) | 🟨 PERSIST | writes flat making | also write `making_charge_type`,`making_charge_value`; resolved ₹ unchanged |
| `app/Services/RetailerSalesService.php` | ~L128–131 and ~L426–429 (`making_charges => item.making_charges`) | 🟨 PERSIST | snapshots item making | snapshot item's `making_charge_type/value` too |
| `app/Services/QuickBillService.php` | `persist()` line build ~L277–305 (`making_charge`, `wastage_percent`) | 🟨 PERSIST | flat making + **already %-based wastage** | resolve making mode → ₹; persist type+value on `quick_bill_items` |

### 1.3 Item registration (where retailer making is resolved)

| File | Lines | Tag | Today | Change |
|---|---|---|---|---|
| `app/Services/ShopPricingService.php` | createItem/update ~L456–511 (`making_charges`, `overhead_cost`, `selling_price`) | 🟥🟨 | flat making → overhead/selling_price | resolve %/per-gram **at registration** (rate known) → bake resolved ₹ into `selling_price` & `making_charges`; persist type+value on `items` |
| `app/Services/ItemManufacturingService.php` | ~L37–100 (`making_charges`, `total_cost`) | 🟨 | flat making, cost basis | persist type+value; resolved ₹ feeds `total_cost` unchanged |
| `app/Services/BulkImportService.php` | headers L51–53; writes L386/450/574/639/669 (`making_charge`→`making_charges`, `default_making`) | 🟦🟨 | flat making columns | optional new `making_charge_type` import column (default fixed); resolve on import |

### 1.4 Retailer constraint (the A4 design fact)

Retailer **sale** has no gold rate (`computeRetailer` uses `selling_price`). Therefore
%/per-gram making for retail items **must resolve at registration** (`ShopPricingService`) and
bake into `selling_price`. The sale path stays rate-free and unchanged. **Do not** introduce a
sale-time rate into retailer mode.

---

## 2. Hidden-Assumption Sweep (operational, not grep-only)

| # | File:symbol | Assumption | Resolution |
|---|---|---|---|
| H1 | `PricingBreakdown::toCanonicalArray` | line key set is frozen; `making` is a 2-dp string | append-only new keys; golden byte-test |
| H2 | `PricingEngine::recompute` | replaying input reproduces stored canonical bytes | flag canonical extension; short-TTL quotes re-quote (§5) |
| H3 | `QuoteInput::manufacturer/fromArray` | `making` is a resolved ₹ float | add type+value; `making` remains fixed-mode alias |
| H4 | `RetailerSalesService` | `line_total = selling_price`, no rate at sale | retail resolves at registration (§1.4) |
| H5 | `ProfitReportingService` `SUM(making_charges)` | column is realised making revenue (₹) | keep persisting ₹; **never** store %/rate in this column |
| H6 | `RefundPolicyResolver`/`GoldValuationService` deduct `$line->making_charges` | refund math on resolved ₹ | unchanged — reads resolved ₹ |
| H7 | `InvoiceItemsSheet` headings `['…,'Making','Stone','Total']`; `select(...making_charges...)` | export = resolved ₹ | unchanged; optional additive `Making Type` column |
| H8 | `Api/Mobile/InvoiceController` returns `making_charges` (float) | mobile reads resolved ₹ | unchanged; optional additive `making_charge_type` |
| H9 | `StoreItemRequest` `making_charges nullable numeric` | making is a number | add optional `making_charge_type` in {fixed,percentage,per_gram} + value validation |
| H10 | web/mobile/API validators (`PosController`, `QuickBillController`, `Api\PosController`) accept `making`/`making_charge` flat | flat ₹ input | additive optional `making_charge_type/value`; keep flat alias = fixed |
| H11 | `InvoiceRenderSnapshotService::buildLineSnapshot` `schema_version:1`, **no making, not hashed** | print snapshot is physical-attr only | if "why this amount" needed on reprint → bump to `schema_version:2` additively (safe, unsigned) |
| H12 | `invoice_items.making_charges NOT NULL` | always present | resolution always yields ₹ (default 0) |
| H13 | `Item`/`QuickBillItem` `casts: making* decimal:2` | making is decimal money | new `making_charge_value` needs its own cast (decimal:2 for ₹/₹g, decimal:2 for %); **do not** reuse the money cast blindly for % |

---

## 3. Mobile Parity Audit

| Surface | File | Status |
|---|---|---|
| Mobile POS preview & sale | `app/Http/Controllers/Api/Mobile/PosController.php` L183–196 (`PricingEngine::compute`) | **parity by reuse** — resolves server-side automatically; add `making_charge_type/value` to request payload (optional) |
| Mobile repair | `Api/Mobile/RepairController.php` L215 (`PricingEngine`) | unaffected (no making) |
| Mobile Quick Bill | `Api/Mobile/QuickBillController.php` L190 (validate `items.*.making_charge`), L281 (return) | additive optional `making_charge_type`; resolved ₹ in `making_charge` unchanged |
| Mobile invoice read | `Api/Mobile/InvoiceController.php` L85 select, L121 return `making_charges` | unchanged; optional additive `making_charge_type` for "12% making" label |
| Signed quote compat | mobile consumes `/pos/quote` `lines[].making` | unchanged key + new appended keys; old apps ignore unknown keys |
| Offline | — | **must not** self-resolve %/per-gram (no client-side financial authority); show estimate, server authoritative on sync — document in mobile contract |
| Cached payloads | old cached invoices | read `making_charges` ₹ — still valid; `making_charge_type` absent ⇒ render as fixed |

**Guarantee:** because mobile and web both call the one `PricingEngine`, resolving modes
server-side keeps web/mobile **bit-identical** with zero duplicated math.

---

## 4. Reporting / Export Impact Audit

| Consumer | File | Reads | Verdict |
|---|---|---|---|
| P&L | `app/Reporting/ProfitReportingService.php` L70–95 (`SUM(making_charges)`, `SUM(stone_amount)`) | resolved ₹ | 🟩 **no change** |
| P&L view | `resources/views/report_pnl.blade.php` | resolved ₹ | 🟩 no change |
| GST report / GSTR | `GstReportingService`, `TaxService` | line totals incl. making | 🟩 no change (making inside line_total) |
| Invoice-items export | `app/Exports/Sheets/InvoiceItemsSheet.php` L19/L24 | `making_charges` | 🟩 works; ⬜ optional `Making Type` column |
| Generic export | `app/Http/Controllers/ExportController.php` | resolved amounts | 🟩 no change |
| Reporting validators | `reports:validate`, `returns:validate`, `vault:reconcile` | guard identities, resolved ₹ | 🟩 no change |
| Making-mode analytics | (new, optional) | `making_charge_type` | ⬜ post-rollout enhancement only |

**Determination (im8):** reports continue consuming **resolved accounting truth**, never live
pricing semantics. **Nothing in reporting must change before rollout.** Only optional additive
analytics later.

---

## 5. Signed-Quote & Canonicalization Audit (HIGH-RISK)

**Canonical structures:** `PricingBreakdown::toCanonicalArray()` (top-level frozen field list +
per-line frozen object). **HMAC payload builder:** `PricingEngine::sign()` →
`hash_hmac('sha256', toCanonicalJson(), APP_KEY)`. **Verification:** `verify()` hashes the
**stored** `breakdown_json` bytes (never re-serialises). **Replay/drift:** `recompute()` rebuilds
canonical JSON from `input_payload` and compares to stored bytes.

**Append-only evolution map:**
- Top level: if any new top-level field is ever needed, **append after `lines`** — never insert.
- Per line: append `making_type`, then `making_value`, **after** `stone`. Existing keys keep
  position and formatting (`money()` 2-dp strings, `weight()` 3-dp).

**Drift across the deploy boundary (H2):** once the engine emits the new line keys,
`recompute()` of a **pre-deploy** quote (whose stored bytes lack those keys) will mismatch →
false drift. Mitigations, in order of preference:
1. **Feature-flag the canonical extension** (`config('features.making_charge_modes')`). While
   off, canonical output is byte-identical to today (verified by golden test). Flip on at deploy;
   only quotes *issued after* the flip carry new keys, and they verify against their own bytes.
2. Quotes are short-TTL (30/60 min) and the POS **already re-quotes on expiry** — pre-flip
   quotes naturally drain within the hour.
3. Do **not** retro-add keys to historical `pos_quotes` rows.

**Golden-byte test plan (mandatory before MC-2 ships):**
- T1: fixed-mode manufacturer quote → canonical JSON **byte-identical** to pre-change baseline (flag OFF).
- T2: flag ON, fixed mode → still byte-identical except appended keys present with expected values.
- T3: `verify()` of a stored pre-change `breakdown_json` still passes under new code.
- T4: percentage & per-gram quotes → resolved `making`/`line_total`/`gst`/`final_total` match hand-computed paisa.
- T5: `recompute()` of a flag-ON-issued quote reproduces its own stored bytes (no drift).

**No accidental signature drift** is the acceptance bar for the signed-quote section.

---

## 6. Historical Reproducibility Audit

| Artifact | Reproducible because | Guarantee |
|---|---|---|
| Finalized invoice line | `making_charges` (resolved ₹) + `rate` + `weight` persisted on the row; `type`/`value` snapshotted | recomputing from the row's own stored inputs yields the same ₹ **regardless of future rate or rule changes** |
| Signed quote | verified against stored `breakdown_json` bytes; never re-serialised | immune to engine evolution |
| Render snapshot | `InvoiceItemSnapshot` `schema_version`-tagged; additive bumps only | old prints reproduce as captured |
| Returns/CN | `RefundPolicyResolver` reads persisted `making_charges`; `policy_breakdown` JSONB captures `making_retained` at settle | CN reversal reproducible |
| Exports / audit trail | read persisted ₹ | stable |

**Explicit determinism guarantee (im9):** a future gold-rate change or making-rule change
**only affects new quotes/registrations**. Every finalized artifact is self-contained: it never
re-resolves making against live state. ImmutableLedger + accounting-guard trigger remain the
backstop.

---

## 7. Database / Schema Evolution Audit

**Additive, nullable-only migrations (no NOT-NULL, no drops, no required backfill):**

| Table | New columns | Cast | Default/null |
|---|---|---|---|
| `items` | `making_charge_type` (string 16), `making_charge_value` (numeric) | type=string, value=decimal:2 | nullable; NULL ⇒ `fixed` |
| `invoice_items` | `making_charge_type`, `making_charge_value` | same | nullable; NULL ⇒ `fixed` |
| `quick_bill_items` | `making_charge_type`, `making_charge_value` | same | nullable; NULL ⇒ `fixed` |
| `shop_preferences` | `default_making_charge_type` (optional) | string | nullable; NULL ⇒ `fixed` |

**Old-row compatibility:** NULL `type` is read as `fixed`, `value = making_charges`. **Serializer
fallback:** API/exports emit `making_charge_type ?? 'fixed'`. **Rollback safety:** drop the nullable
columns + revert the feature flag → exact prior behaviour (resolved ₹ untouched). **Optional tidy
backfill** (`UPDATE … SET making_charge_type='fixed', making_charge_value=making_charges WHERE
making_charge_type IS NULL`) is safe (changes no amount) but **not required** — defer to MC-7.
Follow the PostgreSQL boolean rule if any boolean is ever added (`DB::raw('true'/'false')`).

---

## 8. Validation & Rounding Audit

| Concern | Rule |
|---|---|
| Type validation | `making_charge_type ∈ {fixed,percentage,per_gram}` at every input boundary (StoreItemRequest, PosController×4 endpoints, QuickBillController, Api\PosController, Api\Mobile\PosController, Api\Mobile\QuickBillController) |
| Value bounds | fixed ≥ 0; percentage ∈ [0, cap] (cap configurable, e.g. ≤ 100 or shop cap); per_gram ≥ 0; **negative prevention** everywhere (mirror existing `min:0`) |
| Percentage rounding | `resolved = round(metalValue × value/100, 2)` — 2-dp ₹ before entering `line_total` |
| Per-gram rounding | `resolved = round(net_metal_weight × value, 2)` |
| Decimal consistency | making resolves to **paisa-safe 2-dp** ₹, matching `PricingBreakdown::money()` and the engine's existing `round(...,2)` discipline |
| GST interaction | none new — making stays inside `line_total → taxable`; per-line GST still via `apportionGstToLines` |
| Deterministic totals | resolution is pure (no `now()`/`auth()`); same input ⇒ same ₹ ⇒ same canonical bytes |
| Guard identity | `total = subtotal + gst + wastage − discount + round_off` unaffected (making ⊂ subtotal) |

**Canonical rounding behaviour (im10):** one resolution helper used by manufacturer POS, Quick
Bill, and item registration so all surfaces round identically. No per-surface re-implementation.

---

## 9. UI / UX Touchpoint Audit

| Surface | File(s) | Change |
|---|---|---|
| Item registration | `inventory/items/create.blade.php`, `create-retailer.blade.php`, `edit*.blade.php` | add mode dropdown + dynamic value label (₹/%/₹·g⁻¹); live "= ₹Y resolved" |
| Manufacturer POS | `pos_customer.blade`, `pos_customer_retailer.blade` | mode selector on making field; live resolved preview |
| Quick Bill | `quick-bills/form.blade.php` | making mode per line (sits beside existing `wastage_percent`) |
| Invoice / QB display & print | `invoices/show.blade.php`, `quick-bills/show.blade.php`, `quick-bills/print.blade.php` | render "Making: ₹Y (12% of metal)" — the **why** |
| Mobile preview / settlement | mobile app (contract only here) | show resolved ₹ + mode label |
| Exports / analytics | `InvoiceItemsSheet` | optional `Making Type` column |
| Item show | `inventory/items/show.blade.php` | display mode + resolved ₹ |

**im11:** every making display must show **why** (mode + basis), in plain English per the
`simple-english-ui` standard — never just a bare number on a mode-driven charge.

---

## 10. Rollout Dependency Map

```
MC-1 schema (additive nullable)         ──┐  no deps
                                          │
MC-2 engine resolve + canonical (flag)  ──┼─ depends MC-1
   ├ golden byte-tests (T1–T5)            │
MC-3 persistence snapshots              ──┼─ depends MC-2 (resolution exists)
MC-4 input validators + web/mobile UI   ──┼─ depends MC-2 (engine accepts type/value)
MC-5 retailer registration resolution   ──┼─ depends MC-2 + MC-3
MC-6 mobile transport additive fields   ──┼─ depends MC-2 (parity by reuse)
MC-7 optional analytics + tidy backfill ──┘  depends all; post-pilot
```

**Must land together (same deploy):**
- MC-2 engine resolution **+** the canonical feature-flag **+** golden tests. (Resolution without
  the flag/test risks signature drift.)
- MC-3 persistence **+** MC-2. (Persisting type/value is meaningless before the engine sets them;
  resolving without persisting loses reproducibility metadata.)

**Can land incrementally / independently:**
- MC-4 UI (server already resolves; UI just exposes the choice — fixed-only until UI ships).
- MC-5 retailer registration resolution (manufacturer/Quick Bill work without it).
- MC-6 mobile fields (additive; old apps unaffected).
- MC-7 analytics + backfill (purely additive).

**Feature-flag strategy:** single `features.making_charge_modes` gates (a) canonical extension,
(b) UI mode selectors, (c) acceptance of non-fixed `making_charge_type` at validators. Flag OFF =
today's behaviour, byte-identical. **Web/mobile coordination:** flip server flag first (server
resolves + persists); mobile/web UIs read the same flag via their config endpoints; old mobile
clients keep sending fixed and keep working.

**Rollback plan:** flag OFF → engine emits fixed-only canonical (byte-identical), validators
reject non-fixed types, UI hides selectors. Nullable columns may remain (harmless) or be dropped.
No finalized invoice is affected at any point.

---

## 11. Risk Classification & Implementation Checkpoints

| Risk | Sev | Checkpoint that retires it |
|---|---|---|
| Signed-quote signature drift | **HIGH** | golden byte-tests T1–T3 green; `verify()` of stored historical quotes passes |
| In-flight quote false-drift at deploy | MEDIUM | T5 + flag gating; confirm POS re-quotes on expiry |
| Retailer rate-free sale path violated (A4) | HIGH if ignored | retailer resolution lives only in `ShopPricingService` (registration); `computeRetailer` unchanged — assert in review |
| Historical invoice meaning altered | HIGH if ignored | reproducibility test: recompute a finalized line from its own row → identical ₹; ImmutableLedger untouched |
| Mode value leaking into a summed column | MEDIUM | assert `ProfitReportingService`/exports still read only `making_charges`; `making_charge_value` never summed as money |
| Mobile/web divergence | MEDIUM | both call `PricingEngine`; parity test on a %-mode quote across `Api\Mobile\PosController` vs web |
| Rounding inconsistency across surfaces | MEDIUM | single resolution helper; paisa-level tests for %, per-gram |
| Old client / old row breakage | LOW | NULL-type ⇒ fixed fallback test; old-payload acceptance test |
| Guard/GST regression | LOW | `reports:validate` + `returns:validate` green; guard formula unreferenced by making |

**Definition of done for the audit gate (before MC-2 code):** the §4.4 semantic decisions in the
companion doc are signed off, the golden-byte baseline for fixed-mode canonical JSON is captured,
and this touchpoint inventory is confirmed against the branch HEAD.

---

## 12. Constraints Honoured

No implementation performed; no accounting semantics mutated; no client-side pricing authority
(modes resolve server-side in the one engine); no silent alteration of historical invoice meaning
(resolved ₹ + inputs snapshotted, signed quotes verified against stored bytes). Preserved:
immutable accounting truth, signed-quote integrity (append-only + flagged), GST semantics,
reporting determinism, reconciliation, and historical reproducibility.

---

## 13. Final Confidence Statement (im14)

Multi-mode making charges can be implemented without accounting drift, historical corruption,
quote-signature breakage, mobile/web divergence, reporting inconsistency, or hidden regressions —
**provided** the implementation obeys three invariants this map enforces:
1. **Resolve to ₹ inside `PricingEngine`/`ShopPricingService`; persist the resolved ₹ into the
   existing `making_charges` column unchanged.** (Keeps every 🟩 READ-ONLY surface untouched.)
2. **Extend the signed canonical form append-only, behind a flag, with golden byte-tests.**
   (Keeps signatures and history intact.)
3. **Resolve retailer making at registration, never at the rate-free sale path.** (Respects A4.)

Anything that cannot be done within these three invariants is a signal to stop and re-design,
not to push through.
