# Retailer Daily Metal Pricing and Shared Purity Catalog

## Summary

- Build a shop-scoped retailer pricing subsystem where the owner must enter the day's base metal rates once per shop-local business day.
- Recalculate `cost_price` for all `in_stock` retailer items from the current day's metal rates; keep `selling_price` manual and unchanged.
- Add explicit `metal_type` and shared shop-managed purity definitions so gold and silver pricing stop depending on category-name guessing.
- Add an owner-only `Pricing` settings tab to manage timezone, purity definitions, today's rates, and legacy-item cleanup.

## Key Changes

### 1. Data model and pricing source of truth

- Add explicit `metal_type` to retailer pricing entities that need it now:
  - `items` gets `metal_type` (`gold` or `silver` in v1).
  - `products` gets `metal_type` so any stock/template flow can use the same purity catalog.
- Add a shop-scoped purity-definition table, e.g. `shop_metal_purity_profiles`, with:
  - `shop_id`, `metal_type`, `label/code`, `purity_value`, `basis` (`karat_24` for gold, `millesimal_1000` for silver), `is_active`, `sort_order`.
- Add a daily base-rate table, e.g. `shop_daily_metal_rates`, with:
  - unique `shop_id + business_date`
  - `timezone`
  - `gold_24k_rate_per_gram`
  - `silver_999_rate_per_gram`
  - `entered_by_user_id`, `entered_at`, `updated_at`
- Reuse existing append-only `metal_rates` for resolved historical per-purity shop rates. Each day/save writes shop-specific resolved rows for every active purity profile.

### 2. Pricing engine behavior

- Add one shared pricing service as the SaaS source of truth for:
  - current shop business date from pricing timezone
  - active purity profiles per metal
  - current resolved rate lookup for `shop + metal_type + purity`
  - deriving gold rates as `24k_base * purity / 24`
  - deriving silver rates as `999_base * purity / 1000`
  - applying same-day manual per-purity overrides from the Pricing page
  - retailer `cost_price` formula: `net_metal_weight * resolved_rate_per_gram + making_charges + stone_charges`
- Same-day manual overrides are valid only for the current business day. They do not carry into tomorrow.
- Repricing scope is only retailer items with `status = in_stock`. Sold items, invoices, and historical financial records remain unchanged.

### 3. Owner daily prompt and shop gating

- Add an owner-only blocking modal rendered from the authenticated app shell for retailer shops.
- Trigger rule:
  - if the current shop-local business day has no `shop_daily_metal_rates` row, owner sees the modal on first authenticated load anywhere in the app.
- Modal inputs:
  - 24K gold price per gram
  - silver 999 price per kg in UI, converted to per gram internally before storage
- Save action:
  - create/update today's daily base-rate row
  - resolve/write current-day per-purity shop `metal_rates`
  - queue a retailer stock reprice job for existing `in_stock` items
- Non-owner behavior before owner submits today's rates:
  - allow general navigation
  - block pricing-sensitive retailer actions until rates exist: retailer item create/edit/import/mobile stock writes and retailer POS sale entry points

### 4. Retailer stock flows

- Web retailer item create/edit:
  - add `metal_type` field
  - replace hardcoded purity dropdowns with active shop purity profiles filtered by selected metal
  - auto-preview `cost_price` live from today's rates
  - keep `selling_price` editable and manual
  - server always recomputes retailer `cost_price` from authoritative daily rates, ignoring client-sent cost
- Mobile retailer item create/update API:
  - add `metal_type`
  - make `cost_price` backward-compatible but non-authoritative: accept if sent, ignore for retailer, return computed value
  - preserve `selling_price` as request-owned
- Retailer stock import:
  - add `metal_type` column
  - compute retailer `cost_price` from current daily rates during import
  - preserve imported/manual `selling_price`
  - reject import when current-day rates are missing
- Existing retailer stock migration:
  - backfill `metal_type` from current category heuristics where classification is confident
  - auto-create missing purity profiles for observed legacy purities
  - leave ambiguous items in a review-required state and exclude them from repricing until fixed

### 5. Pricing settings tab

- Add new owner-only Settings tab: `Pricing`.
- Tab sections:
  - pricing timezone with default from app timezone for existing shops
  - today's base rates card
  - active purity definitions by metal, including create/edit/deactivate/reorder
  - today's resolved-rate grid with optional same-day per-purity overrides
  - legacy-item review list for missing/ambiguous `metal_type`
- Replace hardcoded purity lists in pricing-sensitive SaaS forms with the shared shop purity catalog, starting with retailer stock flows and any other shared metal/purity selectors touched by this pricing system.

## Public Interfaces / API Changes

- New retailer item payload field: `metal_type`.
- Retailer item create/update/import no longer treat `cost_price` as client-authored; server computes it from current rates.
- New owner routes/endpoints for Pricing:
  - save today's rates
  - manage purity profiles
  - update today's overrides
  - resolve legacy-item review
- New internal shared pricing service becomes the only approved source for current metal-rate lookups.

## Test Plan

- Owner sees the daily modal only when today's rates are missing, not again the same day, and again after the next shop-local midnight.
- Manager/staff cannot perform pricing-sensitive retailer actions before owner submits today's rates, but regain access immediately after rates exist.
- Gold pricing resolves correctly for standard and custom purities such as `24`, `23`, `22`, `20`, `18`, `14`, and custom observed values like `19.5`.
- Silver pricing resolves correctly from per-kg owner input into per-gram internal rates for `999`, `925`, and custom millesimal profiles.
- Retailer web create/edit, mobile create/update, and import all compute the same `cost_price` server-side.
- Saving today's rates reprices only `in_stock` retailer items and never alters sold history or invoice totals.
- Same-day per-purity override changes current rate lookups and reprices stock for today only; the next day starts fresh from base rates.
- Legacy migration backfills confident items, creates missing purity profiles from observed data, and leaves ambiguous items flagged for owner review.

## Assumptions and Defaults

- `selling_price` remains manual for retailer shops; only `cost_price` auto-updates.
- V1 retailer pricing supports `gold` and `silver` only; `platinum` remains out of scope but the schema stays extensible.
- Business day boundary uses a shop pricing timezone, defaulted from the current app timezone for existing shops.
- Existing ambiguous retailer items are not auto-guessed beyond current safe heuristics; they are excluded from repricing until reviewed.
- Batch repricing of existing retailer stock runs asynchronously via job/queue after daily rate save; new and edited items use current-day pricing immediately.
