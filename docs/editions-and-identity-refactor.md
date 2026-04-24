# Editions & Identity Refactor — Spec

Scope: make Retailer / Manufacturer / Dhiran first-class peer services (any combination allowed, Dhiran-only is valid), add admin and user-side edition management, and make the login mobile number safely changeable.

Status: **spec only — no code changes**. Reviewer must approve before Phase 1 begins.

---

## 1. Current state (as of 2026-04-19)

| Concept | Where | Shape |
|---|---|---|
| Shop edition | `shops.shop_type` | single string: `retailer` \| `manufacturer` |
| Dhiran enablement | `dhiran_settings.is_enabled` | boolean, per shop |
| Edition gate | `EnsureShopEdition` middleware + `ShopEdition` support class | equality check on `shop_type` |
| Edition sign-up toggle | `platform_settings.retailer_enabled` / `manufacturer_enabled` | per-edition flags |
| Login credential | `users.mobile_number` | not verified; no change flow |
| Contact mobile | `shops.owner_mobile` | separate display field, not synced |
| Email OTP infra | `EmailVerificationOtpController` + routes | already exists |

Files that read `shop_type` / `dhiran_enabled` (15): `app/Http/Controllers/Api/Mobile/BootstrapController.php`, `app/Data/Mobile/ShopSummaryData.php`, `app/Http/Controllers/Admin/DashboardController.php`, `app/Http/Controllers/Admin/ShopManagementController.php`, `app/Http/Controllers/Api/Mobile/AuthController.php`, `app/Http/Controllers/ShopController.php`, `app/Http/Controllers/SubscriptionController.php`, `app/Http/Middleware/EnsureShopEdition.php`, `app/Models/Shop.php`, `app/Services/BulkImportService.php`, `app/Services/ItemManufacturingService.php`, `app/Services/OnboardingResumeService.php`, `app/Services/TenantActivityMonitorService.php`, `app/Services/TenantHealthService.php`, `app/Support/ShopEdition.php`.

---

## 2. Target model

Editions are a **set** attached to a shop. Any non-empty subset of `{retailer, manufacturer, dhiran}` is valid.

### 2.1 Schema

New table `shop_editions`:

```
id            bigint PK
shop_id       bigint FK shops.id ON DELETE CASCADE
edition       varchar(16)  — 'retailer' | 'manufacturer' | 'dhiran'
activated_at  timestamp
activated_by  bigint FK users.id NULL (NULL if seeded/admin-granted)
deactivated_at timestamp NULL
deactivated_by bigint FK users.id NULL
deactivation_reason text NULL
created_at / updated_at

UNIQUE (shop_id, edition)       -- prevent duplicates
CHECK (edition IN ('retailer','manufacturer','dhiran'))
INDEX (shop_id)
```

Active editions = rows where `deactivated_at IS NULL`. History is preserved (audit).

### 2.2 Column lifecycle on `shops`

- `shop_type` — **keep for one release** as a read-replica (backfilled by trigger or app-layer on write). Deprecate in a follow-up migration once all reads are migrated to `shop_editions`.
- `dhiran_settings.is_enabled` — **keep as-is**; mirror into an `shop_editions` row where `edition='dhiran'`. Source of truth shifts to `shop_editions`; `is_enabled` becomes a derived convenience.

Rationale: big-bang column drop on a live DB is unsafe. Dual-write for one release → verify → drop.

### 2.3 Seeding migration

For every existing row in `shops`:
- Insert `(shop_id, shop_type, activated_at=shops.created_at, activated_by=NULL)` into `shop_editions`.
- If `dhiran_settings.is_enabled = true` for that shop, also insert `(shop_id, 'dhiran', ...)`.

Idempotent (uses INSERT … ON CONFLICT DO NOTHING on `(shop_id, edition)`).

### 2.4 Model layer

`Shop` model:

```
public function editions(): HasMany  -- all rows incl. deactivated
public function activeEditions(): HasMany  -- deactivated_at IS NULL

public function hasEdition(string $edition): bool
public function hasAnyEdition(string ...$editions): bool
public function hasAllEditions(string ...$editions): bool
public function editionList(): array   -- ['retailer','dhiran']
```

Backward-compat: `isRetailer()` / `isManufacturer()` now delegate to `hasEdition(...)`.

`ShopEdition` support class gets `activeFor(Shop $shop): array`, `addTo(Shop, string, ?User): void`, `removeFrom(Shop, string, ?User, string $reason): void`.

### 2.5 Middleware

`EnsureShopEdition` already accepts variadic editions: change the inner check from `in_array($shopType, $editions)` to `$shop->hasAnyEdition(...$editions)`.

