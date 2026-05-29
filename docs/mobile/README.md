# JewelFlow Mobile Development Guide

> **Status:** This document reflects the **TRUE current state** of the
> JewelFlow SaaS backend as of the Mobile Contract Stabilization Phase A +
> M5. Read it before writing any mobile screen.
>
> The backend has transitioned from "web app with evolving APIs" to
> "platform with stabilized mobile contracts." That transition is real but
> not yet finished. Some layers are stable and authoritative; some are
> partial; some are pending. Each section below labels its own state.

---

## 0. Mental model — read this first

JewelFlow is a multi-tenant jewellery shop SaaS. The backend already
enforces:

- The three-class material pricing model (gold/silver = accounting rate,
  platinum/copper = reference memo, stones = per-piece value).
- ImmutableLedger and constitutional DB triggers for accounting safety.
- Per-shop capability gating via `MetalRegistry` and `enabledMetalsForShop`.
- Per-shop GST and pricing semantics.

**The mobile client consumes that truth. It does not redefine it.**

| Mobile is responsible for | Mobile is NOT responsible for |
|---|---|
| Rendering, navigation, interaction | Pricing rules |
| Operator workflow ergonomics | Accounting correctness |
| Offline read caching of stable resources | Material identity rules |
| Surfacing backend validation errors clearly | Inventory state machine |
| Idempotency-key generation per mutation | Tax / GST computation |
| ETag round-trip on stale-update handling | Refund policy logic |

If a mobile screen needs business logic that does not yet exist in the
API, the answer is **never** "hardcode it in the app." The answer is
"the contract is incomplete; request a backend endpoint or capability
field." This rule is non-negotiable.

---

## 1. Current backend stabilization state

These are the layers the mobile client treats as authoritative and stable.
Do not work around them, second-guess them, or re-implement them locally.

| Layer | State | Authority |
|---|---|---|
| Material identity semantics | **Stable** | `App\Services\MetalRegistry` — sole oracle for `identityClass`, `pricingClass`, `purityIsAccountingTruth`, `puritySelectorMode`, `purityLabel`, `accountingTruthMetals`, `fineWeightMultiplier`, `tier1Metals`/`tier2Metals`, `enabledMetalsForShop`. |
| Pricing-class semantics | **Stable** | The R1–R7 pricing-control plan is shipped. Classes A/B/C are constitutionally separated by file, service, vocabulary, and schema. See `docs/runbooks/pricing-control-plan.md` and `docs/runbooks/material-pricing-classes.md`. |
| Mobile capability disclosure | **Stable** | `GET /api/mobile/v1/registry/materials` returns the `MaterialRegistrySnapshot` DTO with `registry_version`. |
| Canonical API envelope | **Stable** | `MobileEnvelope` middleware wraps every `/api/mobile/v1/*` response in `{ data, meta, errors }`. |
| Validation authority | **Stable** | Shared Form Requests + `App\Rules\Material\*` + `App\Rules\Inventory\*` consulted by both web and mobile. Parity-tested in CI. |
| Idempotency foundation | **Stable** | `EnsureIdempotency` middleware + `idempotency_keys` table. Mounted on every mutation route under `/api/mobile/v1/`. |
| Concurrency protection | **Stable** | ETag / If-Match concurrency control on item and customer mutations. |
| Upload infrastructure | **PARTIAL** | See §8. Only the storage table and model exist. |
| Session / device governance | **Pending** | See §9. Existing session model works; lock-screen + revocation signal + token-session binding (M7) is not yet built. |
| Mobile domain coverage (returns, exchanges, karigar, reference-price reads) | **Pending** | See §11. |

**Anti-drift discipline:** mobile must adapt to backend semantics. Do not
reopen API philosophy, do not redesign envelopes, do not re-model
materials, do not invent capability disclosure. Those layers are frozen.

---

## 2. Material and pricing semantics

The backend models materials by **identity class** and **pricing class**.
These two axes drive every UI decision the mobile client makes around
metals.

### Three pricing classes (constitutional)

