# JewelFlow

JewelFlow is an end-to-end jewelry business platform with:

- A multi-tenant web SaaS for daily operations
- A companion mobile app for shop-floor workflows
- Public catalog sharing for customer engagement

This README is written so a new founder, ops person, developer, or implementation partner can quickly understand what the product does and how it is deployed.

Last updated: April 2026

---

## 1) What the Product Is

JewelFlow helps jewelry businesses run sales, inventory, customer, and service workflows from one system.

It supports two business editions:

- Retailer edition: counter sales, customer programs, vendors, schemes, loyalty, catalog sharing.
- Manufacturer edition: lot-based gold inventory and manufacturing-oriented accounting flows.

JewelFlow is subscription-driven. Shops onboard through a plan and payment flow, then use the product through the web dashboard and mobile app.

---

## 2) Products in This Workspace

| Folder | Product | Purpose |
|---|---|---|
| `jewelflow/` | Web SaaS + API (Laravel) | Core platform, tenant operations, admin control tower, billing, public catalog routes |
| `jewelflowMobileApp/` | Mobile companion app (Expo React Native) | Barcode workflows, quick billing, stock/repair/customer actions, in-app catalog browsing/sharing |

Important: the mobile app consumes backend APIs from the Laravel app. They are two deployable artifacts, but one product system.

---

## 3) Who Uses JewelFlow

- Owners and managers: business controls, reporting, subscriptions, exports.
- Counter staff: POS, quick bills, customer and repair operations.
- Inventory staff: stock updates, category/item management, reorder signals.
- Platform admin team: tenant management, billing controls, security operations.

---

## 4) Core Business Flows

### A) Paid onboarding flow

1. Choose shop type (`/shops/choose-type`)
2. Choose plan (`/subscription/plans`)
3. Pay (`/subscription/payment`)
4. Payment callback verifies signature
5. Shop is created (`/shops/create`, `POST /shops`)
6. User enters dashboard (`/dashboard`)

Webhook endpoint:

- `POST /subscription/payment/webhook`

### B) Daily operations flow

1. Manage products/items and stock
2. Sell through POS or quick bill
3. Manage customers and repairs
4. Track reports, exports, and close-day activities

### C) Catalog growth flow

1. Staff creates item/collection share links
2. Customer opens public catalog pages
3. Shop receives leads/orders through their regular sales process

---

## 5) Feature Overview by Edition

| Module | Retailer | Manufacturer | Notes |
|---|:---:|:---:|---|
| POS / Sales / Invoices | Yes | Yes | Shared sales stack |
| Customers / Repairs / Settings | Yes | Yes | Shared core |
| Cash ledger and reports | Yes | Yes | Shared base reporting |
| Quick Bills | Yes | Yes | Separate quick-bill register and flow |
| Bulk import flows | Yes | Yes | Route + role + edition guarded |
| Gold lot inventory | No | Yes | Manufacturer only |
| Customer-gold workflows | No | Yes | Manufacturer only |
| Vendors / Suppliers | Yes | No | Retailer only |
| Schemes / Offers | Yes | No | Retailer only |
| Loyalty / EMI / Reorder / Tags | Yes | No | Retailer only |
| Catalog and WhatsApp sharing | Yes | No | Retailer-led growth workflow |

---

## 6) Platform Architecture

### Backend (core)

- Framework: Laravel 12 on PHP 8.2+
- Auth for mobile APIs: Sanctum bearer token
- Data model: shared tables with strict `shop_id` tenancy scoping
- Jobs and scheduling: queue workers + scheduler for async/periodic work

### Web application

- Blade + Tailwind + Alpine + Turbo
- Tenant dashboard under `/dashboard`
- Platform admin under `/admin/*`

### Mobile application

- Expo Router + React Native
- TanStack Query + Axios
- API base path: `/api/mobile`
- Works as an authenticated companion to the same tenant account

### Public catalog surfaces

- Signed fallback links:
  - `/catalog/p/{token}`
  - `/catalog/c/{token}`
- Slug website routes:
  - `/s/{slug}`
  - `/s/{slug}/product/{token}`
  - `/s/{slug}/collection/{token}`

### Critical tenancy/security boundary

- Tenant operations are guarded by middleware such as `tenant`, `subscription.active`, `account.active`, and `shop.exists`.
- Platform admin uses separate routes and privileges.

---

## 7) High-Level Route Map

### Web

- Main dashboard: `/dashboard`
- Platform admin: `/admin/*`
- Public catalog website: `/s/{slug}/*`

### Mobile API

Mounted with prefix:

- `/api/mobile/*`

Examples:

- `POST /api/mobile/auth/login`
- `GET /api/mobile/dashboard`
- `GET /api/mobile/items/barcode/{barcode}`
- `GET /api/mobile/stock`
- `GET /api/mobile/repairs`
- `GET/PUT /api/mobile/catalog/template`

---

## 8) Deployment Model (What Goes on VPS)

For production, deploy on VPS:

1. `jewelflow/` (web app + API)
2. Database (PostgreSQL)
3. Queue worker and scheduler processes
4. Static asset build output

Do not deploy on VPS for runtime:

1. Full mobile source tree (`jewelflowMobileApp/`)
2. Expo local dev tooling

The mobile app is delivered as APK to users, while the app talks to the VPS-hosted API.

---

## 9) Private Mobile Distribution (No Play Store)