`EnsureDhiranEnabled`: delegate to `$shop->hasEdition('dhiran')` instead of reading `dhiran_settings.is_enabled`. Keep `dhiran_settings` for per-shop configuration (interest rates, LTV, etc.) — that table is not about enablement any more.

---

## 3. UX: shop creation, upgrade, routing

### 3.1 Shop creation (onboarding)

`/shops/choose-type` becomes a **multi-select**. Tiles for Retailer, Manufacturer, Dhiran. User must pick at least one. `PlatformSetting::retailerEnabled()` / `manufacturerEnabled()` gain a `dhiranEnabled()` sibling to gate tiles.

Plan picker `/subscription/plans` receives chosen editions (session or query). Plans are filtered: show plans that match the exact edition set **or** combo plans that cover a superset.

### 3.2 Upgrade / add-on (existing shops)

New page `/settings/services` (owner-only): shows active editions as cards with "Active" state, shows available editions as cards with "Add [edition]" button. Clicking "Add" → plan/price preview → payment → on webhook success, insert `shop_editions` row.

Remove edition: soft-deactivates (sets `deactivated_at`, `deactivated_by`, `deactivation_reason`). Blocked if data still present (e.g. can't drop `dhiran` while any loan is active; can't drop `retailer` while any POS invoice exists in last 90 days). Shows guard message explaining what to clear first.

Never allow removing the **last** edition (would orphan the shop). Must cancel subscription instead.

### 3.3 Routing & layout switch

Post-login routing (`AuthenticatedSessionController::store`):

- If shop has `{dhiran}` only → redirect to `/dhiran` (Dhiran dashboard).
- If shop has retailer or manufacturer (with or without dhiran) → redirect to `/dashboard`.

Layout selection:

- Routes under `/dhiran/*` use `layouts/dhiran.blade.php` (new file, Dhiran-only sidebar, Dhiran branding header).
- All other authenticated routes use existing `layouts/app.blade.php` but the sidebar is driven by `$shop->editionList()`:
  - Retailer-only → retailer sidebar sections.
  - Manufacturer-only → manufacturer sidebar sections.
  - Both → merged.
  - If `dhiran` is also in the set → "Dhiran" app-switcher tile at top of sidebar (full-page nav to `/dhiran`).
  - If Dhiran is the only edition → JewelFlow chrome is never rendered.

Subdomain `dhiran.jewelflow.com` (later phase): domain-routed group serving only `/dhiran/*` routes, strips JewelFlow chrome unconditionally. No code changes in controllers — only a route-file domain constraint and cookie-domain config (`.jewelflow.com`). Phase-7 scope.

---

## 4. Admin edition control

New admin page: **Admin → Shops → [shop] → Editions**.

- Lists active + historical editions.
- "Grant edition" button — opens modal with edition dropdown + required reason textarea. Submits → inserts `shop_editions` row with `activated_by = admin_id`, reason stored in audit log (not on the row).
- "Revoke edition" button — opens modal with reason textarea. Same guards as user-side removal. Logs to audit.
- Every grant/revoke writes a `platform_audit_logs` entry: `admin_id`, `action='edition.grant'|'edition.revoke'`, `target_shop_id`, `edition`, `reason`, `ip`, `timestamp`.

Gated by permission `shops.editions.manage` (new) — superadmin role gets it by default.

---

## 5. Mobile number change (login credential)

### 5.1 User-side flow

Route: `POST /profile/mobile/change-request`, `POST /profile/mobile/change-verify`.

**Preconditions**:
- User must be authenticated.
- User must have `email` set **and** `email_verified_at` not null. If not, block with "verify your email first" → link to existing email-OTP flow.
- New mobile must be 10 digits, not equal to current, not already owned by another user.
- Rate limit: 3 change-requests per user per hour; 5 verify-attempts per request.

**Step 1 — Request**:
1. POST `{ new_mobile_number, current_password }`.
2. Validate password (re-auth). On fail → throttle + generic error.
3. Check new mobile uniqueness.
4. Generate 6-digit numeric OTP, hash it with `Hash::make`, store in new `mobile_change_requests` table:
   ```
   id, user_id, new_mobile_number, otp_hash, expires_at (now + 10 min),
   attempts (int, default 0), verified_at NULL, consumed_at NULL,
   requested_ip, created_at
   ```
