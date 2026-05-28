# JewelFlow System Constitution

> **Amendment Process:** Articles I–X are non-amendable. Articles XI–XII may be amended by a PR with 72-hour cooling-off period and explicit founder sign-off. New articles may be added by the same process.

---

## §0 Reading Order Notice

Read this document before writing any code that touches:
- Database migrations (`database/migrations/`)
- Service layer accounting (`app/Services/InvoiceAccountingService.php`, `app/Services/Returns/CreditNoteService.php`, `app/Services/Returns/ReturnService.php`)
- Models with `ImmutableLedger` trait
- Any code that calls `DB::statement()`, `DB::unprepared()`, or raw SQL

---

## §0.A Scope

This constitution governs **accounting truth and ledger integrity**. It is not a general coding standards document. It does not govern UI patterns, test coverage targets, API design, or deployment procedures. Those concerns belong in other documents.

---

## Article I — Accounting Primitives Are Inviolable

The `ImmutableLedger` trait, DB accounting guard triggers, and financial period lock (`assertShopLockForDate`) cannot be softened, bypassed, or overridden for any UX, performance, or convenience reason.

Every new flow that writes financial data must:
1. Call `assertShopLockForDate()` before any accounting write
2. Use `forceFill()` only at record creation time, never at update time on finalized records
3. Emit the correct `MetalMovement` or ledger row — never skip emission for "simplicity"

---

## Article II — Domain Lists Are Permanent

Every significant accounting and operational domain retains a list view accessible by direct URL with date-range filtering, status filtering, and CSV export. No navigation reorganization removes these views.

---

## Article III — Orchestration Lives in Event Listeners

Next-step suggestions, contextual prompts, and guided flow logic are computed exclusively in domain event listener classes. Controllers dispatch domain events and return data. They do not contain "what should the user do next?" logic.

---

## Article IV — Guided Flows Always Have Expert Escape Paths

Every guided wizard flow exposes a path to the full form. The underlying FormModel and validation are identical in both modes. No accounting correctness depends on the user proceeding through wizard steps in order.

---

## Article V — Four-Level Event Feed Depth Is Permanent

Every significant domain event has four inspectable levels: plain-language summary, operational detail, accounting detail, and full audit trail. Levels 3 and 4 are never removed from any record.

---

## Article VI — Progressive Disclosure Levels Are System-Wide Constants

Four levels (0 = operational, 1 = contextual, 2 = detail, 3 = audit) apply everywhere. No section invents its own disclosure semantics.

---

## Article VII — The Ten Permanently Explicit Actions

The following actions are permanently Tier 3 or Tier 4 (explicit choice required). No future development may make them automatic or default:

1. Any cash movement out of shop (refund, payment)
2. Store credit creation or consumption
3. Any lot creation
4. Any karigar issuance (gold leaving shop's possession)
5. Any item write-off
6. Any return settlement / credit note issuance
7. Any approval decision (approve/reject)
8. Any gold rate override
9. Any financial period lock or unlock
10. Any item disposition decision (melt/rework/writeoff)

---

## Article VIII — Relationship Discovery Is Additive

New relationship-centric entry points add paths to existing domain records. They do not replace domain-level search, filtering, or reporting.

---

## Article IX — Orchestration Decisions Are Logged

Every Tier 2 suggestion, its computed reason, and the user's acceptance or override are recorded in `orchestration_events`. This table is append-only.

---

## Article IX.A — Constitutional Trigger Registry

The following DB triggers are constitutionally protected. They must exist by exact name in any production database. Deleting, disabling, or renaming any of these triggers is a constitutional violation.

| # | Trigger Name | Table | Type | Purpose |
|---|---|---|---|---|
| 1 | `credit_notes_accounting_guard_trigger` | `credit_notes` | Accounting guard | total = subtotal + gst − discount + round_off |
| 2 | `invoice_items_finalized_guard_trigger` | `invoice_items` | Finalized freeze | Blocks edits to finalized invoice items |
| 3 | `store_credit_non_negative_guard_trigger` | `store_credit_movements` | Balance guard | Prevents store credit balance going negative |
| 4 | `invoices_accounting_guard_trigger` | `invoices` | Accounting guard | Blocks updates to finalized invoice totals |
| 5 | `credit_notes_numbering_event_trigger` | `credit_notes` | Numbering event | Emits CN number sequence event |
| 6 | `invoices_numbering_event_trigger` | `invoices` | Numbering event | Emits invoice number sequence event |
| 7 | `audit_logs_append_only_trigger` | `audit_logs` | Append-only | Blocks UPDATE/DELETE on audit log rows |
| 8 | `invoice_payments_append_only_trigger` | `invoice_payments` | Append-only | Blocks UPDATE/DELETE on payment rows |
| 9 | `loyalty_transactions_append_only_trigger` | `loyalty_transactions` | Append-only | Blocks UPDATE/DELETE on loyalty rows |
| 10 | `customer_gold_transactions_append_only_trigger` | `customer_gold_transactions` | Append-only | Blocks UPDATE/DELETE on old gold rows |
| 11 | `karigar_payments_append_only_trigger` | `karigar_payments` | Append-only | Blocks UPDATE/DELETE on karigar payment rows |
| 12 | `cash_transactions_append_only_trigger` | `cash_transactions` | Append-only | Blocks UPDATE/DELETE on cash rows |
| 13 | `metal_movements_append_only_trigger` | `metal_movements` | **NOT INSTALLED** | **Documentation placeholder.** The Constitutional Lockdown work originally planned this trigger; during Phase 0 it was discovered that `metal_movements_immutable_trigger` (entry #18, Feb 2026) already provides identical append-only protection. Migration `2026_05_26_100000` is a no-op assertion that the pre-existing trigger is in place. **The constitutional protection for `metal_movements` lives in entry #18, not this entry.** This row remains in the registry only to preserve the historical record. |
| 14 | `return_line_items_settled_guard_trigger` | `return_line_items` | Settled freeze | Blocks edits after return is settled |
| 15 | `karigar_invoices_finalized_guard_trigger` | `karigar_invoices` | Finalized freeze | Blocks edits to finalized karigar invoices |
| 16 | `job_orders_finalized_guard_trigger` | `job_orders` | Finalized freeze | Blocks edits to completed/cancelled job orders |
| 17 | `vault_reconciliation_runs_append_only_trigger` | `vault_reconciliation_runs` | Append-only | Blocks UPDATE/DELETE on reconciliation run rows |
| 18 | `metal_movements_immutable_trigger` | `metal_movements` | Append-only | **Pre-existing since 2026_02_18.** Calls `prevent_ledger_mutation()`. Predates Constitutional Lockdown work but carries the same protection. |
| 19 | `cash_transactions_immutable_trigger` | `cash_transactions` | Append-only | **Pre-existing since 2026_02_18.** Same enforcement function. |
| 20 | `customer_gold_transactions_immutable_trigger` | `customer_gold_transactions` | Append-only | **Pre-existing since 2026_02_18.** Same enforcement function. |
| 21 | `shop_daily_metal_rate_entries_guard_trigger` | `shop_daily_metal_rate_entries` | Append-only (lifecycle-aware) | **Phase 1.** DELETE always blocked; UPDATE blocked unless only mutable allow-list columns (rate_per_gram, source, entered_by_user_id, entered_at, updated_at) change. Identity columns (id, shop_id, business_date, metal_type, created_at) cannot mutate. |
| 22 | `stone_components_snapshot_guard_trigger` | `stone_components` | Snapshot-aware | **Phase 2A** (extended Phase 2B). Rows tied to an inventory `item_id` only are mutable. Once linked to a finalized invoice_item or settled return_line_item, the row becomes immutable — UPDATE blocked except `notes`, DELETE blocked. Phase 2B extended the locked-list to include `certificate_id`, `certificate_authority`, `grade`, `supplier_name`, `photo_path`. Implements Article XIV (Commodity vs Manual Valuation Boundary): no automated process may touch stone values or metadata. |
| 23 | `stone_revaluation_events_append_only_trigger` | `stone_revaluation_events` | Append-only | **Phase 2B.** Append-only audit ledger of every operator-initiated stone revaluation. Each row captures old/new unit_value, old/new count, delta, mandatory reason, and reevaluator user_id. UPDATE and DELETE both raise. Constitutional witness that every change to a stone's value is reconstructable and operator-attributed (Article XIV). |
| 24 | `audit_logs_hash_trigger` | `audit_logs` | Hash-chain integrity | **Pre-existing.** Computes/verifies the audit_log hash chain (forensic non-repudiation). Surfaced by Phase 3 audit. |
| 25 | `metal_rates_no_update` | `metal_rates` | Append-only | **Pre-existing.** Blocks UPDATE on `metal_rates`. Paired with #26. |
| 26 | `metal_rates_no_delete` | `metal_rates` | Append-only | **Pre-existing.** Blocks DELETE on `metal_rates`. Paired with #25. Together they enforce append-only on the metal rate snapshot ledger. |
| 27 | `platform_audit_logs_append_only_trigger` | `platform_audit_logs` | Append-only | **Pre-existing.** Platform-level audit immutability (analogous to #7 for tenant audit logs). |
| 28 | `store_credit_append_only_guard_trigger` | `store_credit_movements` | Append-only | **Pre-existing.** Append-only on store-credit movements. Pairs with #3 (which protects the running balance). |
| 29 | `invoices_numbering_guard_trigger` | `invoices` | Sequence guard | **Pre-existing.** Enforces invoice number monotonicity / sequence integrity. Pairs with #6 (numbering event emission). |

### Operational triggers (not constitutionally critical — documented for completeness)

The following triggers exist in the DB but are operational helpers, not accounting-truth enforcers. They are documented here so a future audit does not flag them as drift, but they are NOT part of the Constitutional Trigger Registry and may be modified by ordinary feature work:

| Trigger | Purpose |
|---|---|
| `customers_business_identifier_trigger` | Auto-generates `customer_code` on customer insert |
| `imports_business_identifier_trigger` | Auto-generates business identifier on imports insert |
| `metal_lots_business_identifier_trigger` | Auto-generates `lot_number` on metal_lots insert |
| `repairs_business_identifier_trigger` | Auto-generates repair number on repairs insert |
| `protect_last_super_admin_trigger` | Prevents deletion of the last super-admin user |
| `update_item_return_disposition_cache_trigger` | Maintains the `items.return_disposition` denormalised cache from `returned_item_dispositions` |

**Note on registry entries 13, 14, 18, 19, 20:** Three tables (`metal_movements`, `cash_transactions`, `customer_gold_transactions`) carry TWO append-only triggers each: the Feb 2026 `*_immutable_trigger` (entries 18–20) and the May 2026 `*_append_only_trigger` (entries 12, 13, 14 of the Constitutional Lockdown work). Both fire BEFORE UPDATE OR DELETE and both raise on violation. The redundancy is harmless and provides defense in depth; either trigger catches a constitutional violation. Future migrations may consolidate but must NEVER remove either independently.

---

## Article IX.B — Never-Disable Rule

No migration, script, deployment step, or hotfix may execute `ALTER TABLE … DISABLE TRIGGER` or `SET session_replication_role = replica` in production. If a migration requires bypassing a trigger, **the migration is wrong** — redesign it to work within constraints.

Exception: designated backfill migrations during a controlled deployment window. These must be reviewed and explicitly approved in the PR. The backfill window must be closed (triggers re-enabled) before the migration is considered complete.

---

## Article X — Performance Contracts Are Non-Negotiable

Response time contracts (POS < 150ms, sale submit < 300ms, entity detail < 800ms) are permanent constraints. Feature richness never justifies violating them.

---

## Article XI — Mobile Is Guided Mode Only

Dense mode, multi-item batch operations, and multi-column layout are desktop-only. Mobile uses guided mode, single-column layout, and bottom-sheet modals for contextual prompts.

---

## Article XII — New Feature Placement Requires Constitutional Justification

Every new feature that adds a top-level route, navigation entry, or standalone page must include a written justification answering: "Does an owner naturally say 'I'm going to manage my [X]'?" Code reviewers reject features that fail this test.

---

## Article XIII — Material Tier Doctrine

Every material (metal or stone) operates under one of three tiers:

**Tier 1 — Fully Supported.** Receives every operational flow without restriction. Auto-reprice, live-rate auto-fetch, dhiran collateral, exchange payment, all reports, all reconciliations.

**Tier 2 — Limited Support.** Receives inventory, invoicing, manual valuation, per-metal reconciliation, and reporting. Explicitly EXCLUDES one or more of: live-rate auto-fetch, retailer auto-reprice, dhiran collateral, exchange payment, weekly lot pooling. Tier 2 caveats are operator-visible (Settings → Materials warning copy) and audit-logged at the moment the operator opts in.

**Tier 3 — Blocked.** Rejected at three layers:
  1. Controller validator returns 422 with clear error
  2. Service-layer guard throws `LogicException` via `MetalRegistry::assertSupported()`
  3. DB CHECK constraint refuses the INSERT

No operator action may transact in a Tier 3 material. No automated process may promote a material across tier boundaries. Tier assignment is governed by `config/materials.php` and `MetalRegistry`. Per-shop opt-in lives in `shop_enabled_metals`.

**Current tier assignment (as of Phase 1 rollout):**
  - Tier 1: gold, silver
  - Tier 2: platinum, copper
  - Tier 3: everything else (palladium, brass, rhodium, etc.)

---

## Article XIV — Commodity vs Manual Valuation Boundary

Live-rate updates, scheduled jobs, background processes, and repricing engines may only modify components classified as **commodity-priced** and only on records in `draft` status.

**Manual valuations** — including stone values, making charges, certification charges, repair labor, custom adjustments, and any future per-piece price — are **immutable to any automated process**. Manual revaluation requires explicit operator action with audit-logged reason.

A future feature that automates manual-valuation updates (e.g., AI valuation, market feed for diamonds) is a constitutional change requiring Article XIV amendment with founder sign-off.

Specifically forbidden, regardless of business pressure:
  - Auto-revaluation of stone components based on any market source
  - AI-driven valuation suggestions written directly into the ledger
  - Cross-shop pricing alignment
  - Speculative repricing based on predicted future rates

---

## Article XV — MetalRegistry Authority

`MetalRegistry` is the single authoritative source for material support boundaries. No controller, service, observer, view, scheduled job, console command, or migration may bypass `MetalRegistry` by referencing a metal literal directly in validation, aggregation, branching, or schema logic.

**The only legitimate locations where metal literals (`'gold'`, `'silver'`, `'platinum'`, `'copper'`) may appear are:**
  1. Inside `MetalRegistry` itself
  2. Inside migration files defining historical CHECK constraints
  3. Inside reference data seeders (`shop_metal_purity_profiles`, `gst_categories`, `stone_types`)
  4. Inside `config/materials.php` (the source MetalRegistry consults)

All other code must consult `MetalRegistry::isSupported`, `MetalRegistry::tierFor`, `MetalRegistry::isLiveRateEligible`, `MetalRegistry::isAutoRepricedEligible`, `MetalRegistry::isDhiranEligible`, `MetalRegistry::isExchangePaymentEligible`, `MetalRegistry::enabledMetalsForShop`, etc.

**Future enforcement:** a CI grep check rejects new commits introducing `=== 'gold'`, `=== 'silver'`, `Rule::in(['gold', 'silver'])`, or equivalent literals outside the whitelisted locations above. The check runs against the diff; existing literals in pre-Phase-1 code are grandfathered but flagged for refactor.

---

## §1 — Trigger Deployment Failure Protocol

If a trigger migration fails in CI or production:

**Stop conditions (abort the deployment):**
- Any `CREATE OR REPLACE FUNCTION` exits non-zero
- Any `CREATE TRIGGER` exits non-zero
- The pre-validation block finds violations (row count > 0)

**Rollback:**
- Run `php artisan migrate:rollback --step=1` to execute the `down()` method
- The `down()` method drops the trigger and function by name
- Verify with `SELECT trigger_name FROM information_schema.triggers WHERE table_name = 'X'`

**Investigation (before re-running):**
- Identify the violating rows via the pre-validation query in the migration
- Fix the data through the service layer (compensating entries, never raw UPDATE)
- Only re-run the migration after violations are resolved

**Rejected responses:**
- ✗ Do NOT disable the trigger as a workaround
- ✗ Do NOT comment out the pre-validation block
- ✗ Do NOT use `DB::unprepared()` to bypass the trigger

---

## §2 — Forbidden Patterns

### Pattern F1 — Raw UPDATE on finalized records
```php
// ✗ WRONG — bypasses ImmutableLedger and triggers
DB::statement("UPDATE invoices SET total = 5000 WHERE id = 42");

// ✓ CORRECT — issue a compensating entry through the service layer
$vault->addAdjustment(...);
```

### Pattern F2 — forceFill() on finalized record at update time
```php
// ✗ WRONG
$invoice->forceFill(['total' => 5000])->save(); // invoice is finalized

// ✓ CORRECT — forceFill() is ONLY legal at CREATE time via record() factory methods
```

### Pattern F3 — Disabling triggers in migrations
```sql
-- ✗ WRONG
ALTER TABLE invoices DISABLE TRIGGER ALL;
UPDATE invoices SET gst = 0 WHERE ...;
ALTER TABLE invoices ENABLE TRIGGER ALL;

-- ✓ CORRECT — use forceFill() in a designated backfill migration that runs before
-- the trigger is installed, then installs the trigger with pre-validation
```

### Pattern F4 — PHP boolean in bulk UPDATE (PostgreSQL crashes)
```php
// ✗ WRONG — PostgreSQL rejects 0/1 for boolean columns
GstCategory::whereNotIn('id', [$id])->update(['is_default' => false]);

// ✓ CORRECT
GstCategory::whereNotIn('id', [$id])->update(['is_default' => DB::raw('false')]);
```

### Pattern F5 — Reconciliation commands that write
```php
// ✗ WRONG — reconciliation commands are read-only
// Inside vault:reconcile or returns:validate:
MetalLot::where('id', $id)->update(['fine_weight_remaining' => $correct]);

// ✓ CORRECT — report only; human initiates correction via service layer
$this->error("Lot #{$id} has discrepancy of {$diff}g. Use vault adjustment to correct.");
return 1; // non-zero exit
```

### Pattern F6 — Speculative or AI-generated audit narratives
```php
// ✗ WRONG — never infer or generate narrative text
$summary = "Customer probably returned because of quality issue";

// ✓ CORRECT — use deterministic lookup
$summary = MetalMovement::humanLabel($movement->type);
```

---

## §3 — Boundary Doctrine

**Lane 1: UI → Service → Ledger**
Every user action that creates financial value flows: controller validates input → service layer enforces business rules + calls assertShopLockForDate → model writes via forceFill() at create time → DB trigger backstops. Never skip the service layer.

**Lane 2: Support → Compensating Entry**
Support engineers correct data by issuing new rows through service methods (vault adjustment, store credit manual adjustment). They never run raw UPDATE/DELETE on ledger tables. The audit log captures every compensating entry with actor and reason.

**Lane 3: Migration → Backfill Window**
Schema backfills that must touch finalized records use raw SQL in a designated migration. The migration installs the trigger AFTER the backfill, with a pre-validation block that aborts if any row would violate the new constraint. The trigger is never disabled — it simply doesn't exist yet during the backfill phase.

---

## §4 — Migration Discipline

**Rule 1:** Every migration that adds a column to a finalized-record table must default to `NULL` or a safe default. Never add `NOT NULL` without a default unless the table is empty.

**Rule 2:** Every migration that installs a trigger must include a pre-validation block that counts rows violating the constraint. If count > 0, raise an exception with the violating row IDs.

**Rule 3:** Every trigger migration's `down()` method must `DROP TRIGGER IF EXISTS` and `DROP FUNCTION IF EXISTS` by exact name.

**Rule 4:** Migration filenames encode the sequence: `YYYY_MM_DD_HHMMSS_description.php`. Migrations in a batch must have ascending timestamps to guarantee execution order.

**Rule 5:** Never reference an Eloquent model by class in a migration. Use raw `DB::` or `Schema::` calls only — models change but migrations must run in sequence forever.

**Rule 6:** Seed data belongs in seeders, not migrations. Exception: permission/role seed data that must run in sequence with schema changes.

**Rule 7:** Never use `DISABLE TRIGGER` in any migration. See Article IX.B.

---

## §5 — Intentional Historical NULLs

The following columns are nullable because they were added after the table had existing rows. The NULL values are **truthful** — they record "this was not captured at that time." They must **never** be backfilled with guessed or derived data.

| Column | Table | Why NULL is truthful |
|---|---|---|
| `place_of_supply_state_code` | `invoices` | Added post-launch; pre-launch invoices have no captured supply state |
| `buyer_gstin` | `invoices` | Added post-launch; pre-launch invoices have no captured GSTIN |
| `buyer_customer_type` | `invoices` | Added post-launch; customer type not captured historically |
| `place_of_supply_state_code` | `credit_notes` | Mirrors invoice; same rationale |
| `buyer_gstin` | `credit_notes` | Mirrors invoice; same rationale |
| `state_code` | `customers` | Added post-launch; customer state not recorded historically |
| `gstin` | `customers` | Added post-launch; GSTIN not collected historically |
| `customer_type` | `customers` | Added post-launch |
| `snapshot_place_of_supply_state_code` | `invoice_compliance_snapshots` | Added post-launch |

**Forbidden actions on these columns:**
- Do NOT run `UPDATE invoices SET place_of_supply_state_code = 'XX' WHERE place_of_supply_state_code IS NULL`
- Do NOT derive a value from shop address and backfill
- Do NOT assume a NULL means intra-state; it means "unknown"
- DO allow NULL in GSTR-1 exports — export them as blank, not as a guessed state code

---

## §6 — Future-Maintainer Warnings

**Warning 1 — manufacture MetalMovement has from_lot_id = NULL by design**
When a karigar receipts finished items, `MetalMovement(manufacture)` is created with `from_lot_id = NULL`. This is correct — the lot was debited at `job_issue` time. Fixing this by adding a lot reference would create a double-debit. Do not "fix" it.

**Warning 2 — forceFill() at CREATE is legal; at UPDATE on finalized records it is not**
The `record()` factory methods on ledger models use `forceFill()` at create time to populate append-only fields. This is the one legitimate use. Any `forceFill()` call on an existing finalized record is a constitutional violation.

**Warning 3 — cgst_was_backfilled marks assumption-based splits**
Invoices with `cgst_was_backfilled = true` had their CGST/SGST computed by the M2 backfill script assuming intra-state. These may be wrong for interstate shops. Do not treat them as authoritative for state code derivation.

**Warning 4 — reconciliation commands exit 1 on discrepancy; this is not an error**
`returns:validate`, `vault:reconcile`, `karigar:reconcile`, `shop:detect-stuck` exit with code 1 when they find discrepancies. This is correct behavior — they are reporting tools. CI pipelines must not treat exit 1 from these commands as a build failure.

**Warning 5 — Weekly lot aggregation destroys per-transaction traceability**
The `old_gold_weekly` aggregation command pools daily exchange lots. After aggregation, the link from a specific customer exchange to a specific lot row is gone. This is by design for the common case. Per-transaction traceability is an opt-in shop setting (deferred to Phase 5+).

---

## §7 — GST Truth Path (Single Authoritative Path)

All GST rate computation for invoice items flows through exactly one path:

```
gst_categories table → GstRateResolver::resolve() → InvoiceItem.gst_rate (snapshotted)
                                                   → InvoiceItem.gst_amount (computed)
```

No controller, no view, no migration may compute GST rates by querying `shop_preferences`, hardcoding `0.03`, or applying a percentage directly. All GST must go through `GstRateResolver`. Quick Bill manual rate override sets `invoices.gst_override = true` AND snapshots the rate into `invoice_items.gst_rate` — it does not bypass the snapshot requirement.

---

## §8 — No Admin Mutation of Finalized Records

Shop admins, platform admins, and support engineers may not directly modify finalized accounting records through any admin UI, artisan command, or API endpoint. The only permissible correction paths are:

1. **Vault adjustment** — via `BullionVaultService::addAdjustment()` with mandatory note
2. **Store credit adjustment** — via `StoreCreditService::addManualAdjustment()` with mandatory note and approval
3. **Compensating invoice/credit note** — create new correcting documents; never edit existing ones
4. **Return and re-issue** — for misbooked invoices; cancel via `cancelByReversal()` + issue a new invoice

If none of these paths work, the situation requires CA review, not a database edit.
