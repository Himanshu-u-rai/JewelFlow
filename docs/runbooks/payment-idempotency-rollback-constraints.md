# S3-07b — invoice payment idempotency: rollback constraints and retention

**Status: measurement and procedure only. Nothing here authorizes a release, a
rollback, a purge or any production write.**

Evidence: `docs/runbooks/payment-rollback-harness.sh` (run 2, exit 0, all checks
green). Run 1 of that harness is **invalidated** — see §7 for exactly what it
covered and what it did not touch, and "How run 1 lied" for the mechanism.

The baseline checkout `/tmp/jf-oldctl` is retained for source review and
targeted re-runs. Its `vendor/` is a hardlink copy sharing inodes with the audit
worktree — **do not modify files under it**, as edits would be seen by both
trees.

Revisions under test, resolved from Git rather than assumed:

| Revision | Content | Deployed? |
|---|---|---|
| `70b7116` | cache-only, **no** S3-07 cache-hit authorization guard | yes — contained in the reported baseline `018b3d8` |
| `0296431` | cache-only **plus** the S3-07 guard | no — audit branch only |
| `2dd0875` | first durable-claim revision | no |
| `7d20e08` | current HEAD: durable claim staked before validation | no |

`git merge-base --is-ancestor` confirms only `70b7116` is in `018b3d8`. A real
rollback lands on `70b7116`, so that is what the harness drives.

## 1. Result: rolling back to `70b7116` is UNSAFE while retries can still arrive

| Scenario | Cache entry | Baseline response | Payments | Cash txns | Verdict |
|---|---|---|---|---|---|
| RB-1 partial payment | removed | **201 (new payment)** | **2** | **2** | double charge |
| RB-2 full settlement | removed | 422 "already fully paid" | 1 | 1 | protected, but incidentally |
| RB-3 partial payment | intact | 200 replay | 1 | 1 | safe, cache did it |
| RB-4 mixed version, 54.8 ms measured overlap | — | 201 **and** 201 | **2** | — | double charge |
| RB-5 legacy replay | rewritten to 3 s TTL | 200 replay | 1 | — | TTL not refreshed |

The mechanism is not subtle: `70b7116` consults `Cache::get` and nothing else.
The `invoice_payment_claims` row is present, correct, and invisible to it.

**RB-2 is not a safety property.** The retry was refused by the baseline
controller's own balance guard — outstanding was already zero, so a second
payment would have overpaid. Protection therefore exists only for the exact
subset of retries that would overpay. RB-1 is the same code path with a partial
payment and it charges twice.

**RB-4 is deliberately not asserted.** Whether the old request reads the cache
before the new request's `Cache::put` lands is a race. A single-payment outcome
there would be a timing accident, and asserting it would record an accident as a
guarantee. The harness prints the count and classifies it instead.

## 2. What already makes a rollback survivable, and exactly how far it goes

HEAD still writes the legacy cache entry, same key shape, same 24 h TTL
(`InvoiceController::storePayment`, `Cache::put($cacheKey, $response,
now()->addHours(24))`). That is why RB-3 is clean: the baseline controller reads
back something HEAD wrote. No new compatibility code is required — it is already
there.

What it does not cover is cache loss. The cache is best-effort by construction:
a flush, an eviction, a restarted cache container, a changed `CACHE_STORE`, or
simple TTL expiry each turn RB-3 into RB-1.

## 3. Retention — measured, not assumed

**`invoice_payment_claims` has no pruner.** `routes/console.php:147` schedules
`mobile:prune-idempotency-keys` daily, and that command
(`app/Console/Commands/PruneIdempotencyKeys.php`, `--hours=48`) targets the
`idempotency_keys` table used by the `EnsureIdempotency` middleware. Nothing
prunes `invoice_payment_claims`. Retention is currently **unbounded**, and the
table grows one row per keyed payment. That is stated as a fact about the system,
not a recommendation: adding a pruner would delete the exact rows that make a
retry safe, and is out of scope here.

**The legacy cache window is per-key, and it is not "24 hours after
deployment".** Two findings support that:

- A legacy *replay* returns before reaching `Cache::put`, so it cannot refresh
  the TTL. Verified by control-flow reading in both revisions and behaviourally
  by RB-5: an entry rewritten to a 3-second TTL, replayed, was gone 4 seconds
  later.
- A cache-only writer *can* extend the window, but only for a key it writes
  fresh. Processing a new key sets that key's own 24 h entry. It does not touch
  any other key.

