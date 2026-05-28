## Permanently Forbidden Recovery Actions

The following actions are **constitutionally forbidden** regardless of urgency, business pressure, or support escalation level. No exception exists.

| Forbidden Action | Why | Correct Alternative |
|---|---|---|
| `UPDATE invoices SET total = X WHERE id = Y` | Bypasses ImmutableLedger and accounting guard trigger | Cancel via `cancelByReversal()` + issue correcting invoice |
| `UPDATE credit_notes SET gst = X WHERE id = Y` | Bypasses `credit_notes_accounting_guard_trigger` | Issue a forward correction credit note |
| `ALTER TABLE X DISABLE TRIGGER ALL` | Destroys all accounting guards simultaneously | Fix data through service layer compensating entries |
| `DELETE FROM metal_movements WHERE id = Y` | Destroys append-only ledger integrity | Issue a compensating vault adjustment movement |
| `DELETE FROM audit_logs WHERE id = Y` | `audit_logs_append_only_trigger` prevents this at DB level | Audit logs are permanent by design |
| `UPDATE store_credit_movements SET amount = X` | Bypasses `store_credit_non_negative_guard_trigger` | Issue correcting +/- movement via `StoreCreditService::addManualAdjustment()` |
| Backfilling intentional historical NULLs | Replaces truthful "not captured" with guessed data | Leave NULL; export as blank in reports |
| Running `returns:validate --fix` or similar auto-repair | Reconciliation commands are read-only | Investigate discrepancy; fix through service layer |

See CONSTITUTION.md for the full boundary doctrine.

---

# Recovery Constitution

This is the permanent governing document for all support tooling in JewelFlow. It defines the boundary between safe and unsafe recovery actions. Every support engineer, developer, and future tool-builder must read and follow these rules before acting on production data.

---

## The Governing Principle

> **Support tooling OBSERVES the ledger and APPENDS corrections. It never EDITS history.**

The financial ledger in JewelFlow is append-only by design. Every MetalMovement, every credit note, every audit log entry is a permanent record. The system is built so that the current state of any account can be derived by replaying its history from the beginning. Any action that edits a historical row breaks this invariant and destroys the ability to audit what happened.

Compensating entries are the correct recovery model — just as in double-entry accounting.

---

## What Support MAY Do

- **Run read-only artisan commands** at any time without risk:
  - `php artisan returns:validate` — checks returns domain integrity
  - `php artisan vault:reconcile` — checks lot balance integrity
  - `php artisan karigar:reconcile` — checks karigar outstanding balance
  - `php artisan shop:detect-stuck` — surfaces stuck item workflows
  - `php artisan shop:quality-signals` — surfaces operational quality issues

- **Issue compensating entries through service-layer methods:**
  - Vault adjustments (positive or negative) via the Vault → Add Adjustment UI
  - Store credit manual adjustments via the store credit management UI
  - Both paths create properly attributed `metal_movements` or `store_credit_movements` rows with notes and audit log entries

- **Use the AuditLog viewer and entity event feeds** to trace event chains before taking any action. Always diagnose before correcting.

- **Create a new `ReturnedItemDisposition` row** (re-disposition) via Control Center UI. This is append-only — it adds a new disposition record, it does not edit an existing one.

- **Add a new `MetalMovement` (vault adjustment)** with a mandatory note. The note must explain the reason for the correction in sufficient detail for a future auditor to understand it without additional context.

- **Cancel `draft` return orders** — draft returns have no accounting footprint (no CN issued, no store credit moved, no item status changed) and may be cancelled via the UI or via a scoped SQL UPDATE limited strictly to `status = 'draft'` rows.

---

## What Support MUST NEVER Do

- **Run raw SQL UPDATE or DELETE** on any finalized record, settled return order, issued credit note, or MetalMovement row. These tables are protected by DB-level triggers and the ImmutableLedger application guard.

- **Bypass ImmutableLedger guards via `forceFill()`** outside of explicitly reviewed and documented backfill migration scripts. `forceFill()` outside a migration context is not a recovery tool.

- **Edit `credit_notes.gst`, `invoice_items.gst_amount`, or `invoice_items.allocated_*` columns.** These are sealed at finalization and form the basis of tax compliance records.

- **Cancel a settled return order** in an attempt to "undo" a refund. Settled returns and issued credit notes are permanent. The forward path is always a new sale or a compensating credit adjustment.

- **Modify `audit_logs`.** The append-only DB trigger prevents this at the database level. Any attempt to work around it is a violation of this constitution regardless of technical feasibility.

- **Delete items, lots, or movements.** These are FK-protected and/or trigger-protected. Deletion is not a recovery tool; it destroys auditability.

- **Use `DB::unprepared()` to circumvent sequence guards, hash-chain triggers, or other DB-level integrity protections.** These protections exist for a reason. Circumventing them produces a database whose history cannot be verified.

- **Add UI buttons that perform direct DB mutations without service-layer validation.** All UI actions that change state must go through a named service class that enforces business rules, emits an AuditLog entry, and can be tested in isolation.

- **Create "fix everything" admin console pages.** Bulk repair tools are dangerous because they operate on multiple records simultaneously, making mistakes hard to detect and impossible to cleanly reverse. Each correction must be deliberate and individually attributed.

---

## Escalation Paths

When the above rules prohibit a necessary correction:

1. **Document the discrepancy** in writing: the record IDs, the expected vs actual values, the user action that caused the problem, and the reason a standard compensating entry is insufficient.

2. **Use the compensating-entry model** where possible. Most discrepancies can be corrected by a positive or negative vault adjustment, a store credit adjustment, or a new sale record. Exhaust this path before escalating further.

3. **If a genuine system bug caused the problem**, the fix is a database migration script with:
   - Explicit commentary explaining what data it corrects and why
   - A `--dry-run` mode that shows what would change without changing it
   - Review and sign-off by a second engineer before running on production
   - A corresponding bug fix in the application code so the bug cannot recur

4. **If data is permanently wrong due to user error** and no compensating entry can fully correct it, document the discrepancy in the relevant `VaultReconciliationRun` with `STATUS_CORRECTED` and a note. The audit trail will show the known discrepancy and the decision made.

---

## Examples: Correct vs Incorrect Recovery

**Scenario:** Wrong purity on karigar receipt (19k entered instead of 22k)

- CORRECT: Calculate the fine weight difference `(22/24 - 19/24) * gross_weight`. Enter a positive vault adjustment via the Vault UI with a note referencing the job order number and the correction reason.
- WRONG: `UPDATE metal_movements SET fine_weight = X WHERE id = Y`

---

**Scenario:** Customer refund amount was wrong due to a system bug in the policy calculation

- CORRECT: Create a new sale or store credit adjustment for the shortfall amount. Document in the audit log referencing both the original CN and the corrective record.
- WRONG: `UPDATE credit_notes SET total = X WHERE id = Y`

---

**Scenario:** Item stuck in `with_karigar` after job order cancellation

- CORRECT: Use Control Center → "Fix Status" button. The system creates a new `ReturnedItemDisposition` row and transitions the item status with a full audit trail.
- WRONG: `UPDATE items SET status = 'in_stock' WHERE id = X` (unless this is an absolute last resort with documented justification, reviewed by a second engineer, and followed by a manual audit log entry)

---

**Scenario:** Duplicate karigar receipt credited a lot twice

- CORRECT: Enter a negative vault adjustment equal to the duplicate fine weight via the Vault UI, with a note citing the job order number and the duplicate receipt date.
- WRONG: `DELETE FROM metal_movements WHERE id = Y`
