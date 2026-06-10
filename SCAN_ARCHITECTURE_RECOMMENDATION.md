# SCAN ARCHITECTURE RECOMMENDATION
*Date: 2026-06-10 — Read-only strategic review. No code, migrations, commits, or redesign of unrelated systems.*
*Companion to: [SCAN_SESSION_WORKFLOW_AUDIT.md](SCAN_SESSION_WORKFLOW_AUDIT.md)*

---

## Premise (established by the prior audit)

The phone → web-POS scan bridge is **pull-based**: the phone drops a barcode into a server queue (`scan_events`, `processed=false`); the web POS, *while it is polling*, claims and consumes it. Confirmed in production: 6 barcodes reached the backend, the web never polled, all 6 sit `processed=false`, and **the phone still reported success.** No data/inventory/accounting/tenant/permission/security impact. The single real defect is a **broken feedback loop**: scans can sit indefinitely while the phone believes they succeeded.

The decision this document drives: *what is the right business + engineering move for a SaaS entering pilot* — not what is theoretically perfect.

---

## Question 1 — Correct long-term architecture (if scanning is a major differentiator)

If "scan on your phone, watch it land on the counter screen instantly" is a headline feature, the long-term destination has four properties:

1. **Terminal-authoritative, phone-as-input.** The web POS owns the cart (source of truth). The phone is a remote input device, never a second cart. This kills the silent-merge class of problems by definition.
2. **A closed, acknowledged loop.** Every scan is *delivered and acknowledged back to the phone* — the phone shows "✓ Added: Gold Ring 22k, ₹X" or "✗ not added (item not found / desktop not listening)." The phone never claims success on "queued."
3. **Presence + explicit pairing.** Both ends continuously know the other is alive (heartbeat). Pairing is explicit and visible: this phone ↔ this terminal, with a named scanner shown on the POS and a clean take-over when a new device pairs.
4. **Low-latency transport.** A persistent, server-brokered channel so a scan appears on the counter screen in well under a second, both directions.

That end-state is a **presence-aware, acknowledgement-based, real-time channel** with the terminal as cart authority. **It is the destination, not the first build.** The differentiator is the *experience* (instant, confirmed, trustworthy), and that experience is delivered incrementally — most of its value (trust, confirmation, presence) is available *before* you pay for real-time transport.

---

## Question 2 — Smallest production-grade solution to the real problem

The real problem is one sentence: **the phone lies about success and neither side can see the other's state.** The smallest honest fix has exactly three moves, all expressible on the *existing* pull model and existing schema:

1. **Make the phone honest.** "Sent" must become "queued," and only flip to "added" once the event is actually consumed (the `processed` flag already exists as the truth signal). If it isn't consumed within a short window, the phone says "not picked up — is the POS scan panel open?"
2. **Make the desktop's state visible to the phone.** Surface whether a terminal is currently listening (a live poll = presence). A phone that connects to a session nobody is polling should *say so* immediately.
3. **Make the connected scanner visible on the POS.** Show "Scanner connected: <name>" (and how many) using the lifecycle columns that already exist but are unused.

That is the whole minimal solution: **a delivery-confirmation loop layered on the current poll, reusing `processed`, `connected_user_id`, `last_seen_at`.** No new infrastructure, no new runtime dependency, no migration. It converts a silent failure into a visible, self-explaining state — which is the entire defect.

---

## Question 3 — A / B / C / D

**Chosen: B — Polling + delivery confirmation.**

| Option | Verdict | Why |
|---|---|---|
| A. Keep polling, just improve it | Insufficient | Tuning the poll without a confirmation loop leaves the phone still lying on "queued." Doesn't fix the actual defect. |
| **B. Polling + delivery confirmation** | **Chosen** | Smallest change that makes the phone honest and both ends observable. Solves the *observed* production failure. Zero new infra, reuses existing columns, reversible — correct risk profile for pilot. Confirmation semantics also become the foundation a future real-time layer reuses. |
| C. Full real-time push | Premature | The right *eventual* transport, but it adds a persistent-connection runtime (a new critical-path dependency and outage surface) to fix a MEDIUM, no-data-loss problem — before any pilot data proves scanning is even heavily used. Wrong order. |
| D. Something else | No | No fourth option beats B on value-per-risk at this stage. |

**Justification in one line:** B is the *smallest thing that makes the system tell the truth*, and truth — not latency — is what's actually broken today.

---

## Question 4 — Multiple staff scanners

| Model | Behavior fit | Verdict |
|---|---|---|
| **1 phone → 1 POS** | Each counter runs one cart; one cashier owns one high-value, reviewed sale. Predictable, attributable, no cross-talk. | **Default.** |
| Many phones → 1 POS | Collaborative filling of one cart. Real but niche; today it happens *silently*, which is the danger. | Opt-in, explicit, attributed — never the silent default. |
| Both | Support both, but the *default* must be deterministic. | Yes, with 1:1 as default. |

