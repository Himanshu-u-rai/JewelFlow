# Mobile companion API and web parity: handoff for review (2026-10-31)

Paired mobile handoff: `docs/handoffs/2026-10-31-companion-batch-handoff.md` on branch
`integration/mobile-companion-20261017` of the private repository
Himanshu-u-rai/JewelflowMobileApp.

This repository is public. Operational evidence (server access, logs, local scripts)
is kept outside it.

## 0. Status

The work is written, tested on a local stack, and committed for review on branch
`feature/mobile-companion-api`.

- **Not deployed:** production and staging both run `d7dcfb0`.
- **No migration has run on a server.**
- The batch's staging step was not done: the owner redirected the batch into a
  screen-by-screen mobile redesign on 2026-10-17.

## 1. State (verified 2026-10-31)

| Item | Value |
|---|---|
| Review branch | `feature/mobile-companion-api` |
| Base | `d7dcfb0367fa6ffd3571502d25c44a7bdb36aedf` (= `main` = `origin/main`) |
| Head before this handoff commit | `09a8f84a56ef48d94b93372f20b71a021d9ff822` |
| Production code | `d7dcfb0` (read-only check). Trials off, trial length 7 days, maintenance off, 386 migrations |
| Staging code | `d7dcfb0` (read-only check). 388 migrations: the paused OTP schema is kept. Staging's own trial settings read "offered, 30 days" (staging only, not changed in this period) |
| Other branches | `feature/login-mobile-verification` (`48b0d5e`, OTP, paused, untouched). `fix/small-batch-20260914` is already contained in `main`. A leftover worktree branch `claude/practical-albattani-8ebe11` (`a8b92c3`, content deployed) |

## 2. Commits (review range `d7dcfb0..feature/mobile-companion-api`)

Compare: `https://github.com/Himanshu-u-rai/JewelFlow/compare/main...feature/mobile-companion-api`

**How the commits were made:** one continuous uncommitted working tree
(2026-10-17 to 31) was split after the fact by each file's last-modified time.

- A file changed in several phases appears only in its last phase.
- So intermediate commits are not separately tested; only the head was tested.

| Commit | Contents |
|---|---|
| `bb9e3fd` | Challan intake/correction rules moved onto `Repair`; photo controller/model tweaks |
| `b1626ad` | Mobile stock sub-category filter |
| `1828ea5` | Tag printing: label / jewellery-tag sizes; Print page accepts GET |
| `61f5daf` | Quick bill tests (empty bill, walk-in customer save) |
| `f939a52` | Cash book accounts (migration), quick bill totals (issued only), return review, mobile item photos and making modes, purity rates |
| `75c3744` | Customer history page + one spend rule, devices, suppliers, repair edit, scan sessions |
| `0a8639d` | Plain refusals, RBAC parity, route id guards, break-test fixes, bootstrap pricing readiness |
| `09a8f84` | Invoice due collection (web form + app endpoint through `InvoiceDueService`; EMI guard) |

## 3. Behaviour changes on the web (owner-visible)

| Change | Before → now | Roles | Files |
|---|---|---|---|
| Invoice due collection | No way to collect a due on an invoice → **Record payment** form on the invoice page for finalized bills with a due: split cash/UPI/bank/other, account for UPI/bank, starts empty | Needs `sales.create` | `InvoiceController::storePayment`, `InvoiceDueService`, `invoices/show.blade.php`, `app.css` |
| EMI bills | A direct payment, store credit or scheme redemption could lower the invoice due without lowering the plan's balance → refused while a plan is **active**. The page shows "Collect the due → Open EMI plan" instead (defaulted/completed plans do not block) | all | `InstallmentPlan::activeFor`, `StoreCreditController`, `SchemeService` |
| Cash book accounts | UPI/bank/wallet money had no account → every money row from sales, EMI, invoice payments and manual entries records its account; filter by account; day totals | cash book users | migration `2026_10_25_000001`, `CashBookController`, views |
| Quick bill list totals | Drafts counted in "Today" and "Outstanding" → issued bills only | quick bill users | `QuickBillController` (web + mobile) |
| Customer history | No dedicated page → `/customers/{id}/history` (invoices, quick bills, repairs); "total spent" counts finalized bills only; loyalty value uses the shop's point value | `customers.view` | `CustomerController::history`, `Invoice::spentByCustomer` |
| Return approval | Web review page adjusted; per-line refund adjustment shared with the app | `returns.approve` | `ReturnsController`, `ReturnService` |
| Tag printing | Fixed sizes; Print opened "Method Not Allowed" in a new tab → label or jewellery tag, preset and custom sizes; Print accepts GET | `inventory.view` | `TagPrintController`, `tags/*` |
| Refusal wording | Permission names / "ask the owner" in refusals → one plain "This isn't available on your account." for non-owners. The owner still sees plan and read-only reasons. The full-lockout screens (suspended, subscription ended) keep their reason for everyone, by owner decision | non-owners | `NotAvailable`, `AuthorizeWithReason`, `errors/403.blade.php`, middleware |
| Dashboard money | Revenue/profit/trend → behind `reports.view`; counts stay visible to all | all | `DashboardController`, `dashboard.blade.php` |
| Phone scanner page (POS) | Any barcode reached the cart → only in-stock items of the shop; repeat-scan window | `sales.pos` | `ScanEvent::stockProblem`, `ScanSessionController` |

## 4. API contract changes (`/api/mobile`, Sanctum bearer)

