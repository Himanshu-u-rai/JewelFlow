# Unresolved idempotency claims — operator procedure

**Status: PROPOSED.** The procedure and its tool (`mobile:idempotency-claims`)
exist on branch `security/multi-tenant-audit` and are tested against synthetic
data in `jewelflow_testing` only (`IdempotencyClaimReconciliationTest`, 9 tests; `tests/Concurrency/claim_release_race.php`, separate processes).
Nothing here has been run on any server. Running `--confirm` on production is a
production data change and needs its own approval.

## 1. What an unresolved claim is

`EnsureIdempotency` stakes a claim row in `idempotency_keys` **before** the
controller runs, with `response_status = 0`. It records the outcome afterwards.
A claim whose status is not a usable HTTP status (`< 100` or `> 599`) is
**unresolved**: the request reached the controller and the server never learned
how it ended. Typical causes are a process killed mid-request or a 5xx.

While the row exists, every retry under that key gets `409
idempotency_in_flight`. That refusal is what prevents a second cash entry. The
pruner (`mobile:prune-idempotency-keys`) keeps these rows on purpose.

**Age is not evidence.** A claim staked a month ago is exactly as uncertain as
one staked a minute ago. Nothing in this procedure clears a claim because it is
old, and the tool has no age option.

## 2. When to act

* The pruner reports `Retained N UNRESOLVED claim(s)`, or
* a shop reports a mobile screen stuck on "Outcome unknown".

Do not act to shrink the count. A claim left alone costs one row and keeps one
key refused, and that is always safe.

## 3. Authorization

* **Two people.** An *operator* gathers the evidence and runs the tool. An
  *approver* reviews the evidence and is named in `--approved-by`. They must be
  different people. The approver is the shop owner, for that shop's own ledger,
  or the platform lead.
* The tool records the approver's name but cannot verify it. Keep the approval
  itself (ticket or change-log entry) and put its reference in `--evidence`.
* On production, `--confirm` is a data change on the same footing as any other
  production correction: approved per claim, never in batches.

## 4. Evidence required, per claim

### 4.1 Identify the claim

```bash
runuser -u www-data -- php /var/www/jewelflow/artisan mobile:idempotency-claims
```

Read-only. Lists `id, shop, user, key, request_hash, staked_at` for every
unresolved claim.

### 4.2 Identify the request

The claim does **not** record its route or payload. It records
`request_hash = sha256(METHOD + "|" + path + "|" + raw body)`, where `path` has
no leading slash (for example `api/mobile/v1/cashbook`).

* **Strongest evidence:** the exact body the device sent. Hash each candidate
  route with it and compare to `request_hash`. A match proves which request the
  claim belongs to:

  ```bash
  printf '%s' 'POST|api/mobile/v1/cashbook|<raw json body exactly as sent>' | sha256sum
  ```

* **Otherwise:** the web-server access log for that shop's user around
  `staked_at` gives the method and path. That identifies the route, not the
  payload — record it as the weaker of the two.

If neither is available, **stop**. The claim cannot be attributed, so it cannot
be reconciled.

### 4.3 Look for the business effect

Every money or metal route writes all of its rows in one database transaction,
so a request either left all of its records or none of them. Search for records
created at or after `staked_at`, by that `user_id` in that `shop_id`:

| Route | Look in |
|---|---|
| `POST /cashbook` | `cash_transactions`, plus the `audit_logs` row of action `cash_in`/`cash_out` written with it |
| `POST /cashbook/drawer-check` | `cash_drawer_checks` |
| `POST /installments/{plan}/pay` | `installment_payments` for that plan |
| `POST /job-orders/{jobOrder}/receipt` | `job_order_receipts`, and the `items` and `metal_movements` rows it minted |
| `POST /returns` | `return_orders` for that invoice |
| `POST /returns/{returnOrder}/approve` | that return's status, and any `credit_notes` row |
| `POST /job-orders` | `job_orders` |
| `PATCH /items/{item}`, `PATCH /customers/{customer}` | the row itself. Last write wins, so a repeat changes nothing new |
| sessions, `uploads/intent` | no money moves |

Read-only queries only. Reading production for this needs the same access
approval as any other production read.

### 4.4 First: has the original request ended?

Evidence gathered while the original request can still commit is worthless: an
uncommitted row is invisible to the operator and can appear later. **Run the
tool as a dry run first.** It tries to take the claim's advisory lock. The
original request takes that lock before it stakes the claim and holds it until
the claim is resolved, and PostgreSQL drops it if the process dies — along with
any uncommitted work.

* **Lock free:** the session that held the lock has ended, and with it any
  uncommitted work. The original request cannot continue on another session:
  while it holds a claim lock, its connection may not be re-established
  (second review, below). So it can never commit. Evidence gathered from now
  on is final.
* **Lock held:** the tool refuses and changes nothing. Wait. The request is
  still running, or the operator tool is already working on this claim.

