# Retail / Dhiran promotion recognition — implementation candidate

> Historical cloud candidate notes. The [local takeover handoff](../handoffs/jewelflows-takeover-local-review-2026-10-06.md) supersedes the verification/deployment status below. Keep the lifecycle limitations and migration-before-code requirement; do not treat cloud-only evidence as current local verification.

Status, 6 October 2026: implemented in the isolated Codex source workspace, **not release-ready**. No staging/production changes, remote pushes, live database changes or laptop branch changes were made.

## Scope and boundaries

- Owner-only introduction, once per environment + realm + shop + owner + target + stable campaign. The database claims exposure while rendering the dashboard; a lost response can lose an ad, never a business operation. `data-turbo-temporary` prevents restoring that card from a Turbo snapshot.
- `already_use` and `opt_out` are permanent advertising preferences, not purchase evidence. No daily/browser reset. Existing same-shop edition suppression remains.
- Settings → Product preferences in each product. Optional recognition uses a 40-character random code (200 bits), stored only as a hash in the database, expiring after 10 minutes. An authenticated owner supplies their own current password and consent at start, the other owner supplies their own password and consent at approval, then the first owner confirms the displayed business with their password and consent. No cross-host callback, return URL, phone/email lookup, password/session transfer or SSO.
- Either party can withdraw a pending approval or remove established recognition. Removal cancels already-approved outstanding requests for that pair. An unapproved request cannot establish a relationship without new target consent.
- Direct pairs only: no customer master, transitive group, account merge, edition grant or shared subscription. Recognition suppresses acquisition offers regardless of the other product's billing state. It does **not** claim that a purchase exists.
- Existing business-route realm, session and access middleware is unchanged. Metadata routes use their product's realm, tenant, auth, CSRF and throttling plus strict current active-user/shop-owner checks in the controller. They deliberately omit shop billing/access gates so expired or read-only owners can opt out or withdraw consent; this grants no business access or entitlement. Inactive users and wrong owner/realm/shop are still refused.
- Four additive promotion-only tables; migration down drops only those tables. All business and financial tables retain their schema and data.

## Lifecycle and failure behavior

Recognition use revalidates both exact user/shop/realm/owner-role bindings, user activity, shop existence and a keyed identity proof. Password changes alter that proof. The shared User/Role save trait permanently revokes links and consumes pending requests on user shop/role/realm/password/activity/employment changes and owner-role name/shop changes. It takes sorted transaction-scoped owner locks before the security SQL and commits the SQL and invalidation together, including quiet saves and disabled model events. Requests precede recognition rows in lock order. A stale reader only revokes the request version it checked; reconnecting cannot be clobbered by the old snapshot.

Supported application security writers use model saves: password changes/resets, administrative user updates, staff changes and onboarding bindings. Profile deactivation previously used bulk SQL; it now uses the same atomic save path and sets both activity/employment fields consistently. Existing-role name/shop and user realm reassignment have no current supported UI writers, but model saves are covered. Historical role/employment/realm backfills predate this migration. Raw SQL or mass query-builder ownership changes and external writers remain unsupported without explicit invalidation under the same owner locks/transaction. Current proofs are checked on lookup, but raw change-and-restore without invalidation is not a continuous-ownership guarantee.

Shop `deactivated_at`, `is_active` and `access_mode` are written by billing enforcement/recovery, administrative access holds and bulk suspend/unsuspend. They are not a distinct permanent-retirement signal and must not revoke ownership recognition. Existing access gates still apply; recognition does not grant access or entitlements. Phone/email edits and billing/access changes preserve consent. A true shop deletion removes its metadata through foreign keys; retiring an account without deletion must change its owner activity/binding through the supported save path or explicitly invalidate metadata. No new retirement workflow is introduced.

Dashboard lookup failures suppress the optional card and log the exception class, not identity/payment data. Preferences/verification do not silently report success on database failures. No caching of cross-product ownership. No subscription/transaction data is returned. Counterparty business names are displayed only for owner-approved requests/relationships.

Lifecycle invalidation is synchronous and atomic with security saves; a failure rolls back both changes and is not silently accepted. Run migration before enabling this code. For rollback, recover application code while retaining metadata; removing the four tables requires a separately reviewed destructive rollback. Merely setting `CROSS_PROMOTION_ENABLED=false` hides introductions, but does not disable preferences/recognition routes or lifecycle saves.

