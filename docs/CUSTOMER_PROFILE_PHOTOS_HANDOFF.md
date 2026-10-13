# Customer profile photos — local review handoff

## State and boundaries

- Branch: `feature/customer-profile-photos`.
- Worktree: `/home/himanshu/Desktop/jewelflow-worktrees/customer-profile-photos`.
- Base/original feature parent: `c68f0f932035370e72cf126da88d724aa3815665`, verified against the
  production checkout read-only on 11 October 2026 at 15:53:47 UTC.
- Feature commit subject: `feat: add private customer profile photos`.
  Final SHA is recorded after committing in the separate evidence coordination
  record; this document travels with the feature commit itself.
- Sole writer: Local Codex. No push, main merge, remote migration or deployment.
- OTP worktree/branch is preserved at `c390d201c46c7675506ea1e3b8ba80f1d0d24b90`.
  OTP staging was `4deb8dadfc09e943c30fac229badebff74bbd319`, enforcement OFF,
  at the initial read-only check. Neither was changed by this feature. OTP remains
  paused pending Fast2SMS approval; no SMS sent. OTP and UI-audit work stay paused.

## Independent-review corrections — 14 October 2026

Correction parent: `917109839d13ae3b1849296472b6a06126119608`.
The final correction SHA and its immutable full-suite results are recorded after
commit in the external review packet and coordination ledger, not guessed here.
This correction changes only the shared photo JavaScript, its new browser
regressions and this handoff. Backend, storage, encoder, permission gates, CSS,
dependency manifests/locks and mobile APIs are unchanged.

- R1: metadata reads capture both controller identity and its change generation;
  a late restored-page response cannot undo a newer save/remove or apply to a
  disposed controller. Cached controls remain usable after an outstanding save.
- R2: successful mutations restore keyboard focus after re-enabling controls;
  removing a photo chooses an enabled, visible fallback if its opener disappears.
- R3: an uncertain response reports an unconfirmed outcome, reads authoritative
  metadata and requires fresh readable state before retrying. A read never
  automatically resubmits a mutation. Known validation messages remain visible.

The supplied reviewer reproducer initially reproduced all three findings and
then passed all three against the correction. All 28 frontend tests passed with
zero skips: 18 actual Chromium/native-DOM cases using candidate JS/CSS and
controlled HTTP responses, plus the 10 existing camera/lifecycle tests. Running
the same tests against exported original-commit JS/CSS produced 14 failures and
14 passes, proving the checks distinguish the broken implementation. The feature
worktree was never reverted for this falsification.

Guarded focused PHP tests passed: 65 tests / 652 assertions. Vite build, JS syntax
and diff checks passed. The full configured PHP suite at the original immutable
9171098 candidate passed 3,621 tests / 18,572 assertions with zero failures/errors,
one deliberate browser-only skip and 29 existing PHPUnit deprecations. Its first
run without APP_NAME recorded one inherited BackupConfigurationTest failure;
explicit APP_NAME=JewelFlows matched the example configuration and passed. No
application/configuration fix was made to hide that failure. New immutable
candidate results, exact commands, local Laravel browser coverage and screenshots
are in the updated packet; physical-device acceptance remains NOT RUN.

Both real local Laravel product flows passed with isolated synthetic accounts:
normal upload/replace/remove, delayed authenticated metadata after Turbo restore,
the next mutation returning 200, keyboard focus, actual commits with intentionally
lost upload/removal responses, authoritative read reconciliation without duplicate
submission, Back/Forward, emulated capture/retake/cancel cleanup, and 390px layouts
with 44px controls and no horizontal page overflow. Four refreshed synthetic
desktop/mobile screenshots were inspected. HTTP delivery manipulation in these
checks is not a provider/storage failure rehearsal or physical-camera acceptance.

The new browser test requires an existing Playwright installation through
PLAYWRIGHT_MODULE_PATH and optionally BROWSER_EXECUTABLE. Without Playwright it
explicitly skips its browser cases; the verified run supplied both and skipped
none. No new application dependency was introduced.

## Implemented behavior

Retail customer and Dhiran borrower profiles/directories share 40px list and
80px profile avatars with initials fallback. Camera, upload, change and remove
are SVG icon buttons with accessible names, titles and keyboard focus rings;
phone targets are at least 44px. Preview/capture/retake/cancel precede saving;
removal has an explicit confirmation. Full customer create/edit forms stage the
optional photo with their normal save; quick-add and native/mobile APIs are unchanged.
Dhiran's existing quick-create remains photo-free; its existing borrower profile
offers the photo controls after creation. No new borrower-onboarding workflow.

One separate `customer_profile_photos` row stores a generated private path,
shop/customer IDs, revision UUID, pixel dimensions and stored byte size. No image
bytes/base64, original filename, GPS or original version is stored in the database.
Only the optimized WebP is kept on the existing private `local` disk.

