# Known Pre-Existing Test Debt (tracked follow-up)

**Status:** documented, NOT blocking the subscription/multi-product release. Everything here **pre-dates** that work and fails identically on the pre-merge `main` ancestry (commit `6c41b22`). Recorded so it is never silently forgotten.

These are deferred deliberately because fixing them means editing **frozen production code** or **fixing pre-existing test flakiness** — both outside the scope of the subscription release, and neither caused by it. Pick them up as their own change.

---

## 1. Architectural-guard tests flag pre-existing production debt

### 1a. Inline fine-weight math — `FineWeightAuthorityExclusivityTest::no_inline_fine_weight_purity_math`
The guard greps the codebase for fine-weight derivation done inline instead of through `MetalRegistry::fineWeight()` / `fineWeightMultiplier()`. It flags two pre-existing sites:
- `app/Http/Controllers/Api/Mobile/PosController.php:622` — `$fineWeight = $item ? round((float) $item->net_metal_weight * ((float) $item->purity / 24), 4) : 0.0;`
- `app/Services/QuickBillService.php:288` — a comment describing the same purity factor.

**Verified pre-existing:** the offending line at `PosController.php:622` exists byte-for-byte at base `6c41b22`; neither file was touched by the subscription work.

**Fix path (when scoped):** route both sites through `MetalRegistry` so the authority is the single source of fine-weight math. This is a real (small) refactor of mobile POS + quick-bill pricing — must be done carefully with its own tests, because it touches money math.

### 1b. ID-format guards — `BusinessIdentifierArchitectureTest`
Failing assertions: `'INV-1001' ends with '-0000000001'` and a blade-hash-id pattern check. These assert a business-identifier format/architecture that the current code does not satisfy.

**Verified pre-existing:** fails on base `6c41b22`.

**Fix path:** reconcile the identifier-generation format with what the architecture test expects, OR update the test if the format intentionally changed. Needs a decision on which is authoritative (the generator or the test).

---

## 2. Flaky DB-deadlock import tests — `BulkImportSafetyTest`

Several tests fail intermittently with `SQLSTATE[40P01]: Deadlock detected`. The full-suite failure count was **non-deterministic** (observed 25 then 32 across two identical runs), which is the signature of test-isolation/locking flakiness, not a logic failure. Some classes (e.g. `InstallmentDefaultCloseTest`) pass in isolation but failed inside the full suite — classic cross-test state/lock contention.

**Verified pre-existing:** the deadlocks reproduce on base `6c41b22`.

**Fix path (when scoped):** investigate the lock ordering in the bulk-import transaction path under `RefreshDatabase` + the `database` session driver; likely a shared-row lock acquired in inconsistent order across concurrent/sequential test transactions. Options: serialize these tests, use `DatabaseTransactions` consistently, or fix the lock acquisition order in the import service. Do NOT change the import service's production locking purely to satisfy tests without confirming the production path is safe.

---

## What was fixed (for context)

The subscription release's `test(env)` commit recovered ~89 pre-existing failures (114 → ~25) by correcting the **test environment only** (no production code):
- test runner was reading the production `.env` (`PLATFORM_ENFORCE_SUBSCRIPTIONS` effectively on) → `phpunit.xml` now forces it off;
- `CreatesTestTenant` created an owner role with no permissions (the app's `Gate::before` has no owner short-circuit) → the trait now grants the real owner permission set;
- several web-login tests didn't disable CSRF (`419`) → they now do.

The remaining items above are the residue: genuine pre-existing app/test debt unrelated to subscriptions. The subscription/multi-product/edition surface itself is fully green (55 tests across MultiProductSubscriptionTest, SubscriptionLifecycleTest, ServicesBuyNowTest).