`ERP_REGISTER_URL` no longer defaults to production. Without an explicit override it derives the sibling Retail host only in local/production; testing/staging suppress it. An empty override disables it. Existing Dhiran URL derivation is preserved. Deployment-controlled explicit overrides must be reviewed per environment; the code cannot know whether an intentionally configured host points at the wrong environment.

Expired requests are unusable but retained. No background cleanup was added. Retention of promotion metadata is a separate operational setting; never prune unrelated idempotency or financial records for this feature.

## Verification evidence and limits

Executed here:

- `npm run build`: passed (Vite; not PHP asset freshness verification).
- `node tests/js/product-preferences.browser.cjs` with the workspace Chromium runner: 17 checks passed, zero page errors. Sixteen realm/width combinations (320, 360, 390, 430, 768, 820, 1024, 1440), labelled inputs, 44px controls, no horizontal overflow, native consent/password validation and local fixture form capture.
- `git diff --check`: passed.

Browser checks use **actual template HTML/CSS with deterministic substitutions**, expanding conditional states together. They do not compile Blade, execute controllers, test authentication, or exercise PostgreSQL. A fixture parsing/UTF-8 rendering issue was corrected before the final run. No real customer data or remote requests are used.

Written, **NOT RUN**: `ProductPromotionRecognitionTest` (16 tests), updated `CrossPromotionTest`, existing integration/regression suites, PHP syntax/Blade compilation and migration up/down. PHP, Composer and PostgreSQL are unavailable in this workspace. No security result is inferred from the browser fixture.

Additional **NOT RUN** gates: actual simultaneous tabs/approval/revocation workers; actual two-host browser cookies, logout/password resets after recognition; mobile/physical devices; Safari/Firefox; real application layouts with these templates; staging/production.

## Required local gate before staging

Use an isolated worktree and the existing `Tests\TestDatabaseGuard` without modification. It must resolve to the disposable local `jewelflow_testing` database, never the preview database or either server. Check resolved config before running tests; `RefreshDatabase` can delete data. No `.env` or credentials are included in this packet.

1. Confirm the real laptop HEAD, dirty files, configuration and installed dependencies. Review the supplied delta, migration and controller/service before applying. Do not merge/cherry-pick the reconstructed Codex history into the real repository.
2. Run PHP lint, Pint on changed PHP files, Blade compilation and `npm run build:verify` in that isolated worktree.
3. Run at least:

   ```bash
   php artisan test tests/Feature/ProductPromotionRecognitionTest.php tests/Feature/CrossPromotionTest.php tests/Feature/AdminMessagingTest.php tests/Feature/DhiranCrossPromoUrlTest.php
   ```

4. Run the existing realm/auth/session/password-reset, staff/role, shop retirement, subscription, security and dashboard suites. Run migration up/down/up against disposable synthetic data; compare business/financial rows before/after. Rollback only this migration in that disposable environment, not all existing migrations.
5. Run real worker overlap: same owner's two first dashboard requests → at most one card / one exposure; concurrent approvals with one code → exactly one accepted target; concurrent opposite-direction finalization → one pair; finalize versus cancellation/revocation → no usable old approval after revocation. Database uniqueness/row locks are implemented, but this is reasoning until measured.
6. Two product hosts, independent owner sessions: complete recognition; prove cross-shop customer routes still refuse both directions, each logout affects only its product, and password resets remain realm-scoped. Change owner shop/role and restore it; old link/request must stay revoked. Same phone without verification must never infer a relationship.
7. Click the actual Settings links and all form states in both application layouts at phone/desktop widths. Verify CSRF rejection, rate limiting, password-error UX, expiry, refresh, Turbo Back and cancelled/reused requests. A static fixture is not a substitute.

Stop on failures and repair locally. Only then prepare a staging candidate. Production remains outside this handoff's authorization.

## Provenance for Claude / local continuation

Worktree here: `ui-takeover-1d01ba4/work`, branch `feature/product-promotion-recognition`.
Feature delta base: `25fed63453d2f48edd30657f8bccf72ae9530e7d` (reconstructed source history, including earlier UI and landing work).
Original actual laptop export HEAD: `1d01ba440dd43ba9df15d888b2fdf34a23b00049`, branch `ui/navigation-batch-3`, with Claude's uncommitted work included in the export. The laptop is not changed by this work.

The packet contains a feature-only patch, before/after file hashes, changed source files and browser evidence. It does not contain `.env`, vendor/node_modules, database dumps, customer data or built production assets. Before applying, compare current local files with the manifest and use `git apply --check`; if files differ, reconcile the delta manually while preserving Claude's work. No force checkout, reset, automatic database command or deployment script is supplied.