5. Send OTP to `$user->email` (NOT to new_mobile — new_mobile isn't trusted yet).
6. Log `profile.mobile_change.requested` to audit log.
7. Return success response. Never echo the OTP back.

**Step 2 — Verify**:
1. POST `{ otp }`.
2. Look up most recent non-consumed request for this user where `expires_at > now()`.
3. If no such request → 422 "Request a new OTP".
4. Increment `attempts`. If `attempts > 5` → mark consumed, 429 "Too many tries".
5. `Hash::check($otp, $request->otp_hash)`. If false → 422 "Invalid OTP".
6. In a DB transaction:
   - Set `users.mobile_number = request.new_mobile_number`.
   - Mark request `verified_at = now()`, `consumed_at = now()`.
   - Invalidate all other sessions for this user (`Auth::logoutOtherDevices`).
   - Rotate `remember_token`.
   - Log `profile.mobile_change.completed` to audit log.
7. Send confirmation email to `$user->email` ("Your login mobile was changed to XXXXXX1234 at [time] from IP [ip]. If this wasn't you, contact support.").

### 5.2 Admin-side flow

Route: `POST /admin/users/{user}/mobile` (admin only).

1. Admin enters new mobile + mandatory reason textarea.
2. No OTP (admin is the authority — reason is the audit).
3. Validation: mobile uniqueness, digit format.
4. Same DB transaction as user-side step 2 minus session invalidation (admin can choose via checkbox "sign user out of all devices" — default on).
5. Send confirmation email to affected user.
6. Log `admin.user.mobile_change` to `platform_audit_logs` with `admin_id`, `target_user_id`, `old_mobile_masked`, `new_mobile_masked`, `reason`, `ip`.

### 5.3 Shop owner_mobile sync

Separate from login mobile. On user profile mobile change, prompt (not force): "Also update contact mobile on shop profile?" — checkbox, default on for owner role. For non-owner roles, shop owner_mobile isn't theirs to change.

Rename the shop-settings field label from "Owner Mobile" to "Shop Contact Mobile" to kill the confusion. Add tooltip explaining it's the shop's public contact, not the login credential.

### 5.4 Threat model

| Attack | Mitigation |
|---|---|
| Attacker steals session, changes victim's mobile | Step 1 requires current password re-auth. |
| Attacker knows victim's email, triggers change | Step 1 requires session + password. |
| Attacker brute-forces OTP | 6-digit + 5-attempt cap + 10-min expiry. |
| Email account compromised | Confirmation email to old + new email (if user has two) + one-click "This wasn't me" revert link within 24h that restores old mobile. |
| Mobile collision (another user claims it first) | UNIQUE on `users.mobile_number` + validation in step 1 + re-check in step 2 before commit. |
| Admin abuse | Mandatory reason + audit log + mandatory user email notification. |
| Session fixation | `Auth::logoutOtherDevices` + `remember_token` rotation. |

---

## 6. Phased delivery plan

Each phase is independently shippable and reverts cleanly.

| Phase | Scope | Irreversibility | Estimated effort |
|---|---|---|---|
| 1 | `shop_editions` table + seeding migration + `ShopEdition` helper. Keep `shop_type` as dual-write. | Reversible (drop table) | 0.5 day |
| 2 | Middleware + model accessors read new source. 15 call-sites switched. Tests added. | Reversible (revert reads) | 0.5 day |
| 3 | Shop-creation multi-select + `PlatformSetting::dhiranEnabled()`. | Reversible | 0.5 day |
| 4 | Admin edition grant/revoke UI + audit log. | Reversible | 0.5 day |
| 5 | User-side `/settings/services` upgrade + billing integration. | Reversible | 1 day |
| 6 | Mobile-change OTP flow (user + admin paths). | Reversible | 1 day |
| 7 | Routing/layout split + Dhiran-only dashboard + subdomain support. | Config change | 0.5–1 day |
| 8 | Deprecation follow-up: drop `shops.shop_type`, drop `dhiran_settings.is_enabled`. | **One-way** | 0.25 day |

Total: ~5 days of focused work. Phase 8 waits a release cycle after Phase 2 has been live and verified.

---

## 7. Open questions for reviewer

1. Billing: should adding Dhiran to an existing retailer/manufacturer subscription create a **new** subscription row or a line-item on the existing one? Current `ShopSubscription` model is one-active-per-shop — this needs confirmation before Phase 5.
2. Mobile change OTP delivery: email-only OK, or also SMS to current mobile as secondary channel? (Email-only is simpler and works for Dhiran-only customers who may not have reliable SMS.)
3. Subdomain: do we have DNS / TLS ready for `dhiran.jewelflow.com`, or should Phase 7 stop at path-based split (`/dhiran` with its own layout) and subdomain ship separately?
4. Downgrade guards: is 90 days the right window for "recent retailer activity"? 30? None?
5. "This wasn't me" revert link: 24h OK, or 48h for older demographic users?

---

## 8. Non-goals

- Per-edition staff permission scoping (existing `role` + `permissions` cover this).
- Separating tenant data by edition (all still live in the same shop tenant).
- Extracting Dhiran into its own Laravel repo (future work, post-demand).