Recommended pattern for your business model (subscription first, then app download):

1. Build signed Android APK
2. Upload APK to secure storage/CDN
3. In SaaS dashboard, show download button only for active subscribers
4. Generate time-limited signed download links
5. Add app version checks so users can be prompted to update

This keeps control in your SaaS while avoiding store publishing.

---

## 10) OTA vs New APK (Expo)

JewelFlow mobile (Expo) has two update types.

### OTA update (no new APK)

Use for JavaScript-level changes, for example:

- UI text/layout updates
- API request or validation logic fixes
- React state/business-rule improvements

### New APK required

Use when native layer changes, for example:

- New native module/library
- Android permissions/config changes
- App icon/splash/package identity updates
- Expo SDK upgrade that changes native build

Simple rule:

- JS/app logic only -> OTA possible
- Native/build config touched -> create and distribute new APK

---

## 11) Local Developer Setup

### A) Web SaaS (`jewelflow/`)

Prerequisites:

- PHP 8.2+
- Composer
- Node.js + npm
- PostgreSQL

Install:

```bash
cd jewelflow
cp .env.example .env
composer install
npm install
php artisan key:generate
php artisan migrate
```

Run all major services together:

```bash
composer run dev
```

Or run manually:

```bash
php artisan serve
php artisan queue:work
php artisan schedule:work
npm run dev
```

### B) Mobile app (`jewelflowMobileApp/`)

Install and run:

```bash
cd jewelflowMobileApp
npm install
npx expo start --clear
```

By default in development, the app tries to resolve API base URL from Expo host and points to:

- `http://<dev-machine-ip>:8000/api/mobile`

You can override with:

- `EXPO_PUBLIC_API_BASE_URL`

Example:

```bash
EXPO_PUBLIC_API_BASE_URL=https://your-domain.com npx expo start
```

---

## 12) Environment and Operational Variables

### Backend (`jewelflow/.env`)

Core app:

- `APP_ENV`, `APP_KEY`, `APP_URL`, `APP_DEBUG`

Database/cache/queue/session:

- `DB_*`, `CACHE_STORE`, `QUEUE_CONNECTION`, `SESSION_DRIVER`

Subscriptions and payment:

- `PLATFORM_ENFORCE_SUBSCRIPTIONS`
- `RAZORPAY_KEY_ID`
- `RAZORPAY_KEY_SECRET`
- `RAZORPAY_WEBHOOK_SECRET`

Optional integrations:

- `METAL_RATES_API_URL`
- `METAL_RATES_API_KEY`

### Mobile

- `EXPO_PUBLIC_API_BASE_URL`

---

## 13) Background Jobs and Schedules

Keep queue workers running in all non-trivial environments.

Scheduled operations include:

- `backup:run` (daily)
- `loyalty:expire` (daily)
- `cache:warm-shops` (every 10 minutes)

If queue/scheduler is down, imports and async operations will lag or fail.

---

## 14) Testing and Quality Checks

Run tests:

```bash
cd jewelflow
php artisan test
```

Useful targeted tests include:

- `BulkImportSafetyTest`
- `BusinessIdentifierArchitectureTest`
- `PlatformBoundaryHardeningTest`
- `SubscriptionServiceEnforcementTest`

Front-end build sanity:

```bash
npm run build
```

Safer production-style asset check:

```bash
npm run build:verify
```

Type-check mobile app:

```bash
cd ../jewelflowMobileApp
npx tsc --noEmit
```

---

## 15) Production Smoke Checklist

After deployment:

1. App loads, login works, dashboard opens
2. Queue worker and scheduler are running
3. Subscription onboarding flow works end-to-end
4. Mobile app can log in and call `/api/mobile/dashboard`
5. Catalog links open publicly
6. Signed links validate correctly

Useful maintenance commands:

```bash
php artisan route:list --except-vendor
php artisan assets:verify-fresh
php artisan optimize:clear
php artisan config:clear
php artisan cache:clear
php artisan view:clear
```

---

## 16) Troubleshooting

### Invalid signature on catalog links

Cause:

- `APP_URL` mismatch (scheme/domain/port)

Fix:

```bash
php artisan config:clear
php artisan cache:clear
```

Then confirm `APP_URL` exactly matches the URL users open.

### Mobile app cannot reach backend

Checklist:

1. Backend reachable from phone network
2. Correct `EXPO_PUBLIC_API_BASE_URL`
3. API routes are under `/api/mobile`
4. No firewall/proxy blocks

### Background tasks not running

Fix:

- Start `php artisan queue:work`
- Start `php artisan schedule:work` or configure cron for scheduler

---

## 17) Security and Ops Guidance

- Keep secrets in environment variables only
- Use HTTPS in all shared environments
- Restrict APK download endpoint to active subscribers
- Prefer signed, expiring download links for APK delivery
- Keep mobile signing keys in secure CI/secrets storage
- Monitor payment webhook and queue health

---

## 18) FAQ

### Do we deploy mobile source code to VPS?

No. Deploy backend and database to VPS. Distribute APK separately.

### Do we need a new APK for every app change?

No. JS-only changes can go OTA. Native/build config changes require new APK.

### Can we run without Play Store?

Yes. You can distribute APK privately via your SaaS dashboard with access controls.

---

## 19) License

Use and distribution follow your organization policy and contractual terms.