So the bound is: **24 hours after the last successful write for that specific
key, by either version.** For a key last written before a rollback, the clock
started at that write, not at the deploy.

After the window closes, a retry carrying that key reaches `70b7116` as a first
request. If it would not overpay, it is charged again.

## 4. The constraint that actually has to hold

**Payment traffic must be served only by code that respects durable claims — or
by an explicitly reviewed revision established as compatible.** Everything below
is a way of satisfying that constraint or of narrowing the window in which it is
violated; none of it replaces it.

Stated as a release/recovery rule:

1. No revision that predates `invoice_payment_claims` may take payment traffic
   while retries for claimed keys can still arrive. `70b7116` is such a revision
   and RB-1 measures the consequence.
2. No period in which both claim-aware and cache-only writers serve the same
   route. RB-4 double-charges at 54.8 ms of overlap, which is shorter than any
   rolling restart.
3. If a revision must be rolled back to, it has to be reviewed against this
   constraint first and named. `0296431` is the only cache-only candidate that
   at least carries the S3-07 authorization guard — but it is **still cache-only
   and still double-charges on cache loss**, so it does not satisfy the
   constraint either. It has never been deployed.

**No compatibility layer is proposed.** HEAD already writes the legacy cache
entry with the original key shape and TTL, which is the whole of the backward
compatibility that a concrete release requirement has so far been shown to need.
Building anything broader — a shim, a dual-write adapter, a middleware rewrite —
would be speculative until a specific release requirement demands it.

## 4a. The two ways to narrow the window, neither of them a substitute

Both are procedures for review. Neither is executed here.

### Option A — constrain the rollback (no code)

A rollback to `70b7116` is only safe when all of the following hold:

1. The shared cache survives the rollback intact. Not flushed, not restarted,
   not repointed, `CACHE_STORE` unchanged, `APP_NAME` unchanged (the store
   prefix is `Str::slug(APP_NAME).'-cache-'`).
2. No key written by HEAD is older than 24 hours **and** still retriable by a
   client. The retry window the mobile client actually uses bounds this; it is
   not bounded by the deploy time.
3. `invoice_payment_claims` is left in place. Dropping the table with the code
   destroys the only durable record of which keys were already served, and
   removes any chance of Option B.
4. No mixed-version period. RB-4 double-charges with 54.8 ms of overlap, which
   is less than a single rolling-restart window.

Condition 4 is the one that ordinarily fails. Any rolling deploy or partial
rollback puts both versions behind the same load balancer.

### Option B — rehydrate the legacy cache from the durable claims first

> **CLASSIFICATION: TEMPORARY COMPATIBILITY MEASURE. NOT A SAFE ROLLBACK
> PROCEDURE ON ITS OWN.** It demonstrates replay *while a rehydrated cache entry
> exists*. It establishes nothing about the period after that entry is evicted,
> flushed or expires — and the existing evidence already shows what happens
> then: **RB-1 is exactly the post-cache-loss state, and it double-charges.**
> Rehydration moves the exposure in time; it does not remove it.
>
> **These cache writes are NOT APPROVED for any environment.** They are written
> down so they can be reviewed, not so they can be run.

The claims table already holds everything the legacy cache entry needs: the
invoice id, the key, and `response_body`, which is byte-for-byte the array
`70b7116` would have cached. Nothing has to be invented or reconstructed.

Run **before** the old code starts taking traffic:

```
php artisan tinker --execute="
DB::table('invoice_payment_claims')
    ->whereNotNull('response_body')
    ->where('created_at', '>', now()->subHours(24))
    ->orderBy('id')
    ->chunk(500, function (\$rows) {
        foreach (\$rows as \$r) {
            Cache::put(
                \"invoice_payment_idempotency:{\$r->invoice_id}:{\$r->key}\",
                json_decode(\$r->response_body, true),
                now()->addHours(24)
            );
        }
    });
"
```

**Verified locally, on the exact scenario it is meant to fix.** Invoice 95,
key `optb`: HEAD recorded payment 47 (201); the cache entry was removed and
confirmed `gone`; the snippet above was run verbatim and reported the entry
`present`; the same authorized retry was then sent to `70b7116`, which answered
**200 with payment 47** and **no** `X-Idempotent-Replay` header — a genuine
legacy cache replay, not HEAD in disguise. `payment_count` stayed 1,
`cash_txn_count` 1, `claim_count` 1. That is RB-1 turned into RB-3.