**New routes**

- `GET|POST|DELETE /customers/{customer}/photo`: `customers.view` to read, `customers.edit` to change.
- `PUT /repairs/{repair}` (`repairs.edit`): partial update; omitted fields are kept.
- `GET /repairs/{repair}/challan/pdf` (`repairs.view`).
- `GET /items/barcodes` (`can:lookup-item`).
- `PATCH /vendors/{vendor}/archive|reactivate` (`vendors.manage`).
- `GET /pricing/purity-rates` and `POST /pricing/purity-rates/{profile}/override` (`pricing.update`).
- `GET /v1/cashbook/accounts`.
- `POST /v1/returns/{returnOrder}/reject`.
- Web: `POST /invoices/{invoice}/payments` (`sales.create`) and `GET /customers/{customer}/history` (`customers.view`).

**Changed gates**

- Item lookup: `inventory.view` → `lookup-item` (inventory.view **or** sales.pos). Cost is shown to POS staff, as on the web POS. Vendor/karigar ids still need `inventory.view`.
- Returns: `sales.*` → `returns.view` / `view-return` / `returns.create`.
- `/dashboard` is no longer gated by `reports.view`; money fields need it.
- The sessions list needs `settings.view`.
- `auth/logout` and `auth/me` are named and reachable while the shop is closed.

**New response fields** (additive)

- `photo_version` on customers.
- A challan block on repairs.
- `emi_plan {id, status, remaining_amount}` on invoice detail.
- Capability flags, including `invoice_payment` (sales.create), `pricing`, `items_create`, `returns_approve` and others.
- `pricing.rates_set_today` in bootstrap.

**Status codes**

- Junk route ids return 404 (were 500).
- Out-of-range body ids/amounts return 422 (were 500).
- An invoice payment on a bill with an active EMI plan returns 422 `payments`.
- An exceeded due returns 422; the financial lock returns 422.

**Idempotency**

- The invoice payment endpoint keeps its durable claim, staked before business validation (R-C5).
- The business part moved into `InvoiceDueService::collect`, which runs inside the same transaction on the locked invoice.

**Cash rows**

The app's invoice payment now writes a cash book row for every money mode, not only cash.

## 5. Data, compatibility, rollback

- **Migration** `2026_10_25_000001`: adds nullable `cash_transactions.payment_method_id`
  (FK to `shop_payment_methods`, restrict on delete) and `payment_method_label_snapshot`,
  plus an index.
  - Additive.
  - Existing rows keep NULL (intentional, no backfill).
  - The restrict FK means a payment account referenced by cash rows cannot be hard-deleted. Review the delete path in `PaymentMethodController`.
  - Rolling back the code without the migration is safe. Rolling back the migration drops the recorded accounts.
- **Constitution:** `assertShopLockForDate` is called by the new due-collection path.
  - Ledger rows are created with `record()` (create-time `forceFill`).
  - No triggers were touched, disabled or added.
- **Released mobile app** (build 25, older JS): responses gain fields only.
  - Behaviour differences it will meet: returns need `returns.*`; EMI bills refuse direct payment (422); refusal texts are plainer; the dashboard answers 200 without the money figures for staff without `reports.view` (previously 403).
  - **This was not verified against the released binary.** Do that before a production API release.
- **Tenant scoping:** unchanged model scoping. A break sweep of 121 mobile routes covered: signed out, another shop, no permissions, junk ids and junk bodies. No cross-shop access, 0 crashes after fixes.
- **Untouched:** GST path, invoice numbering, pricing engine maths, OTP, subscription purchase flows, platform admin.

## 6. Tests

| Run | Revision | Result |
|---|---|---|
| Full suite `php8.2 artisan test` (local PostgreSQL test DB) | tree of `09a8f84` except one CSS `@media` block added afterwards | **3809 passed, 1 skipped, 0 failed** |
| `InvoiceDueCollectionTest` | same | 6/6 (seen failing before the fix) |
| Payment race harness `docs/runbooks/payment-race-harness.sh` (real concurrency, testing DB) | same | 5 scenarios, 0 failed checks, overlap measured in each |
| `RbacPermissionMatrixTest` + emulator role walks | tree of `0a8639d`-equivalent, 2026-10-30 | 45 permissions, 340 checks, 0 findings |
| `MobileApiBreakSweepTest` | same | 121 routes, 0 crashes |
| Browser check of the web form | local server, demo data | split payment recorded, two cash rows with account, EMI bill shows the plan link, no horizontal scroll at 375 px |

No staging or production test was run in this period.

## 7. Operations

- No staging or production deployment, migration, configuration, scheduler, DNS or
  permission change in this period. Production and staging were only read.
- No dependency changes in this repository. `public/build` is ignored; assets were built locally only.
- OTP stays paused and excluded; its staging schema is preserved.
- The SMTP credential rotation and permission work remain deferred and unexecuted.
- Synthetic records exist only in local databases.

## 8. Open items

1. Deploy to staging (needs approval), then test the app against it (see the mobile handoff).
2. Verify the released mobile build against this API before any production release.
3. Still open from 2026-10-25:
   - The web quick bill shows the "% of metal / per gram" making selector even when `FEATURE_MAKING_CHARGE_MODES` is off (the server saves it as fixed).
   - The manufacturer POS single-item sale drops the payment account (out of retail scope).
4. Pending owner decisions:
   - Should the app get EMI screens?
   - Should the item editor keep the stored price when weight, purity and charges are unchanged?
