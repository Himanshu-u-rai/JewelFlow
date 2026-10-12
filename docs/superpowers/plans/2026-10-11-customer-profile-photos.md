# Customer Profile Photos Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans
> inline, with the Local Codex chat as sole writer. No delegated writers.

**Goal:** Optional, private customer/borrower photos with camera/upload previews,
safe replacement/removal and no financial, KYC, OTP or mobile API changes.

**Architecture:** One shop-scoped `customer_profile_photos` record per customer,
with an opaque revision and private generated file reference. A strict GD encoder
accepts both capture and upload, retains only a proportional, orientation-corrected
WebP at maximum 512px, and never retains the original on failure. Shared Blade/
Vite UI uses existing tokens and product-specific permission-gated routes.

**Tech Stack:** Laravel 12, PostgreSQL, existing private local disk, PHP 8.2 GD/
EXIF, native getUserMedia/dialog, existing Vite/Turbo. No new dependencies.

## Approved constraints and baseline

- Branch `feature/customer-profile-photos`; isolated sibling worktree.
- Base `c68f0f932035370e72cf126da88d724aa3815665` matched production read-only.
- OTP `c390d20` clean and paused; staging `4deb8da` enforcement OFF, untouched.
- Inputs JPEG/PNG/WebP, maximum 5 MiB and 20,000,000 pixels.
- Quality starts at 80; try 75 and 70 when over 100 KiB, recording actual results.
  Size target is not a hard business rule: keep identifying detail at quality 70.
- Never upscale; preserve proportions; strip metadata and handle all EXIF
  orientations including mirrored phone photos. No database image bytes/base64.
- Existing customer details remain editable when archived; photos neither
  reactivate a customer nor relax restrictions on new commitments.

## Task 1 — strict encoder and safe persistence

Files: `tests/Unit/Customer/ProfilePhotoEncoderTest.php`,
`tests/Support/ProfilePhotoFixtures.php`, `app/Services/CustomerProfilePhotoEncoder.php`,
`database/migrations/2026_10_11_000001_create_customer_profile_photos_table.php`,
`app/Models/CustomerProfilePhoto.php`, `app/Services/CustomerProfilePhotoService.php`,
`app/Console/Commands/PruneCustomerProfilePhotos.php`.

- [x] Verify the inherited test guard and run the existing customer/borrower baseline.
  Command: `php8.2 /home/himanshu/jf-customer-photo-evidence/run-local.php vendor/bin/phpunit tests/Feature/Customer/CustomerManagementTest.php tests/Feature/Dhiran/DhiranBorrowerProfileTest.php`.
- [x] Write and run encoder tests RED before production code, including real
  encoded JPEG/PNG/WebP fixtures, EXIF orientation/GPS injection and corrupt data.
  Assertions use the stored bytes, not mock calls:

```php
$result = app(CustomerProfilePhotoEncoder::class)->encode($upload);
$info = getimagesizefromstring($result['bytes']);
$this->assertSame('image/webp', $info['mime']);
$this->assertLessThanOrEqual(512, max($info[0], $info[1]));
$this->assertStringNotContainsString('Exif', $result['bytes']);
```

- [x] Implement encode validation before GD decode, dimension/memory checks,
  orientation correction, proportional downsampling and strict quality 80/75/70
  WebP output. Validate the generated result; on any failure throw a field error.
- [x] Add a unique customer FK, shop FK, private path, width/height/size and UUID
  revision; no original name or image bytes. Hide the reference from serialization.
- [x] Write persistence tests RED: replacement, removal, refused stale revisions,
  false/throwing storage writes, database failure and cleanup refusal while selected.
  The expected request contract is:

```php
$this->postJson('/customers/'.$customer->id.'/photo', [
    'customer_photo' => $upload, 'customer_photo_version' => $revision,
])->assertOk()->assertJsonStructure(['photo' => ['url', 'version']]);
```