Properties that make this the smallest compatible option:

- **Reads claims, writes only cache.** No row in `invoice_payment_claims` is
  created, updated or deleted. No purge, no backfill, no invented payload hash.
- **No new production code.** No command class, no middleware change, nothing to
  own or test afterwards.
- **Idempotent.** Re-running overwrites entries with identical values.
- **It is an explicit administrative TTL refresh**, and should be described that
  way rather than as a fix. It buys one fresh 24 h window for the keys it covers
  and nothing beyond that.
- **The 24 h filter is the honest scope.** Older claims correspond to keys whose
  legacy window already closed; rehydrating them would silently resurrect a
  window the client no longer believes in.

Option B narrows the exposure to keys created during the rollback itself. It
does **not** rescue condition 4 — a mixed-version period still double-charges on
any key first seen after the rehydration ran.

#### What rehydration does and does not check — answered directly

| Question | Answer |
|---|---|
| Does it renew the key's lifetime? | **Yes.** `now()->addHours(24)` writes a **new** 24 h window per key, decoupled from the original payment time. A key written 23 h ago gets 24 h more. This is an administrative extension of the compatibility window, and it is the only mechanism found that extends a window without a fresh payment — a legacy replay does not (RB-5). |
| Does it verify the claim is complete? | **No.** The only filter is `whereNotNull('response_body')`. It does not check `response_status`, does not confirm a matching `invoice_payments` row exists, and does not validate the JSON decodes to the expected receipt shape. |
| Does it verify invoice ownership? | **No.** It reads `invoice_payment_claims` through the `DB` facade, so `BelongsToShop` never applies. It writes every shop's claims into the cache in one pass. The cache key contains the invoice id, so entries do not collide across shops — but no ownership assertion is performed, and none of the tenant checks that guard the request path run here. |
| Does it verify receipt identity? | **No.** It copies `response_body` verbatim. It does not confirm the body's `payment.id` matches a live payment, nor that the stored `request_hash` corresponds to anything. |
| Does the replay it enables re-authorize? | **No.** The revision it feeds, `70b7116`, returns `Cache::get` with **no** authorization check on the cache-hit path — that is finding S3-07, whose guard (`0296431`) has never been deployed. A rehydrated entry is therefore replayable by any caller who reaches the route with that key. |

Those five answers are why the snippet is classified as a stopgap and left
unapproved. Making it verify any of the above would mean writing and testing new
code, which is a fix, not a recovery step — and the fix already exists in HEAD.

## 5. Failure boundaries — what is proven, and what is not

`tests/Feature/Security/InvoicePaymentRetryRepairTest.php`, 250 tests / 905
assertions green across `tests/Feature/Security` + `tests/Feature/Mobile`.

| Boundary | Test | Result |
|---|---|---|
| Failure **inside** the transaction | P-05 | 0 payments **and** 0 claims; the key is not burned and works afterwards |
| Failure **after commit**, before the client is answered | **P-15 (new)** | payment **and** claim both durable; the retry replays the *same payment id* |
| Cache entry lost after a successful commit | P-06 | claim carries the retry, 1 payment |
| Unrelated unique violation inside the transaction | P-14 | error surfaces; not dressed up as a replay; 0 payments |

**P-06 was mislabelled.** It carried `[INJECTED FAILURE — after payment commit,
before response/cache storage]`, but its mechanism is `Cache::forget` after a
request that succeeded — nothing is injected. The label is corrected to
`[SIMULATED CACHE LOSS]`, and P-15 was written to actually occupy the boundary
P-06 was credited with.

**P-15 was verified capable of failing.** It passed on first run, which proves
nothing on its own, so the claim lookup in `findPaymentClaim` was temporarily
stubbed to return `null`. The test went red at `assertSuccessful()` on the
retry, with the four preceding assertions still passing — confirming that the
commit-durability assertions and the replay assertion are testing different
things, and that the replay assertion is the one carrying the lookup. The
mutation was reverted and `git diff` confirms the controller is unmodified.

### NOT RUN — the in-doubt commit

**Status: NOT RUN. Not covered by P-15 and not claimed to be.**

P-15 injects a failure *after* `PDO::commit()` has returned successfully. The
in-doubt case is different in kind: the connection is lost **during** the
commit, so the process never learns the outcome. The row may exist while the
application believes it does not.

Reproducing it requires severing the connection mid-commit at the network or
server level, which is outside what is authorized here. No test approximates it,
and none of the tests above should be read as covering it.

