# Event Taxonomy

> **Constitutional reference:** This taxonomy implements the requirements of [CONSTITUTION.md](../CONSTITUTION.md) Article V (Four-Level Event Feed Depth), Article VI (Progressive Disclosure Levels), and Article IX (Orchestration Decisions Are Logged). The `audit_logs` append-only trigger is registered in Article IX.A as a constitutional trigger.

This document is the permanent source of truth for what belongs in each of the three event and audit layers. Future developers adding new features should consult this document before deciding where to record an event.

---

## Layer 1: audit_logs

**What it records:** Every state transition, every user action that has financial or operational significance, financial writes, and approval decisions. This is the primary compliance and accountability ledger.

**Who writes it:** `AuditLog::record()` called explicitly in the service layer. It is never written automatically by observers alone — a deliberate service-layer call is required to ensure the context (who did what, why) is captured.

**Who reads it:**
- Accountants and auditors reviewing financial history
- Support engineers tracing event chains
- Shop owners via **Settings → Audit tab** (with date, user, and type filters)

**Retention:** Permanent, append-only. A DB-level trigger blocks `DELETE` and `UPDATE` on this table. No application-layer code can bypass it without direct DB access.

**NOT for:**
- Raw DB operations or internal system bookkeeping
- Query performance or system health metrics
- High-volume internal state that no human would review

---

## Layer 2: entity_events

**What it records:** Human-readable operational events tied to a specific entity — an item, invoice, karigar, or return order. Each event describes something that happened to that entity in plain language that an owner or support engineer would want to read.

**Who writes it:** `EntityEventService::record()` called from Observers and service methods. The call includes the entity reference, a human-readable summary, a level (0–3), and optional metadata.

**Who reads it:**
- Shop owners and support staff on entity detail pages
- Displayed using a four-level disclosure hierarchy

**The four disclosure levels:**

| Level | Name | Visibility | Example |
|---|---|---|---|
| 0 | Always visible | Shown by default, always | Sale finalized, item status changed |
| 1 | Contextual | Shown by default, collapsible | Approval request sent, override applied |
| 2 | Detail | Expandable on request | Specific deduction amounts, policy parameters |
| 3 | Audit | Explicit activation only (debug/support) | Internal flags, system-generated metadata |

**The rule:** If a human would not naturally read the event as "X happened on this item/invoice/return", it does not belong here. MetalMovement emissions, lot balance updates, trigger firings — these are internal bookkeeping and have no place in entity events.

**NOT for:**
- Raw audit rows or double-entry bookkeeping records
- System bookkeeping that no human would want to read
- Accounting amounts that require the precision and immutability of audit_logs

---

## Layer 3: orchestration_events

**What it records:** Records when the system made a guided suggestion (a Tier 2 orchestration prompt) and what the owner chose. This layer exists to answer the question: "Why did the system suggest this, and what did the user decide?"

**Who writes it:** `OrchestrationEvent::create()` when a system-generated default or recommendation is presented to the shop owner as a UI prompt.

**Who reads it:**
- "Why was this suggested?" audit trail accessible to support engineers
- Supports UX confusion investigations: "The owner says they never approved X — did the system assume a default?"

**Key fields:**

| Field | Meaning |
|---|---|
| `prompt_type` | The category of the suggestion (e.g., `lot_aggregation_default`, `return_policy_suggestion`) |
| `suggested_action` | What the system recommended |
| `suggestion_reason` | The logic/signal that triggered the suggestion |
| `user_decision` | What the owner chose (accept / modify / reject) |
| `was_overridden` | Boolean — did the owner change the default? |

**Status:** Not yet fully active. The `orchestration_events` table exists and the model is in place. Wiring of individual prompt types to this table is Phase F work.

**NOT for:**
- User-initiated actions (those go to audit_logs)
- Entity state changes (those go to entity_events)
- System internals with no user-facing prompt

---

## Decision Matrix

Use this table when deciding where to record a new event. Multiple columns can be checked for a single real-world event.

| Event | audit_logs | entity_events | orchestration_events | Never in any UI |
|---|---|---|---|---|
| Invoice finalized | ✓ | ✓ (sale_finalized, Level 0) | | |
| Item status changed | ✓ | ✓ (Level 0) | | |
| MetalMovement emitted | ✓ | ✗ (internal bookkeeping) | | ✓ |
| Lot balance updated | | ✗ | | ✓ |
| Return settled + CN issued | ✓ | ✓ (return_settled, Level 0) | | |
| Approval decision | ✓ | ✓ (Level 1) | | |
| Override applied | ✓ | ✓ (Level 2) | | |
| System suggested a default | | | ✓ | |
| DB trigger fired | | | | ✓ |
| Financial lock checked | | | | ✓ |
| Vault adjustment created | ✓ | ✓ on the lot (Level 1) | | |
| Job order status changed | ✓ | ✓ on the item (Level 0) | | |
| Store credit movement | ✓ | ✓ on the customer (Level 1) | | |

---

## Adding a New Event Type

Before adding a new event, answer these three questions:

1. **Does a human auditor need to see this?** → audit_logs
2. **Would a shop owner or support engineer read this on the entity's detail page?** → entity_events (at the appropriate level)
3. **Did the system present a guided recommendation that the user responded to?** → orchestration_events

If the answer to all three is "no", the event should not be surfaced in any UI layer. It may still be logged to the application log (Laravel `Log::debug`) for development purposes, but it must not pollute any of the three structured layers.