**Correction (XR-02, second review).** A free lock used to prove only that the
lock-holding *session* had ended. Laravel answers a lost connection outside a
transaction by reconnecting and retrying on a new session, so the request
itself could carry on without its lock. Measured in
`tests/Concurrency/claim_release_race.php`, scenario 2: the writer's session
was terminated after it staked its claim; the tool found the lock free and
released the claim; the writer reconnected, booked its entry, and the same key
then booked a second. The middleware now refuses to re-establish the
connection while it holds a claim lock, so the request fails instead of
continuing. Same harness after the change: the writer ends with a 500 and no
row, and the retry books exactly one.

**Correction (XR-02).** An earlier revision used "older than PHP
`max_execution_time` plus a margin" as the proof that the request had stopped.
That is withdrawn. On Linux, `max_execution_time` counts only the script's own
execution time. Time spent waiting on the database is not counted, so a request
blocked in a lock wait can outlive it indefinitely. Measured in
`tests/Concurrency/claim_release_race.php`: a writer paused in a lock wait
inside its business transaction; the claim released under it; the writer
committed; the same key then booked a second cash row. A timeout cannot replace
the lock.

The lock is session-level. A reconnect cannot carry the request past it any
more, but **a transaction-mode pooler (PgBouncer `pool_mode=transaction`)
would still void it**: it moves statements between server sessions without
any reconnect the application could see. If `DB_PERSISTENT`
is ever enabled, a PHP fatal error can leave the lock held until the worker
exits. The tool then refuses, which is safe but blocks reconciliation until the
worker recycles. Release check D1 verifies both before the code goes live
(`signature-migration-release-order.md` § Drift checks).

### 4.5 Decide

| Finding, gathered after 4.4 shows the lock free | Outcome |
|---|---|
| Exactly one matching record, created after `staked_at`, by that user, and it matches the request identified in 4.2 | `committed` |
| No matching record | `not-committed` |
| Anything else — several candidates, route unknown, payload unknown, partial rows | **Do nothing.** Leave the claim |

## 5. Execute (after approval)

```bash
# Dry run: shows the claim and the planned action, writes nothing.
runuser -u www-data -- php /var/www/jewelflow/artisan mobile:idempotency-claims \
  --reconcile=<id> --outcome=<committed|not-committed> \
  --evidence="<ledger row ids / query reference / ticket>" \
  --approved-by="<approver name>"

# Same command with --confirm to write.
```

| Outcome | What the tool does |
|---|---|
| `not-committed` | Deletes the claim. The same key may run again, once |
| `committed` | Keeps the key refused, but answers with a definite `409 idempotency_outcome_reconciled` instead of "in progress". **The pruner retains it:** it deletes only claims that recorded a 2xx, so the refusal cannot lapse with age. (An earlier revision let the next prune delete it, because the claim keeps its original `created_at`. Measured, and fixed in the same change.) |

The tool refuses: a missing outcome, evidence or approver; any outcome other
than those two; an id that is not an unresolved claim; a claim whose original
request still holds its lock; and a claim that another process resolved while
it ran (re-checked under a row lock). It acts on one claim per invocation.

**Retention, aligned.** "Unresolved" means one thing everywhere: a status
outside 100–599. The middleware refuses those, the tool lists and reconciles
them, and the pruner retains them — along with every other non-2xx claim.

## 6. Audit trail

The change and one `audit_logs` row are written in the same transaction.
`audit_logs` is append-only and hash-chained.

| Field | Content |
|---|---|
| `action` | `idempotency_claim_reconciled` |
| `model_type`, `model_id` | `IdempotencyKey`, the claim id |
| `shop_id` | the claim's shop |
| `before` | the full claim row as it was |
| `after` | the row as it now is, or `null` when released |
| `actor` | `via: cli`, the OS user who ran it, `approved_by` |
| `data` | `outcome`, `evidence` |

```sql
SELECT created_at, model_id, data, actor
FROM audit_logs
WHERE action = 'idempotency_claim_reconciled'
ORDER BY id;
```

## 7. Verify

* `committed`: a same-key retry returns `409 idempotency_outcome_reconciled`,
  and no second business record appears.
* `not-committed`: the claim is gone, and the operator's next submission books
  exactly one record.

## 8. Undoing a wrong decision

The two outcomes fail in opposite directions. That is why `not-committed` needs
the stronger evidence.

* **Wrongly `committed`** (it had not committed). Safe. The key stays refused;
  the shop enters the transaction afresh, which is a new request under a new
  key. Nothing is lost and nothing is doubled.
* **Wrongly `not-committed`** (it had committed). The released key can run
  again, and a retry books a duplicate. The tool cannot re-create the claim. The
  only remedy is a compensating entry through the service layer
  (CONSTITUTION.md §3). Check the evidence again before `--confirm`.

## 9. Limits

* Attribution depends on logs or the device, because the claim stores neither
  route nor payload — only their hash.
* The mobile app (`2cad553`) has no message for
  `idempotency_outcome_reconciled`. It falls into the generic 409 "Already
  changed" alert. That prevents re-entry but gives the wrong reason. A dedicated
  message is a mobile follow-up and has not been built.
* Tested on synthetic data only. Both outcomes are reached through the real
  cashbook route with injected failures. No server run.