**Default for jewellery retailers: 1 phone ↔ 1 POS terminal.** Reasoning grounded in the domain: jewellery is **counter-centric and high-ceremony** — each transaction is a deliberate, high-value, individually-reviewed bill tied to one cashier. The merge-everyone-into-one-cart model is the *exception*, useful only in rare high-touch "two staff serving one customer" moments, and it must be **explicit and visible** (named scanners, per-scan attribution) rather than silent. The schema already encodes this intent (`connected_user_id`, `device_install_id`); the product should honor it. Many→1 stays available but only as a consciously chosen, attributed mode.

---

## Question 5 — Risks of leaving current behavior unchanged for 100 pilot customers

| Rank | Risk | Reasoning |
|---|---|---|
| CRITICAL | — | None. No path to data loss, inventory mutation, silent commit, or tenant/security breach. A scan never finalizes a sale; the cart is human-reviewed. |
| HIGH | — | None by the strict rubric (no data/inventory/security loss). |
| **MEDIUM** | **"The scanner doesn't work" perception → support load + trust erosion** | The single defect (silent failure) is *invisible and confusing* to a non-technical owner. Across 100 shops it compounds into recurring tickets and a "flaky" reputation — the dominant *business* risk, even though it is not a *data* risk. |
| **MEDIUM** | **Silent multi-phone merge → occasional wrong cart** | Possible only if a cashier finalizes without reviewing — the mandatory cart-review step is the safety net, so it stays MEDIUM, not corruption. |
| LOW | No live scanner visibility / rogue same-shop injection | Detection gap; bounded by signed token + shop scope + permission + cart review. |
| LOW | Orphaned active sessions, unused attribution columns | Housekeeping/forensics only; `audit_logs` still records each sender. |

**Net:** leaving it unchanged is **safe but not painless** — it won't corrupt anything, but the silent-failure perception is the thing most likely to cost trust at scale. This is precisely the gap that the small fix (Q2/B) removes.

---

## Question 6 — Risks of overengineering now (building full real-time before pilot feedback)

| Rank | Risk | Reasoning |
|---|---|---|
| CRITICAL | — | None inherent, *unless* the new real-time layer is placed on the POS critical path and fails (see HIGH). |
| **HIGH** | **New persistent-connection runtime becomes a critical-path dependency / new outage surface** | Adding WebSocket/real-time infra to fix a MEDIUM, no-data-loss problem means a connection-server outage could now degrade the *whole* POS experience. You would raise the blast radius to solve a contained issue — a worse trade than the bug. |
| **MEDIUM** | **Delays pilot / opportunity cost** | The pilot is the priority; real-time infra (servers, scaling, reconnection, auth on the channel) is weeks of build + ops hardening that postpones learning from real shops. |
| **MEDIUM** | **Building for unvalidated assumptions** | No pilot data yet proves phone-scanning is heavily used, or used the way we imagine. Hard-committing to real-time before usage signals risks optimizing a feature shops barely touch. |
| LOW | Added operational complexity for a small team pre-revenue | Real-time infra is a permanent maintenance tax. |

**Net:** overengineering now is *riskier than the bug it fixes* — it trades a contained MEDIUM annoyance for a HIGH-severity new dependency and a slower pilot.

---

## Question 7 — As CTO: staged recommendation

**Before pilot — make the system honest, cheaply.**
- Ship the Q2/Option-B essentials: phone confirms only on actual consume; phone surfaces "POS not listening"; web POS shows the connected scanner(s); default pairing to 1 phone ↔ 1 terminal with a visible take-over or warning.
- Add a one-line onboarding note ("open the scan panel first, then pair the phone; one phone per counter").
- Cost: small, reversible, no new infra, no migration. This removes the only trust-killer before real shops see it.

**During pilot — instrument and let usage decide.**
- Measure, don't assume: how often phone-scanning is actually used per shop, scan→consume latency, rate of unconsumed events, frequency of multi-phone sessions, and support tickets tagged "scanner."
- Treat the pilot as the experiment that answers "is scanning a differentiator worth real-time, or a convenience the improved poll already serves?"

**After pilot — invest only where evidence points.**
- If the data shows scanning is heavily used *and* feedback latency is the limiting factor → build the real-time, presence-aware, ack-based channel (Q1 end-state), now justified by usage.
- If usage is light or the improved poll already satisfies shops → keep it; spend the engineering elsewhere.
- Add many→1 collaborative scanning only if pilot shops actually ask for it, and only as an explicit, attributed mode.

**One-line CTO summary:** *Make it honest before pilot, measure during pilot, and let real usage — not architectural ambition — earn the right to real-time after pilot.*

---

## Final determination

This is **not a pilot blocker.** It is a contained, MEDIUM-severity *trust/visibility* gap on a deliberately simple pull model. The correct move is the **smallest honest fix (B)** before pilot, **instrumentation** during pilot, and a **data-earned** decision about real-time after pilot. Building the full real-time architecture now would cost more risk than the defect it removes.

---

*No code, schema, routes, or configuration were modified in producing this recommendation.*