| Class | Materials | Price meaning | Storage authority |
|---|---|---|---|
| **A — Accounting rate** | gold, silver | Daily fine-weight rate × purity drives vault, exchange, melt, GST | `shop_daily_metal_rates` |
| **B — Reference memo** | platinum, copper | Operator-noted hint "what I am selling this metal at this week" — **not** a rate | `shop_metal_reference_prices` |
| **C — Value-only** | diamonds, stones, gems | Per-piece rupee `stone_amount` field on the item line | `invoice_items.stone_amount` |

**Mobile rules:**

- Branch UI behaviour on `pricing_class`, never on the metal name string.
  A mobile screen that says `if (metal === 'gold') { ... }` is broken.
  A screen that says `if (metalDescriptor.pricing_class === 'A') { ... }`
  is correct.
- Class A is the only class where purity participates in fine-weight math.
  `fine_weight = net_metal_weight × purity / 24` is **valid only when
  `metalDescriptor.fine_weight_supported === true`**. Otherwise do not
  compute fine weight at all.
- Class B has an optional reference price. It is a **memo**. It is never
  multiplied by anything, never feeds vault math, never appears in
  pricing, GST, reconciliation, or reprice. Surfacing it as a hint to
  the operator is fine. Auto-filling it into a price field is forbidden.
- Class C has no metal identity. Stones bypass the entire metal/purity
  machinery and live as a per-piece rupee value (`stone_amount`).

### Purity selector mode

| Mode | Materials | Mobile behaviour |
|---|---|---|
| `mandatory` | gold, silver | Required input. Show the label from `purity_label` (typically "Karat (K)"). |
| `lightweight` | platinum | Optional input. Show the label `Hallmark grade`. Acceptable values e.g. 95 (Pt950), 90 (Pt900). Never required. |
| `hidden` | copper, stones | Do not render any purity input at all. |

Read `metalDescriptor.purity_selector_mode` from the registry snapshot
(see §3). Never decide selector mode from a metal name.

### Reference price ≠ accounting rate

This is the single most important semantic distinction in the system.
Mobile MUST NOT:

- Display a reference price labelled "rate" or "per gram rate."
- Use a reference price as input to any computation.
- Auto-fill it into `selling_price` on item creation.
- Treat its absence as an error state (most shops never note one).

Mobile MAY:

- Display the most recent reference (with `noted_at` timestamp and the
  user who noted it) as a small grey hint next to the selling-price
  input on a platinum or copper item.
- Show a history list on a dedicated screen, mirroring the web's
  `/report/reference-prices`.

If you find yourself wanting to "convert" a reference price into a daily
rate to "make POS faster," stop. That is the class-A/B collapse the
backend constitutionally prevents.

---

## 3. Capability disclosure

The mobile client learns what a specific shop can do, what metals are
enabled, and how those metals behave, from **one endpoint**.

### `GET /api/mobile/v1/registry/materials`

Returns a `MaterialRegistrySnapshot` wrapped in the canonical envelope.

```json
{
  "data": {
    "registry_version": "2026-05-28.1",
    "shop_id": 12,
    "enabled_metals": ["gold", "silver", "platinum"],
    "metals": {
      "gold": {
        "identity_class": "purity_accounting",
        "pricing_class": "A",
        "purity_selector_mode": "mandatory",
        "purity_label": "Karat (K)",
        "purity_is_accounting_truth": true,
        "fine_weight_supported": true,
        "reference_price_supported": false,
        "active_purity_profiles": [22, 18, 14]
      },
      "platinum": {
        "identity_class": "purity_specification",
        "pricing_class": "B",
        "purity_selector_mode": "lightweight",
        "purity_label": "Hallmark grade",
        "purity_is_accounting_truth": false,
        "fine_weight_supported": false,
        "reference_price_supported": true,
        "active_purity_profiles": [95, 90]
      }
    },
    "stones": {
      "pricing_class": "C",
      "value_field": "stone_amount",
      "purity_selector_mode": "hidden"
    }
  },
  "meta": {
    "request_id": "...",
    "server_time": "2026-05-29T08:15:00Z",
    "api_version": "1",
    "registry_version": "2026-05-28.1"
  },
  "errors": []
}
```

### Mobile branching contract

Use these fields, in this order of preference, to drive UI:

1. `pricing_class` → drives whether to show a rate input, a reference
   hint, or just a price field.
2. `purity_selector_mode` → drives whether to render the purity input
   and which label to use.
3. `fine_weight_supported` → drives whether to compute and display
   fine weight.
4. `reference_price_supported` → drives whether to render the
   "Reference price" UI surface at all.
5. `active_purity_profiles` → drives the dropdown options for purity.

**Branch on capability fields, never on the metal name.** A future
metal added to the registry tomorrow should "just work" on the mobile
client with zero code changes.

### `registry_version`

Bumps on any semantic change to the registry (new identity class, new
pricing class, new purity selector mode, schema-affecting change).

- Mobile caches the snapshot keyed by `(shop_id, registry_version)`.
- When the response's `registry_version` differs from the cached one,
  refresh the cache.
- If the mobile client sees an unknown value in any field (e.g. a
  `pricing_class: "D"` it doesn't know about), fall back to the safest
  default — treat the metal as piece-priced (class B-like), do not
  attempt rate math, surface an "Update the app" hint. Never crash.

### Caching expectations

- Snapshot is shop-scoped. Cache it locally with a short TTL (a few
  hours is fine) AND treat a new `registry_version` as a hard
  invalidator from any response's `meta.registry_version`.
- The snapshot is read-only. There is no mobile endpoint to mutate it.
  Capability changes (e.g. enabling platinum) happen through the web
  Settings UI today and propagate via `registry_version` bump.

---

## 4. Canonical API envelope

Every `/api/mobile/v1/*` response — success, validation error, auth
error, 5xx — lands in this shape:

```json
{
  "data":   <object | array | null>,
  "meta":   {
    "request_id":       "<uuid>",
    "server_time":      "2026-05-29T08:15:00Z",
    "api_version":      "1",
    "registry_version": "2026-05-28.1"
  },
  "errors": [
    {
      "code":    "<stable code>",
      "field":   "<optional, validation only>",
      "message": "<human-readable>",
      "params":  { "<optional structured detail>": "..." }
    }
  ]
}
```

### Rules

- **On success**, `data` is the payload and `errors` is `[]`.
- **On failure**, `data` is `null` (most cases) and `errors` carries one
  or more entries.
- `errors` is always an **array of objects**. It is never a string,
  never an object-of-strings, never null. Validation failures shape
  `{ code: "validation.<field>", field: "<field>", message: "..." }`.
- `meta.request_id` round-trips via the `X-Request-Id` HTTP header. If
  the client sends one, the server echoes it back. If not, the server
  mints one. Use this for log correlation when filing support tickets.
- `meta.server_time` is ISO-8601 UTC. Compute clock skew at boot if you
  care; otherwise treat it as the authoritative clock.
- `meta.registry_version` is present on every response so the mobile
  client can detect a stale registry cache from any read.

### Pagination

Lists carry `meta.pagination`. Cursor-based pagination is the contract:

```json
"pagination": {
  "cursor":      "<this page>",
  "next_cursor": "<for next page, null if none>",
  "page_size":   50,
  "has_more":    true
}
```

The mobile client requests the next page by adding `cursor=<next_cursor>`
to the next request. Do not implement offset pagination. Length-aware
paginators on legacy endpoints carry `pagination.page`, `page_size`,
`last_page`, `total`, `has_more` — supported but not preferred.

### Error codes the mobile client should recognise

| Code | When |
|---|---|
| `unauthorized` | Missing / expired Sanctum token |
| `permission_denied` | RBAC gate denied |
| `not_found` | Resource missing OR cross-shop access (do not differentiate; both come back as 404) |
| `conflict` | `idempotency_key_conflict` or general 409 |
| `precondition_required` | Mutation hit an ETag-protected route without `If-Match` |
| `precondition_failed` | `If-Match` did not match current ETag — refresh and retry |
| `unprocessable` | General 422 |
| `validation.<field>` | A field-level validation error |
| `too_many_requests` | Rate-limit hit |
| `service_unavailable` | Idempotency cache unavailable, or general 503 |

Mobile should implement **one** generic error toast/handler that
displays `errors[0].message` and uses `errors[0].code` for any
code-specific routing (e.g. force-refresh on `precondition_failed`).
**Do not write endpoint-specific error parsers.**

---

## 5. Validation authority

The backend is the canonical source of validation truth. Mobile does not
duplicate rules.

### What this means concretely

- For item creation, the rules in `App\Http\Requests\Items\StoreItemMobileRequest`
  (extending `StoreItemRequest`) are authoritative. Material-aware rules
  consult `MetalRegistry` for: enabled-metal allowlist, purity-required-
  for-accounting-truth, barcode uniqueness per shop.
- The same rules are used by the web. A CI parity test asserts both
  paths produce identical errors for identical input.
- Mobile **may** do lightweight pre-flight validation for UX (e.g. "this
  field looks empty, don't bother the server yet"). Mobile **must not**
  encode business rules locally (e.g. "platinum must have purity 95 or
  90" — that's a registry-driven fact, fetch it from `active_purity_profiles`).

### Surfacing errors

- Validation errors arrive as `errors[]` entries with `code:
  "validation.<field>"` and a human message.
- Show `message` to the user inline next to the failing field.
- Do not interpret the message string itself — backend phrasing may
  evolve. Match on `code` if you need to branch UI behaviour.

### Specific stabilized rules

- `metal_type` must be one of `enabledMetalsForShop(shop_id)`. The
  registry endpoint already tells you which metals are enabled.
- Purity is required when the metal's `purity_is_accounting_truth` is
  true. The registry tells you which metals these are.
- Barcode is unique within a shop. Cross-shop collisions are allowed.
  The same item can be updated to a different barcode; the rule honours
  `ignoreItemId` on update flows.

If a screen needs new validation that does not exist in the API, do not
add it locally. Request a Rule class and a Form Request from backend.

---

## 6. Mutation safety

Network failures, retries, and stale views are not edge cases — they are
the default mode of operation on flaky cellular and shop Wi-Fi. The
backend ships two complementary guarantees.

### Idempotency

Every mutation route under `/api/mobile/v1/*` requires an
`X-Idempotency-Key` header. Rules:

- Mint a fresh UUIDv4 per logical operation, not per HTTP request. If
  the user taps "Pay" once, every retry of that same payment carries
  the **same** key.
- Keys are 8–80 characters, alphanumeric plus `_` and `-`.
- On replay of an identical request (same key + same payload), the
  server returns the cached response with header
  `X-Idempotent-Replay: true`. The mobile client treats this as
  identical to a fresh success.
- On a same-key + different-payload, the server returns
  HTTP 409 with code `idempotency_key_conflict`. This is a real bug
  on the client (you reused a key with different content). Surface it
  loudly.
- Missing header on a mutation route returns HTTP 422 with code
  `missing_idempotency_key`.
- Keys live for 24 hours then are pruned. Plan retries accordingly.

For routes that accept `idempotency_key` in the request body (legacy
POS convention), the body field is also honoured. New mobile code
should prefer the header.

### Optimistic concurrency (ETag / If-Match)

`GET` responses on items and customers (under `/api/mobile/v1/`) carry
an `ETag` HTTP header. Format: `"<sha256>:<class>:<id>"` (quoted, strong
validator). Mutations on those resources REQUIRE an `If-Match` header
echoing the ETag the client last saw.

- Missing `If-Match` → HTTP 428 with code `precondition_required`.
  Mobile should treat this as a programming error — your form should
  always have a known ETag.
- ETag mismatch → HTTP 412 with code `precondition_failed`. The
  resource changed since you fetched it. Refresh, re-display, ask the
  user to redo their edits. The `params.expected` field carries the
  current ETag for the next attempt.
- ETag match → mutation proceeds. Response carries the **new** ETag in
  the header. Cache it for the next edit cycle.

### Retry behaviour the mobile client should implement

| Response | Mobile action |
|---|---|
| 2xx with `X-Idempotent-Replay: true` | Treat as success (no extra side effect happened) |
| 4xx other than 408 / 429 | Surface error, do not retry |
| 408 / 429 / 503 | Exponential backoff retry, **same idempotency key** |
| Network timeout / DNS / TLS error | Exponential backoff retry, **same idempotency key** |
| 412 precondition_failed | Refresh the resource, re-display to user, do not auto-retry |
| 5xx server error | Backoff retry, same idempotency key, escalate after 3 attempts |

**The golden retry rule:** if you don't know whether the server received
and processed your request, retry with the same idempotency key. The
backend will either replay your prior success or process the new
attempt — never both.

---

## 7. Offline boundary

The backend draws a deliberate operational line between offline-tolerant
and online-authoritative flows. Mobile must respect it.

### Offline-tolerant (cache aggressively, work without network)

- Browsing the item catalogue (read-only)
- Cached registry snapshot (until `registry_version` bumps)
- Cached daily metal rates (with a "may be outdated" badge when >6h old)
- Customer search results (from the last sync)
- Cached reference price hints
- Viewing an invoice / credit note / repair (read-only)
- POS bootstrap data (payment modes, GST rate, scheme list) for display

### Online-authoritative (must hit the server, never queue)

- POS sale (`/pos/sell`)
- Invoice payment
- Item create / update
- Pricing write (`POST /pricing/today`)
- Reference price write (future endpoint)
- Returns / exchanges / credit notes
- Karigar job-order issue and receipt
- Vault adjustments
- Customer mutation that affects financial state (store credit, loyalty)

### Why offline POS is intentionally unsupported

Three constitutional reasons:

1. **Accounting truth.** An offline POS would need a client-side
   inventory reservation system to prevent the same physical ring from
   being sold simultaneously by two tablets. That state machine cannot
   be made correct without a server round-trip. Building an
   approximation invites the exact double-selling bug the
   ImmutableLedger architecture exists to prevent.
2. **Pricing freshness.** Gold rates can shift mid-day. An offline POS
   would either use a stale rate (the customer pays the wrong amount)
   or fall back to a user-typed override (which bypasses the audit
   trail of the daily rate). Both outcomes are worse than declining the
   sale.
3. **Reconciliation integrity.** Returns, exchanges, credit notes, and
   vault movements form a chain of immutable accounting events. An
   offline queue cannot guarantee insertion order, cannot generate a
   CN number safely, and cannot honour the shop's financial lock date.

The mobile client should detect connectivity loss and show a clear
"Reconnect to take this sale" modal. The few flows that are
soft-queue-tolerable (customer create, repair create) get a small
device-local outbox with retry-with-backoff; conflicts at sync are
surfaced to the user for resolution.

### Practical cache TTLs

| Cached resource | TTL | Invalidator |
|---|---|---|
| `MaterialRegistrySnapshot` | 12 hours | `meta.registry_version` mismatch |
| Daily metal rates | 6 hours | Day rollover or POS bootstrap refresh |
| POS bootstrap (payment modes, schemes) | 1 hour | Explicit pull-to-refresh |
| Item read | 5 minutes | ETag mismatch on next GET |
| Customer search results | 1 hour | Explicit refresh |

---

## 8. Upload state — **PARTIAL**

> Mobile developers must NOT build against imaginary upload APIs.

### What exists today

- `pending_uploads` database table.
- `App\Models\PendingUpload` Eloquent model with scopes (pending,
  expired, consumable) and an `isExpired()` helper.

### What does NOT exist yet

- `App\Services\Mobile\UploadIntentService` (mint, finalize, consume).
- `App\Http\Controllers\Api\Mobile\V1\UploadController` —
  `POST /api/mobile/v1/uploads/intent`,
  `PUT  /api/mobile/v1/uploads/{token}`,
  `GET  /api/mobile/v1/uploads/{token}` are **NOT implemented**.
- Image verification (`finfo_file` + ImageMagick or GD re-encode).
- WebP thumbnail generation pipeline.
- Retry semantics for large or interrupted uploads.
- Legacy ItemController accepting `image_upload_id` alongside
  `image_base64`.
- `mobile:prune-uploads` console command.
- Tests.

### What the mobile client should do today

For image-bearing operations on items and repairs, the mobile client
must continue using the **legacy** `/api/mobile/items` (and similar)
endpoints with `image_base64` inline in the JSON body. This is
documented for the legacy endpoints in `docs/api-quick-bills.md` and
`docs/api-repairs.md`. The legacy path will remain supported until M6
ships in full and the mobile client is migrated.

**Do not** write mobile code that calls `/uploads/intent` or
`/uploads/{token}`. Those routes return 404 today. Do not stub them on
the client expecting "the backend will catch up before release." That
is exactly the speculative coupling this stabilization phase forbids.

---

## 9. Session governance state — **PENDING**

> Current session system exists. M7 governance improvements are not yet
> built.

### What exists today

- `App\Models\MobileDeviceSession` — append-only row per login event
  with `ended_reason ∈ {logout, replaced, revoked, terminated,
  stale_pruned}`.
- `App\Services\Mobile\MobileSessionSeatService` — enforces plan seat
  limits at login.
- `App\Console\Commands\PruneStaleDeviceSessions` — closes idle
  sessions on a schedule.
- Sanctum personal access tokens minted at `POST /api/mobile/auth/login`.

### What is NOT built yet (M7)

- Token-to-session binding (the `personal_access_tokens` row carrying
  a `mobile_device_session_id` FK).
- `EnforceSessionAlive` middleware — checking the bound session's
  `ended_at` on every authenticated request.
- Lock-screen pattern (`POST /sessions/lock`, `/sessions/unlock`).
- Active-session listing endpoint.
- Server-driven revocation (`POST /sessions/{session}/revoke`).
- Owner-on-demand elevation (`POST /sessions/elevate`).
- Per-session `last_seen_at` touch on every request.

### What the mobile client should do today

- Use standard Sanctum auth: `POST /api/mobile/auth/login` returns a
  bearer token; send it on every authenticated request.
- Logout via `POST /api/mobile/auth/logout`. This closes the session
  row with `ended_reason='logout'`.
- For shared-device cashier switching, treat it as a **logout + new
  login** flow until M7 ships. Do not invent a client-side lock-screen
  that fakes session persistence — the server cannot yet verify that
  the displayed user is the authenticated one.
- Be prepared for 401 responses to mean "token rejected" generically
  for now; the more specific `session_ended` code with `params.ended_reason`
  is an M7 deliverable.

Shared-device flows are therefore evolving. Design mobile screens so
they can adopt the lock-screen pattern later without a rebuild.

---

## 10. Mobile philosophy

The single most important paragraph in this document:

> **The backend owns business semantics.**
> **Mobile owns workflow, rendering, interaction, and operator experience.**
> **Mobile consumes semantic truth; it does not define semantic truth.**

What this means at the file level:

| Permitted in mobile code | Forbidden in mobile code |
|---|---|
| Cache management, retry policy | Pricing rules (e.g. "platinum = ₹3000/g default") |
| Form layout, input masks, validation **display** | Hardcoded metal names (`if metal === 'gold'`) |
| Network state UI, optimistic UI affordances | Refund policy logic |
| Idempotency-key generation | Credit-note numbering |
| ETag round-trip handling | Vault math |
| Local caching of stable resources | GST rate derivation |
| Offline-tolerant reads | Any computation labelled "fine weight" |

If a mobile screen needs a business rule that the API does not yet
expose, you have **two** options:

1. Wait for a backend endpoint or a registry field.
2. File a backend issue describing the missing semantic.

You do **not** have a third option of "implement it in JavaScript /
Swift / Kotlin for now and refactor later." That third option is
exactly the drift this stabilization phase eliminated. Re-introducing
it on the mobile side would undo months of foundational work.

---

## 11. Mobile domain coverage — what is and is not on the v1 surface

### Available on `/api/mobile/v1/`

- `GET /registry/materials` — capability snapshot.
- `GET /items/{item}` — read with ETag.
- `PATCH /items/{item}` — write with If-Match (idempotency-protected).
- `GET /customers/{customer}` — read with ETag.
- `PATCH /customers/{customer}` — write with If-Match (idempotency-protected).

### Available on the legacy `/api/mobile/` surface only

These endpoints work but do NOT carry the canonical envelope, ETag, or
idempotency middleware. They are documented in:

- `docs/api-quick-bills.md`
- `docs/api-repairs.md`

Legacy endpoints include POS sale, invoice list/show, payment, customer
search/create, vendor CRUD, repair CRUD, item legacy CRUD with
`image_base64`, pricing today read/write, dashboard, catalog, and
scanner. Use them when v1 has no equivalent. Plan for migration as v1
adds endpoints.

### Pending domains (Phase C — not yet started)

- **M8 Returns / Exchanges / Credit notes** — server domain shipped,
  mobile API endpoints not yet wired. Until M8 lands, mobile cannot
  process returns or exchanges; users must do it on the web.
- **M9 Karigar issue / receive** — server domain shipped, mobile API
  endpoints not yet wired. Karigar workflow stays web-only for now.
- **M10 Reference-price read endpoints** — server domain shipped (R2–R7),
  mobile read endpoints not yet wired. The capability flag
  `reference_price_supported` is exposed in the registry, but no
  read/write of `shop_metal_reference_prices` is available on mobile.

Build mobile read-heavy screens first. Defer feature surfaces that
require Phase C endpoints until those endpoints exist.

---

## 12. Explicit non-goals

The architecture intentionally does not pursue these. Mobile must not
add them either.

- **No offline accounting.** The shop's books are server-authoritative,
  always.
- **No frontend business-rule engine.** The mobile client does not run
  a parallel pricing/GST/refund evaluator.
- **No universal commodity logic.** JewelFlow is a jewellery shop OS,
  not a metals exchange terminal. The mobile client does not become
  one.
- **No speculative ERP complexity.** No multi-currency, no inter-shop
  transfers, no public-developer API, no GraphQL gateway, no
  microservices fan-out. The first-party mobile client is the only
  consumer of the mobile API.
- **No client-side feature flags that bypass backend gating.** All
  feature flags live in `MaterialRegistrySnapshot` and the bootstrap
  payload. Mobile reads them; it does not store its own.

---

## 13. Continuation status (honest)

This document is the canonical reflection of the **TRUE** current
backend state. It is updated when the backend ships new contract
layers — not before, and not after the next sprint as an afterthought.

| Phase | State | Documentation status |
|---|---|---|
| Phase A — M1, M2, M3, M4 | **Shipped + verified (137/137 tests)** | This document is authoritative. |
| Phase B — M5 ETag concurrency | **Shipped + verified** | This document is authoritative. |
| Phase B — M6 uploads | **PARTIAL** — migration + model only | §8 documents the gap. |
| Phase B — M7 session governance | **PENDING** — nothing landed | §9 documents the gap. |
| Phase C — M8 returns API | **PENDING** | §11 documents the gap. |
| Phase C — M9 karigar API | **PENDING** | §11 documents the gap. |
| Phase C — M10 reference-price reads | **PENDING** | §11 documents the gap. |

When a phase ships, update this document in the same PR. Do not let
the docs drift from the contract. That is the exact failure mode this
whole stabilization phase exists to prevent.

---

## Cross-references

| Topic | Document |
|---|---|
| Material identity model (web + backend) | `docs/runbooks/material-identity.md` |
| Pricing control plan R1–R7 | `docs/runbooks/pricing-control-plan.md` |
| Class A/B/C cheat-sheet | `docs/runbooks/material-pricing-classes.md` |
| Legacy mobile endpoint reference | `docs/api-quick-bills.md`, `docs/api-repairs.md` |
| Recovery runbook (support tooling) | `docs/recovery-runbook.md` |
| Material UX implementation journal | `docs/journals/material-ux-alignment-journal.md` |

---

## A word to future contributors

The backend has transitioned from "web app with evolving APIs" to
"platform with stabilized mobile contracts." That foundation cost real
time and discipline to build.

The most dangerous next move is the one that feels harmless: a mobile
screen that hardcodes "platinum = ₹3000" "just for the demo," a
client-side validator that "mirrors the server for speed," an offline
queue that "covers a network hiccup." Each of those undoes a specific
constitutional protection.

When in doubt: the backend is authoritative; mobile renders. Treat
this document as the working agreement between the two layers, and
update it whenever the contract changes.
