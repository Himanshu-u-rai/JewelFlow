# S3-07b — invoice payment idempotency: rollback constraints and retention

**Status: measurement and procedure only. Nothing here authorizes a release, a
rollback, a purge or any production write.**

Evidence: `docs/runbooks/payment-rollback-harness.sh` (run 2, exit 0, all checks
green). Run 1 of that harness is retained as a worked example of a false pass —
see "How run 1 lied" below.

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

## 4. The two ways to exclude incompatible writers

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

### Remaining untested failure boundary, stated explicitly

**The in-doubt commit.** If the database connection is lost during
`PDO::commit()`, PHP never learns whether the transaction committed. The row may
exist while the application believes it does not. Every test above assumes the
process learns the commit's outcome; none covers the case where it cannot. It is
not simulatable in-process — reproducing it needs the connection severed
mid-commit at the network or server level, which is outside what is authorized
here. Consequence if it occurs: the payment and claim are durable, the client
gets an error, and the retry replays correctly — the same shape as P-15 — so the
exposure is believed low, but that is reasoning, not a measurement, and is
recorded as such.

A second, narrower gap: a retry arriving after its claim row has been deleted
would be reprocessed as a first request. No code path deletes claims (§3, no
pruner exists), so this requires a manual deletion and is not reachable through
the application.

## 6. What is still not claimed

- **Mixed-version support is not claimed.** RB-4 measured a double charge with
  real overlap. The two versions cannot safely share traffic.
- Neither option has been executed anywhere. Both are local simulations and
  reviewable procedures.

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