- [x] Serialize photo mutations on the customer row, compare the expected revision,
  commit the new reference only after private storage succeeds, and clean up only
  unreferenced generated files after commit. Keep an orphan if the database cannot
  prove it is unused; a dry-run-first 24-hour prune command owns retry cleanup.
- [x] Run the focused encoder/persistence tests GREEN and measure representative
  original-versus-stored sizes. Do not claim a fixed compression ratio.

## Task 2 — authenticated product routes and existing forms

Files: `app/Http/Controllers/CustomerProfilePhotoController.php`,
`app/Http/Controllers/CustomerController.php`, `app/Http/Controllers/DhiranController.php`,
`app/Models/Customer.php`, `routes/web.php`,
`tests/Feature/Customer/CustomerProfilePhotoTest.php`.

- [x] Write/rerun request tests RED for guests, view-only mutations, cross-shop
  direct image requests, product/realm separation, subscription read-only state,
  old-version requests and unchanged customer/financial/KYC records.
- [x] Put Retail image/read and mutation routes inside existing shop/subscription
  gates with `realm:erp`, existing editions and `customers.view`/`customers.edit`.
  Dhiran routes retain `realm:dhiran`, email verification, enablement and
  `dhiran.view`/`dhiran.create`. Return no raw disk paths.
- [x] Serve only current-version WebP after tenant authorization, using
  `Cache-Control: private, no-store`, nosniff and fixed image/webp content type.
- [x] Add a hidden photo relation and eager-load only web customer/borrower lists.
  Do not change existing mobile controllers/envelopes, quick-add or KYC helpers.
- [x] Add optional photo validation to full customer create/update; coordinate
  customer form fields and photo in the same database transaction. When photo is
  omitted, retain existing customer behavior. Report failure with old form values.
- [x] Run all new request tests and inherited customer/Dhiran regressions GREEN.

## Task 3 — shared photo controls and local acceptance

Files: `resources/views/components/customer-avatar.blade.php`,
`resources/views/components/customer-photo.blade.php`,
`resources/views/customers/{create,edit,index,show}.blade.php`,
`resources/views/dhiran/borrowers/{index,show}.blade.php`,
`resources/js/customer-photo.js`, `resources/js/app.js`,
`resources/css/customer-photo.css`, `resources/css/app.css`,
`tests/Frontend/customer-photo.test.mjs`, feature UI-render coverage.

- [x] Write failing DOM/lifecycle checks and UI-render expectations for visible
  Take/Upload/Change/Remove controls, staged form selections and accessible dialog.
- [x] Use consistent 40px list / 80px profile avatars, initials and object-fit framing.
  Hide mutation controls from view-only users. Reuse existing fonts/tokens/buttons.
- [x] Use one modal with camera preview/capture/retake/confirm and upload preview;
  native dialog plus a scoped keyboard wrap supplies focus trapping, Escape/cancel
  and focus restoration.
  Persist named form files only on confirmation, never discard other form values.
- [x] Stop media tracks and revoke blob URLs on closing, navigation, pagehide,
  Turbo cache/render and late camera permission resolution. Refresh avatars on
  restored pages so replaced/removed images cannot survive cached navigation.
- [x] Build with `npm run build`; test UI lifecycle with
  `node --test tests/Frontend/customer-photo.test.mjs` and actual local browser flows.
- [x] Use only guarded `jewelflow_testing` fixtures for Retail and Dhiran; record
  screenshots, errors, keyboard/narrow viewport and repeated Back/navigation.
  Browser camera emulation is not physical-device acceptance.
- [x] Run relevant feature/unit regressions, PHP lint, Blade compile/clear and
  `git diff --check`; review each changed/new source file and integration diff; commit explicit paths only.
- [x] Separate photo handoff records exact SHAs, measured compression, cleanup/
  rollback migration considerations and an alternative staging plan that does
  not overwrite the paused OTP deployment. No remote deployment/push/main merge.

## Definition of done

All approved requirements have source, automated or actual browser evidence;
remaining physical-device limitations are explicitly NOT RUN. New worktree is
clean after the reviewed feature commit; OTP branch/staging remain unchanged.