If it occurs, the shape is *expected* to match P-15 — payment and claim durable,
client sees an error, retry replays. That is an inference from the transaction
boundary, **not a measurement**, and it is not counted as coverage.

A second, narrower gap: a retry arriving after its claim row has been deleted
would be reprocessed as a first request. No code path deletes claims (§3, no
pruner exists), so this requires a manual deletion and is not reachable through
the application.

## 6. What is still not claimed

- **Mixed-version support is not claimed.** RB-4 measured a double charge with
  real overlap. The two versions cannot safely share traffic.
- **Option B is not a safe rollback procedure.** It is a temporary compatibility
  measure whose protection lasts exactly as long as the rehydrated cache entry.
  After eviction or expiry the system is back in RB-1.
- **No rollback target has been established as safe.** `70b7116` double-charges
  (RB-1); `0296431` is also cache-only and would too, and has never been
  deployed.
- Nothing here has been executed outside `jewelflow_testing`. Both options are
  local simulations and reviewable procedures; the cache writes in Option B are
  **unapproved**.
- **The in-doubt commit is NOT RUN**, not "low risk" — see §5.

## 7. Exactly what the vendor symlink invalidated — and what it did not

The blast radius is one run of one harness. It is recorded precisely because
"some earlier results were wrong" is the kind of statement that quietly
contaminates valid evidence.

### INVALIDATED — discard

| Harness | Scenarios | Run | Intended revisions | Revision ACTUALLY executed | Reported result |
|---|---|---|---|---|---|
| `docs/runbooks/payment-rollback-harness.sh` | RB-1 … RB-5 | run 1 (`/tmp/rollback-run1.txt`) | port 8123 = HEAD `7d20e08`, port 8124 = baseline `70b7116` | **`7d20e08` on BOTH ports.** `/tmp/jf-oldctl/vendor` was a symlink, so composer's `$baseDir` resolved to the audit worktree and `App\` loaded HEAD's controller | `5 scenarios, 0 failed checks` — **every check false**; the "baseline" 200s were HEAD's own claim replays |

Superseded by run 2 (`/tmp/rollback-run2.txt`), with `vendor` hardlink-copied
and RB-0 proving the port separation. Results in §1.

### NOT AFFECTED — evidence stands

| Harness / suite | Scenarios | Why the symlink is irrelevant |
|---|---|---|
| `docs/runbooks/payment-race-harness.sh` | **R-C1 … R-C5** | A *different harness answering a different question*: concurrency against ONE revision, not version compatibility. It uses a single server on port 8123 started from the audit worktree, whose `vendor/` is the real directory. It never references `/tmp/jf-oldctl`, never starts a second server, and has no `OLD_DIR`. Runtime revision established by the invoking worktree: `7d20e08` for the final green run. |
| `tests/Feature/Security`, `tests/Feature/Mobile` | P-01 … P-15 | PHPUnit runs in the audit worktree against its own real `vendor/`. `/tmp/jf-oldctl` is not on any code path. |

**R-C and RB are not interchangeable and must not be merged in any summary.**
R-C found and fixed two real defects (the race-loss replay skipping the payload
hash comparison, and the claim INSERT sitting after the balance guard). Those
findings are independent of the rollback question and survive intact.

## How run 1 lied

The first run of the harness reported `failed checks: 0` and was entirely false.
`/tmp/jf-oldctl/vendor` had been symlinked to the audit worktree to save 120 MB.
Composer derives its PSR-4 base from `$vendorDir = dirname(__DIR__)` inside
`vendor/composer/autoload_psr4.php`, and PHP resolves `__DIR__` through the
symlink to the real path — so `$baseDir` became the new tree and `App\` loaded
HEAD's controller. Port 8124 was running new code.

It was caught because the "baseline" 200 carried `X-Idempotent-Replay: true`, a
header only HEAD emits, and because a baseline that truly saw a cache miss must
have recorded a second payment and had not.

Three guards were added so the same lie cannot be told twice:

- `RB-0`, a version probe that fails the run before any scenario executes. It
  carries its own positive control: the same probe must detect the header on the
  new port in the same run, so "absent" on the old port means something.
- A fatal check that `${OLD_DIR}/vendor` is not a symlink, and that the checkout
  is at `70b7116`.
- `forget_legacy` now asserts the entry is gone instead of discarding output.
  The premise of every rollback scenario is a cache miss; it is no longer taken
  on trust.