Both capture and upload use the same strict server encoder: actual JPEG/PNG/WebP
content, at most 5 MiB / 20,000,000 pixels, proportional maximum edge 512px,
no upscaling, all eight EXIF orientations/mirrors, and metadata removal. PHP's
existing GD/WebP and EXIF extensions are used, without adding dependencies.
PNG/WebP TIFF EXIF chunks use the standard seekable-stream
[PHP EXIF reader](https://www.php.net/manual/en/function.exif-read-data.php).
Processing failures never fall back to an original upload.

Quality starts at 80, then 75/70 if over the advisory 100 KiB target. Quality 70
is the floor to avoid blindly degrading identifying detail. No fixed byte ceiling
or compression ratio is promised. Generated output is checked before storage.

Product routes retain authentication, tenant, realm, edition, email, subscription
and existing view/mutation permissions. Images are served only through these
routes, with `private, no-store`, fixed WebP type and `nosniff`; no public storage
URL or disk path. Old revision image URLs stop serving after replacement/removal.
View-only users see the photo but no mutation controls. Archived-customer editing
follows the pre-existing profile rules and never reactivates a customer.

Customer-row locking plus the required expected revision serialize changes.
Storage/database failure preserves the prior selection; an uncertain database
acknowledgment uses honest refresh-before-retrying wording. Cleanup rechecks
references under a lock and never deletes a selected file. Database/storage
uncertainty defers unused-file cleanup instead of guessing.

Camera tracks and preview blob URLs are released on close/cancel, navigation,
Turbo caching/render, pagehide and late permission resolution/refusal. Restored
pages refresh private photo metadata; removed/replaced photos do not reappear in
the exercised Back/Forward flows. A native dialog with a scoped keyboard wrap
keeps focus inside; focus returns to the opener on cancel.

## Actual local results — 12 October 2026

Guard resolved before PHPUnit: `APP_ENV=testing`, `DB_CONNECTION=pgsql`,
`DB_HOST=127.0.0.1`, `DB_DATABASE=jewelflow_testing`. Development database
`jewelflow_test`, staging and production were not used for tests. No SQL/Tinker,
manual reset/migrate-fresh or application seeder was used. Normal PHPUnit
`RefreshDatabase` owns schema reset/migrations and isolated fixtures.

PHP 8.2.34, PostgreSQL; dependencies installed from unchanged locks. Exact command
prefix used privately: `php8.2 /home/himanshu/jf-customer-photo-evidence/run-local.php`.
The evidence runner forwards only the testing database password, not OTP credentials.

```text
vendor/bin/phpunit tests/Feature/Customer tests/Unit/Customer tests/Feature/Dhiran
  tests/Feature/DhiranIsolationTest.php tests/Feature/DhiranOnboardingTest.php
  tests/Feature/DhiranCrossPromoUrlTest.php tests/Feature/RealmFoundationTest.php
  tests/Feature/RealmAuthFrontDoorTest.php tests/Feature/RealmAuthHardeningTest.php
  tests/Feature/CustomerOpeningBalanceVisibilityTest.php
  tests/Feature/CustomerContextTest.php
  tests/Feature/Masters/CustomerMobileCanonicalFormTest.php
  tests/Feature/ImageOptimizerTest.php
PASS: 286 tests, 1,330 assertions; no skipped tests reported.

node --test tests/Frontend/customer-photo.test.mjs
PASS: 10 tests; includes late permission cancellation/refusal and preview failure.

vendor/bin/phpunit /home/himanshu/jf-customer-photo-evidence/ConcurrencyRehearsalTest.php
PASS: 1 test, 7 assertions; two independent PHP/PostgreSQL connections.
One replacement won, the other returned 409; only the winner remained.

vendor/bin/phpunit /home/himanshu/jf-customer-photo-evidence/CompressionMeasurementTest.php
PASS: 1 test, 16 assertions; measured synthetic samples below.

npm run build; node --check resources/js/customer-photo.js
PASS. Generated assets are ignored and not committed.
php8.2 -l: all 13 changed/new PHP source/test files PASS.
artisan view:cache; artisan view:clear; git diff --check: PASS.

Final focused rerun: 65 tests / 652 assertions PASS.
```

Encoder tests decode actual output and check supported content, limits, corruption,
no-upscale, all EXIF rotations/mirrors and GPS/EXIF/XMP stripping. Request tests
cover upload/replace/remove, stale revisions, real permission/realm/edition/email/
subscription gates, cross-shop direct reads/mutations in both products, storage
false/throwing writes, database save/delete failure, deferred cleanup, orphan
pruning, full forms and unchanged serialization. A populated invoice/loan/KYC
snapshot remains unchanged through dedicated photo mutations.

The new email-gate assertion initially failed because Dhiran's existing JSON
contract is 409 with a message, not 403 or a new error code. The test was corrected
to the existing source/observed contract; no gate behavior was changed.

Real headless Chromium, synthetic accounts/images, desktop 1440px and narrow
390px: Retail and Dhiran upload preview/cancel, capture/retake/cancel with an
emulated media device, upload/replace/remove, old URL rejection, list avatars,
keyboard focus, no horizontal page overflow, and Retail Turbo Back/Forward
passed. A separate Chromium session with actual browser permission denied
showed clear refusal feedback and still allowed upload preview. Actual 24MP
upload rejection kept the old image; full create/edit preserved unsaved input.
Browser media emulation is explicitly NOT physical-phone testing.

Evidence is outside Git at `/home/himanshu/jf-customer-photo-evidence/`:
`retail-browser.log`, `retail-form-browser.log`, `denied-browser.log`,
`dhiran-browser.log`, `final-mobile.log`, `regression.log`, `concurrency.log`,
`compression-results.json`, `camera-final.log`, `build.log`, `blade.log`.
Screenshots include Retail/Dhiran desktop and phone profiles, phone previews,
directories, denied camera, rejected upload and customer creation. Only synthetic
identities/images are present. Browser/server processes are stopped at handoff.

| Synthetic sample | Original bytes | Stored bytes | Output / quality |
| --- | ---: | ---: | --- |
| JPEG illustration, 1600×1200 | 87,976 | 2,610 | 512×384 / 80 |
| PNG illustration, 1600×1200 | 12,139 | 2,606 | 512×384 / 80 |
| WebP illustration, 1600×1200 | 17,940 | 2,600 | 512×384 / 80 |
| Small PNG, 80×120 | 559 | 720 | 80×120 / 80; no upscale |
| High-entropy stress JPEG, 512×512 | 634,820 | 157,950 | 512×512 / 70 |

These are synthetic measurements, not promises for real portraits. Small inputs
can grow in bytes; deliberately random detail can exceed the advisory size target.

## Migration, cleanup and rollback

One additive migration:
`2026_10_11_000001_create_customer_profile_photos_table.php`. Unique customer and
file-reference constraints; shop/customer foreign keys cascade only new photo
metadata. No customer/financial/loan/KYC columns, triggers or historical rows change.

After successful replace/remove, old generated feature files are removed only
after commit and a fresh reference check. Failed cleanup or a hard customer/shop
deletion can leave an unreferenced optimized file. Operational retry:
`php artisan customer-photos:prune --dry-run`, review, then the same command
without `--dry-run`. It touches only valid generated UUID `.webp` paths in the
feature namespace on the private disk, never KYC/evidence, and only files older
than 24 hours. No cron/server changes have been made; scheduling needs deployment
review. Do not run a broad filesystem delete.

Back up both the photo metadata table and private feature directory consistently
before any approved deployment/rollback. Prefer a code rollback that retains the
new table and private files for recovery. Migration `down()` removes references,
NOT files; do not drop it without explicit backup/retention approval. A storage
restore must match the database references; restarting PHP alone is not recovery.

PHP needs GD with WebP and EXIF. The later preview site must independently verify
upload/post/web-server body limits (at least 5 MiB upload and 8 MiB request) and
adequate memory for 20MP orientation processing, ordinarily at least 256 MiB per
worker with suitable pool limits. The encoder refuses safely when current memory
is insufficient; it never raises shared PHP limits or saves an original.

## Staging plan — preserve paused OTP

No deployment is authorized by this handoff. Do not deploy this production-based
branch over the OTP staging release or change its database, environment, service,
enforcement, Fast2SMS route/key, DNS or upstream.

1. Review this feature commit. Choose an explicitly approved separate preview
   release/site with its own synthetic database, private storage and PHP service.
   New hostname/certificate/Nginx/service work requires exact separate approval.
   An approved reviewed integration with OTP is an alternative, not automatic.
2. Record the untouched OTP staging SHA/enforcement state before and after. Prove
   the preview service cannot load production/OTP environment or shared storage.
3. Verify backup/restore for the preview database AND private photos; verify PHP
   extensions, memory/body limits and camera permissions policy. Build from locks,
   deploy reviewed assets/code and the single additive migration only there.
4. Exercise both product hostnames using synthetic accounts and real permission
   roles: direct image/cross-shop/realm denial, read-only/subscription blocking,
   full forms/quick-add, failure rollback, concurrent changes, cleanup dry-run,
   caching/Back, narrow layouts and keyboard/screen-reader labels.
5. With the lead participating, test actual phone cameras, uploads, refusal,
   retake, orientation, background/navigation stream cleanup and identification
   detail on physical iOS/Android devices. This is NOT RUN locally. Test modern
   Safari/Firefox separately; only Chromium was browser-tested here.
6. Leave the preview for review; no main merge, APK/OTA, production deployment,
   OTP SMS or enforcement change. Export an acceptance report with exact SHAs.

## Remaining limitations / scope exclusions

- Physical phones/webcams and Safari/Firefox browser acceptance: NOT RUN.
- Full repository suite at the original 9171098 candidate: actually run as
  described above. Correction-candidate results are recorded in the updated
  external packet. Remote recovery/deployment remains NOT RUN.
- Existing npm lock audit reports 19 vulnerabilities (3 moderate, 14 high,
  2 critical). No dependency versions changed and no automatic audit fix applied.
- No cropping editor, photo albums, additional image variants, KYC promotion,
  native-app changes, user avatar/shop-logo changes or broader theme redesign.
- Real portrait identifying detail must be confirmed during physical-device
  acceptance; the 100 KiB target can be exceeded as measured above.
