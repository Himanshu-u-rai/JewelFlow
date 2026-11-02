# Web UI work: takeover handoff (icons + tables), 2026-11-01

For Codex, taking over the "JewelFlows UI work" chat. Local only: nothing here has been deployed or tested on staging, and nothing touches production, the mobile app, APK/AAB, OTA, OTP or SMTP. Public repo: no credentials in this file.

## Latest checkpoint — Always-visible return refund panels

Started clean `b25b1480d8f3e2ee28a068bcf3a02af0b731933d` on
`feature/table-consistency`. Owner requested Refund breakdown and Adjust refund
remain visible on `/invoices/1/returns/create`. Replaced only their details/summary
wrappers with labelled sections/headings; retained existing bordered surfaces and
removed obsolete chevrons/pointer styling. Exact controls/form tags, inline scripts
and Blade conditions/permission directives are unchanged. No backend/data changes.

Verification: new source/CSS regression tests RED2 before change, GREEN2 after.
Frontend total102 passed/22 existing optional-browser skips; Vite79 build passed.
Fresh resolved guard: testing/pgsql/127.0.0.1:5432/exact `jewelflow_testing`.
`APP_ENV=testing DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 DB_DATABASE=jewelflow_testing php8.2 artisan test --compact tests/Feature/Returns/ReturnCreateDispositionOptionsTest.php tests/Feature/Returns/ReturnsAccessAndLifecycleTest.php`
passed12 tests/48 assertions. Guarded local preview Blade compile/385 PHP lints/
clear, diff check, source identity comparison and Impeccable layout detector[]
passed. Logs: return-panels-* under external quick-bill-notes-dialog-20261102.e6KYil.
Browser policy still blocks visual checks; desktop/mobile/focus appearance remains
unverified, not a visual acceptance pass. No push/deployment/business-data changes.
Next: owner visual review; both panels retain existing availability/permission gates.

## Earlier checkpoint — Quick Bill Notes dialog and visible dropdown arrows

Follow-up from clean `f24f197c508a1c8bcb3698006fb25ede6e653dec`: owner requested
Description half-width. Scoped its desktop (1100px+) wrapper to 50% of its existing
track; phone/tablet keep full width. No fields/handlers/calculations changed.
Source contract and Vite build checked; browser appearance remains unverified.

Next follow-up from clean `825b4c100797c3740283e3ad31707fbf74f37435`: moved the
original Gross/Stone/Net controls into the Description group, in DOM/tab order.
Desktop group uses compact 18rem description plus three 7rem weights; product and
pricing controls share the following row. Phone/tablet stack the description and
weight group; no duplicate fields, hidden values or CSS-only focus reordering.
Six focused Node cases passed, Vite79, guarded Blade compile/385 lints/clear and
diff check/layout detector [] passed. Exact input/select/textarea tags and inline
calculation script unchanged. No DB suite needed for this markup/CSS-only move.
Actual browser layout/focus remains unverified; no remote work.

Metal/Purity follow-up from clean `d839f98a34a5265a24fa6f7dfbfd306c11156c57`:
moved original controls/templates beside the weights in DOM order; compact desktop
Metal 7rem/Purity 6rem tracks with chevrons retained. First group flex-wraps before
crowding narrow desktop windows; phone fields keep two readable proportional tracks.
HSN/Pcs remain below. Same controls/options/handlers/script, no new limits. Six
focused frontend tests and Vite79/Blade385 lints/clear passed; no browser geometry
or exact rendered percentage-reduction claim. Browser review remains next.

HSN/Pcs/Rate follow-up from clean `56c8bba4d1ec093610d263c49b8b326bd6bb742d`:
original HSN, Pcs and Rate controls now follow Metal/Purity in the same wrapping
entry row and DOM/tab order. HSN112px/Pcs72px/Rate144px desktop bases, readable
stacked phone layout; remaining charges use the full following row. No duplicate
fields, input tag changes or calculation changes. Seven focused frontend cases,
Vite build, guarded Blade compile/lints/clear and source checks passed. Browser
rendering/phone geometry remain unverified; local only, no business-data changes.

Rate-width follow-up from clean `d2ed235c3cd331b9de4fb9d1453b42493fbb0fdf`:
desktop Rate basis 9rem→6rem (144px→96px, one-third narrower). Mobile unchanged;
stone amount stays9rem, all form logic unchanged. Seven focused frontend checks,
Vite build and diff check passed. Browser appearance remains unverified.

Owner superseded the proportional size with an explicit 110px desktop Rate basis
from clean `e15a6d21746c839588aaa085131f7c8c9e32ac9e`; mobile and business logic
unchanged. Seven focused frontend checks, build and diff check passed. Browser
appearance remains unverified.

Latest owner adjustment: 130px desktop Rate basis, superseding110px; started clean
`3e34c3709b5edb824b9ec18dda5a29aadfce0367`. Mobile/business logic unchanged.
Seven focused frontend checks, build and diff passed; visual remains unverified.

Weight-width follow-up from clean `35c34201220ee2a8b01892a1513fcab1fe916e78`:
Stone/Net desktop tracks112→56px each, Gross112px unchanged. Shrunk group basis
to15.5rem so adjacent fields use released space; reduced only small-field horizontal
padding to6px. Mobile widths/font sizes, numeric precision and calculations unchanged.
Seven frontend checks/build/diff passed. Browser readability/label wrapping and long
numeric editing at this narrow width remain unverified, not a visual acceptance pass.

Compact charge-row follow-up from clean `9f95f47900daa602ea3d132ed2297d5f9244ce30`:
Pcs72→36px and moved after Rate; Stone amount144→96px and Wastage88→58.67px
(each one-third narrower), both moved into the same wrapping entry row. HSN keeps
its112px slot. Reduced small-field padding, not font size/precision; phone inputs
remain full width. Wastage helper remains visible when positive, on a full row
below the controls so it cannot become a narrow column of text. DOM/tab order
follows the new layout; no duplicated fields or changed input tags/calculation
script. Seven focused Node tests/build/Blade385lints/clear/diff/layout [] passed.
No DB tests or browser checks for this presentation-only move. Very narrow desktop
numeric editing/labels and actual phone/focus geometry remain unverified.

Label-fit correction from clean `e278e6301a80236fa92858844718de8e35d5e2db`:
owner screenshot confirmed Stone wt, Net wt(auto), Wastage% wrapping and shifting
their inputs down. Widened only Stone56→72px, Net56→96px, Wastage58.67→80px;
weight group19rem includes existing Gross112px and gaps. Scoped desktop nowrap
keeps those labels on one line; mobile/fonts and all logic unchanged. Eight focused
frontend checks and Vite/diff passed. Screenshot is pre-fix evidence; post-fix browser
geometry/focus still unverified. Other fields not resized.

Pcs readability follow-up from clean `52ef4e6148b5ac3ba8c8bdf47cc147cf9fe01e10`:
desktop Pcs basis36→64px to accommodate four digits such as1000 with existing14px
tabular text and padding. Mobile and numeric validation unchanged; no maximum added.
Eight focused frontend checks/build/diff passed. Actual browser value fit unverified.

Inline-charge follow-up from clean `626e5f1ae8a02c31e248b62438623cb22bc39a68`:
moved original Hallmark/Rhodium/Other inputs beside Making; removed their separate
card/disclosure. Desktop80px bases for five-digit values, full-size phone grids;
labels retained, no caps/font shrink. Removed obsolete charge-card layout rules
and unused second summary column. Exact input/select/textarea tags and calculation
script unchanged; existing charge values/defaults remain. Legacy internal disclosure
state left in the unchanged script but no longer controls visibility.
Guard freshly verified testing/pgsql/127.0.0.1:5432/exact jewelflow_testing before
RefreshDatabase. PHP26 passed/187 assertions, including normal POST persistence of
10000 for all three charges. Node98 passed/22 existing optional-browser skips, no
failures (nine focused form cases); VM checks total/recalculation with five digits.
Initial source test RED before move; a test-harness tenant context was corrected
for the new item assertion, not an application defect/fix. Vite79/Blade385lints/
clear/Pint/syntax/diff/layout[] and exact script/field source comparison passed.
Evidence inline-charges-* under quick-bill-notes-dialog-20261102.e6KYil.
No preview business-data changes/remote work/dependencies. Browser fit/focus/Turbo
and physical-phone checks remain unverified, not passed; owner visual review next.

Icon actions/customer-width follow-up from clean
`803947bf9c0f32cefa72582ae9a588e7466dadff`: Collapse/Expand now use a rotating
chevron and Remove uses trash-2. Reused jf-row-action/data-tip and the existing
delegated hover/keyboard-focus label helper, not a new tooltip implementation.
Both controls retain44px targets, item-specific accessible names, hidden text and
unchanged handlers; toggle exposes expanded/controls association to its item panel.
Existing customer and Customer name wrappers are50% of their current desktop tracks
at1200px+, with no phone/tablet shrink. Date/mobile/address/layout tracks unchanged.
No field tags, calculation script, validation or submission changes.
Guard verified testing/pgsql/127.0.0.1:5432/exact jewelflow_testing before tests.
Focused PHP3 passed/100 assertions; frontend99 passed/22 existing optional-browser
skips/zero failures, including VM toggle/removal and shared label tests. New VM
fixture corrected to supply the initial item normally provided by the controller;
no application fix hidden. Vite79/Blade385lints/clear/Pint/syntax/diff/layout[] and
field/script identity passed. Evidence icon-actions-* in existing evidence folder.
Actual hover/focus-label placement, touch/phone/zoom/Turbo and post-fix browser
geometry remain unverified. No remote or preview-data changes; visual review next.

Customer single-row follow-up from clean `8886384dd4d73840d20a39292d22cdfd50765a61`:
owner screenshot showed unused grid-cell gaps and Address on its own row. Replaced
desktop1200+ four tracks/half-width child wrappers with five actual tracks: customer,
date, name, mobile, address. Date/mobile retain10.5rem; customer/name get compact1fr
each, Address1.5fr of remaining space. Address no longer spans two columns. Thus
fields sit adjacent instead of reserving blank half-cells, in unchanged DOM order.
Phone/tablet retain original stacked/two-column/full-row Address behavior. No Blade,
field/value/calculation changes. Ten focused frontend cases/build/diff passed;
pre-fix screenshot is evidence, post-fix browser geometry remains unverified.

Item summary presentation from clean `0f291fcd6e96881d287ca59f564910814ea256ad`:
owner requested intentional data grouping for item identity/Gross/Net/Rate/Total
and Line total/Pieces/Wastage/Extra charges. Kept all values/copy, replacing loose
divs with labelled dl/dt/dd strips. Header identity/badges and44px actions occupy
explicit grid areas; figure strip gets consistent8x12 padding and subtle dividers.
Footer is a four-field line summary with an emphasized total, no extra cards or
controls. Both strips use four columns at1100+ and two columns on phones/tablets;
long figures/badges wrap rather than truncate. Removed obsolete summary flex rules.
Total label uses existing darker text token: source palette contrast8.71:1 on
eef2f7 (muted64748b would be4.23:1); ordinary labels4.76:1 on white. These are source
palette calculations, not computed-style/browser measurements.
Guard testing/pgsql/127.0.0.1:5432/exact jewelflow_testing freshly verified. PHP3
passed/104 assertions; frontend100 passed/22existing optional-browser skips/no
failures, including eleven focused form cases. New source contract observed RED.
Vite79/Blade385lints/clear/Pint/syntax/diff/layout[] passed. Exact input/select/
textarea tags, all x-text expressions and calculation script unchanged. Existing
actions/create/edit/retry/save behavior retained. Evidence summary-strips-* in
existing evidence folder. No preview records/remote/dependency changes. Actual
desktop/phone geometry, focus/Turbo/zoom and physical-device checks unverified;
owner visual review remains next.

Starting clean HEAD `54609780106dfe6838bad20bab1eb50bd9774259`, same worktree,
branch and sole writer. Owner requested Notes/Terms removed from the main layout,
a centered popup opened beside the bottom Review action, a smaller address field,
and clear select affordances. Implemented native dialog within the existing POST
form (no nested form/duplicate fields). Native modal focus/Escape/return-focus
behavior is delegated to the browser, not a custom trap. Explicit Close/Done are
non-submit buttons; close preserves text until the bill is saved. Before Turbo
cache, close the dialog. Existing defaults/old input and field names remain.
Phone dialog is viewport-bounded and internally scrollable; controls retain their
44px touch targets. Address starts at one resizable row and two desktop grid tracks.
Customer panel uses the formerly empty Notes area; no stretched two-panel layout.

Root cause for missing dropdown arrows: background shorthand erased select images.
Changed input/select background overrides to background-color (normal/focus/disabled),
added one scoped 18px chevron to every Quick Bill select, and removed competing
making-only positioned chevrons. Native appearance returns in forced-colors mode.
Same selects/options, keyboard semantics, submission routes, calculations and
validation; exact calculation script SHA-256 still
`1ce07b4fa78b070fae296df97f4ee8f1141cffc96a90c850891aa78c60cc61b7`.

Fresh guard: testing/pgsql/127.0.0.1:5432/exact jewelflow_testing before PHPUnit.
Final focused PHP: 26 passed / 180 assertions (new dialog markup, single same-form
fields, edit/retry/default preservation, actual normal POST notes/terms persistence,
toggle/list/pricing/making/reprint). Node: 94 passed / 22 existing optional-browser
skips, no failures. These are source/VM/HTTP checks, not browser interactions.
Initial RED tests failed before implementation. Later test-fixture corrections
supplied required gst_rate and matched existing address whitespace normalization;
no application validation or normalization changed. Source test also caught the
remaining disabled background shorthand before the final fix.
Vite build (79 modules), guarded Blade compile/385 lints/clear, test Pint, PHP/Node
syntax, diff check, unchanged script check and layout detector [] passed.

Evidence: `/home/himanshu/jf-table-evidence/quick-bill-notes-dialog-20261102.e6KYil/`.
Prior browser restriction remains: no rendered screenshots, native focus/Escape,
actual phone/zoom/dropdown/Turbo or measured layout claims. Owner visual review is
next. No data changes, dependencies, backend edits, push/deployment or remote work;
existing preview processes and 24 synthetic bills preserved.

## Previous checkpoint — Compact, type-sized Quick Bill form

Starting clean HEAD `9b596504a172d129a410737dd171b3f0755e159e`, same branch,
worktree and sole writer. Owner requested reducing form length through appropriate
field widths/grouping, not changes to calculations or billing behavior.

New scoped layout lives in the existing Vite app.css pipeline. Removed obsolete
inline structural/phone overrides instead of stacking competing layouts. Entry
gets the full work area rather than a permanent 300px review sidebar. Customer
fields use a wide name/customer slot and compact date/mobile slots (four fields
across only on wide desktops); Address/Notes/Terms start at two resizable rows.
Notes and customer panels no longer stretch each other vertically. Item headers
use small readouts instead of four large metric cards. Description/product fields
share a desktop row; pieces, weights, rates/amounts and percentage have distinct
widths. Making basis/value sit together. Removed nested weight/price cards and
the tall duplicate total card, retaining their data as compact readouts. Additional
charges remain in their original disclosure. Review summary/button follow items;
the existing review/payment drawer and all submit actions remain.

Phone layouts retain full-width customer fields/readable grids and 16px controls,
44px targets; tablet review/extras stack. Scoped important font declarations are
needed to override the inherited important mobile text-sm/text-xs shrink rules.
Labels are associated for the seven static customer/notes fields. No new numeric
limits, field names, validation, default disclosures, Alpine handlers or permission
changes. Old price-grid CSS ordering was removed so visual order follows DOM/tab
order. A source comparison confirms all 126 behavior-bound elements and the exact
calculation-script SHA-256 are unchanged from the starting candidate.

Fresh guard verified testing / pgsql / 127.0.0.1:5432 / exactly jewelflow_testing.
Final targeted PHP command (full command in evidence README) covers Navigation,
View, Quick Bill toggle/list totals/pricing print, making modes and original reprint:
49 passed / 687 assertions, no failures/skips. Focused new form cases: 2/72,
including edit values, manual net weight, retained charges, validation retry and
POST/PUT contracts. Node frontend suite: 93 passed / 22 existing optional-browser
skips / 0 failures. It executes original calculation/row/payment/review handlers
at VM boundaries; CSS contracts are not a browser layout engine. Layout and mobile
font contracts observed RED before their fixes. Two early test-harness issues
(tenant model binding context and DOM parser stripping @input) were corrected in
tests only and are documented, not claimed as application defects.

Vite 79 modules, scoped Pint, PHP/Node syntax, guarded Blade compile/385 PHP lints/
view clear, diff check and source Impeccable scan ([]) passed. Local assets rebuilt;
public/build remains ignored/uncommitted. Evidence:
`/home/himanshu/jf-table-evidence/quick-bill-compact-form-20261102.PUWkvY/README.md`.
Browser restriction honored: no screenshots, measured height reduction, computed
styles, real focus/gesture/Turbo or physical-phone acceptance claims. Owner refresh
the form for visual review, including long names/nonstandard purity helper and
expanded charges. Other Quick Bills audit findings remain out of scope. No data
changes, push, deployment or remote work; 24 synthetic bills/processes preserved.

## Previous checkpoint — Quick Bills opens entry; register in form header

Starting clean HEAD `790e042dd6e648e5701e69aa84136e7383223eb2`, same branch,
worktree and sole writer. Owner requested swapping the Quick Bills entry points.
Sidebar now opens quick-bills.create for users with sales.create; view-only staff
keep the existing register destination. The per-shop enable toggle is preserved.
Added existing data-nav-match hook for /quick-bills so the permanent sidebar can
remain active on list/show/edit/print after Turbo navigation, without JS changes.

The form header's existing register link is now explicitly named Quick Bill
register with a list icon, gated by sales.view. Desktop keeps its label; phone
gets a named 44px icon rather than the old display:none link. This prevents
removing the only visible route to the register after changing the sidebar.
The register retains New Quick Bill as its way back to entry. No route, form
action/fields, validation, calculation, service or permission definitions changed.
Other Quick Bills audit findings are not implemented by this scoped swap.

Guard verified testing / pgsql / 127.0.0.1:5432 / exactly jewelflow_testing before
RefreshDatabase. New navigation tests were RED (2 failed/1 passed) before the swap;
phone header contract also failed before restoring the button. Final command:
`APP_ENV=testing DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 DB_DATABASE=jewelflow_testing php8.2 artisan test --compact tests/Feature/Navigation tests/Feature/View tests/Feature/QuickBillToggleTest.php tests/Feature/QuickBillListTotalsTest.php`
→ 37 passed / 568 assertions, no failures/skips. Covers both shop editions,
restricted view-only/create-only roles, toggle hiding and existing list totals.
`node --test tests/Frontend/*.test.mjs` → 89 passed / 22 existing optional-browser
skips / 0 failures. Source CSS contract, not rendered phone geometry. Guarded
Blade compile, 385 compiled PHP lints, view clear, scoped navigation-test Pint,
PHP/Node syntax and diff checks passed. Source layout scan returned []. Existing
QuickBillToggleTest has only its expected sidebar URL updated, not reformatted.
No new asset utilities/JS or asset build needed. Browser restriction honored;
no rendered keyboard/touch/Turbo acceptance claimed. Synthetic 24-bill preview
batch preserved. Evidence: quick-bills-audit directory, NAVIGATION.md/nav-* logs.
No push/deployment. Next: owner refresh sidebar/form for visual review.

## Previous checkpoint — Simplify Occasions header

Starting clean HEAD `5cfaa054b693412c7ea88ed7c0e00b62587d0684`, same branch,
worktree and sole writer. Removed the birthday/anniversary description and the
Upcoming / Next 7 Days / Customers summary from Customer Occasions at owner request.
Also removed two now-unused local summary calculations. Title, reminder-window
selector/value, filters, record table/mobile cards, dates and permissions remain.
No CSS/JS/controller/data changes or broader redesign.

Fresh guard: testing / pgsql / 127.0.0.1:5432 / exactly jewelflow_testing.
Summary regression failed before removal, then
`APP_ENV=testing DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 DB_DATABASE=jewelflow_testing php8.2 artisan test --compact tests/Feature/Tables/CustomerDirectoryToolbarUiTest.php`
passed 9 tests / 138 assertions, including the populated occasion row remaining.
Guarded preview Blade compile, 385 compiled PHP lints, view clear, scoped Pint
and diff check passed. No asset rebuild or broader suite needed for markup removal.
Browser visual check still unverified; URL-policy restriction honored. Evidence:
customer-mobile-filters directory, `occasions-*` logs. No push, deployment or
remote work. Next: owner refresh Occasions for visual review.

## Previous checkpoint — Remove EMI overdue/outstanding header totals

Starting clean HEAD `a01d8ad0b7fc48867aba4740cb1fef5aad990940`, same branch,
worktree and sole writer. Owner requested removing Overdue and Total Outstanding
from the EMI / Installments header. Plans in View remains. Removed their two
unused Blade aliases only; controller queries, balances, table/card amounts,
overdue row indicators, filters, pagination and permissions are unchanged.

Fresh guard: testing / pgsql / 127.0.0.1:5432 / exactly jewelflow_testing.
Summary regression failed before removal, then
`APP_ENV=testing DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 DB_DATABASE=jewelflow_testing php8.2 artisan test --compact tests/Feature/Tables/CustomerDirectoryToolbarUiTest.php`
passed 9 tests / 136 assertions. Guarded preview Blade compile, 385 compiled PHP
lints, view clear, scoped Pint and diff check passed. No asset rebuild or broader
suite needed for deletion-only markup. Browser visual check still unverified;
prior URL-policy restriction honored. Evidence: customer-mobile-filters directory,
`emi-*` logs. No push, deployment or remote changes. Next: owner visual review.

## Previous checkpoint — Remove Loyalty issued/redeemed header totals

Starting clean HEAD `345b683fe95f906a6c1b22f058da7d43fcd591e9`, same branch,
worktree and sole writer. Removed Total Issued and Total Redeemed from the Loyalty
Points header at owner request; Members with Points remains. Removed their two
unused local Blade aliases, not the controller queries or any loyalty data/logic.
No CSS/JS, other summaries, filters, pagination or permission changes.

Fresh guard: testing / pgsql / 127.0.0.1:5432 / exactly jewelflow_testing.
Updated summary regression failed before removal, then
`APP_ENV=testing DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 DB_DATABASE=jewelflow_testing php8.2 artisan test --compact tests/Feature/Tables/CustomerDirectoryToolbarUiTest.php`
passed 9 tests / 134 assertions. Guarded preview Blade compile, 385 compiled PHP
lints, view clear, scoped Pint and diff check passed. No asset rebuild or broader
suite needed for deletion-only markup. Browser visual check still unverified;
prior URL-policy restriction honored. Evidence: customer-mobile-filters directory,
`loyalty-*` logs. No push, deployment or remote changes. Next: owner visual review.

## Previous checkpoint — Directory mobile totals and Filters share a row

Starting clean HEAD `f2daada979abf30cd41cb754ca11f0e95259a6c9`, same worktree,
branch and sole writer. Owner requested Filters beside the two small Directory
totals on mobile. CSS-only, Directory-scoped grid now keeps the heading full width
above a row containing two shrinkable metric columns and the 44px Filters control.
Metric labels/values can wrap instead of forcing page overflow. The existing
inline no-JavaScript form spans the available width. Desktop and other customer
tabs are unchanged; filter dialog/fields/handlers/permissions are untouched.

New known-selector CSS contract failed before the edit, then passed for widths
320/390/427/640/768 and unchanged desktop rules. Both focused frontend files:
`node --test tests/Frontend/customer-header-layout.test.mjs tests/Frontend/customer-directory-search.test.mjs`
→ 31 passed, no skips/failures. `npm run build` (79 modules), `git diff --check`
and Impeccable source layout scan passed (no mechanical findings). No PHP/DB tests
rerun for a CSS-only change. Browser-policy restriction remains honored: rendered
geometry, focus, touch and physical phone acceptance are still unverified.
Evidence/logs: previous customer mobile filter evidence directory, `row-*` files.
No push or deployment. Next: owner refresh Customers for mobile visual review.

## Previous checkpoint — Customer mobile filters and header action

Starting clean HEAD `31e5426f290677516585b317c10538d70146ef42`, same worktree,
branch and sole writer. Owner requested the invoice-style mobile filter sheet on
Customer Directory, Loyalty Points, EMI / Installments and Occasions, then moving
Add Customer beside the bell on mobile instead of leaving a text button below.
Final follow-up removes the With Email metric from the Directory header only;
the other two totals and backend queries remain unchanged.

All four tabs now reuse the invoice native-dialog pattern through one shared
`responsive-filter-panel` Blade component. Each existing GET form stays inline on
desktop and opens from a 44px Filters button on mobile. Fields, query values,
permissions, counts, pagination and Clear links remain unchanged; Create EMI Plan
stays outside the filter sheet. No duplicate form or query state. Close/backdrop/
Escape dismiss; resize releases the modal for desktop; Turbo closes it before
cache/render. Native dialog supplies the focus boundary; no custom body lock.
Directory/Loyalty search stays visible in the phone sheet and retains the desktop
icon disclosure. Search Escape closes the sheet instead of focusing a hidden icon.

One existing permission-gated Add Customer link now becomes a named 44px icon
beside the bell at phone widths. Desktop keeps its full label. Customer tabs stay
on their own full-width second row; view-only users still get no Add link. The
header stays in normal phone page flow, not a fixed overlay. No global header
component or backend changes. Impeccable refinement reuses the incumbent palette
and invoice pattern rather than introducing another visual system.

Checks before the final one-line With Email removal:

- Guard resolved `testing / pgsql / 127.0.0.1:5432 / jewelflow_testing` before tests.
- New sheet Feature/CSS contracts failed before implementation; the five mobile
  Add-placement CSS cases also failed before their layout change, then passed.
- `APP_ENV=testing DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 DB_DATABASE=jewelflow_testing php8.2 artisan test --compact tests/Feature/Navigation tests/Feature/View tests/Feature/Tables tests/Feature/Historical/HistoricalIndexRenderTest.php tests/Feature/InvoiceFlowTest.php tests/Feature/Customer/CustomerManagementTest.php`
  → 120 passed, 1161 assertions, no failures or skips.
- `node --test tests/Frontend/*.test.mjs` → 87 passed, 22 existing optional-browser
  skips, no failures. Actual Alpine handlers exercised at mocked native-API/DOM
  boundaries; CSS contracts check known selectors, not rendered geometry.
- Vite build (79 modules), scoped Pint, PHP/Node syntax, `git diff --check`, guarded
  preview Blade compile, all 385 compiled PHP lints and view clear passed.

The final With Email removal has its own RED-to-GREEN summary test and focused
Customer/Invoice toolbar rerun: 13 passed, 184 assertions. Blade compile, 385 PHP
lints, view clear, scoped Pint and diff checks passed again. Broader tests above
were not repeated for that display-only deletion.

Evidence: `/home/himanshu/jf-table-evidence/customer-mobile-filters-20261102.tGmD7Y/`.
Browser URL-policy restriction remains honored: no bypass, screenshots or claims
of real modal focus, menu geometry, touch/keyboard/Turbo or physical-phone
acceptance. Owner refresh Customers for visual review. No full PHP suite,
benchmark, push, deployment or remote work. Preview processes/data and other
worktrees preserved. Next: owner visual review of mobile header and all four
filter sheets, plus desktop regression appearance.

## Previous checkpoint — Compact invoice register, matching Directory

Starting clean HEAD `7c99efbdcbceb94163a8e6586b5b512f56e46576`, same isolated
worktree/branch/sole writer. Owner requested removing the four invoice KPI cards,
keeping Total Invoices/Today's Invoices in the table header and applying Directory's
design. Follow-ups removed "Showing 1-20 of 32" from the header only and requested
a phone Filter button/bottom panel like Returns. Actual Returns inbox inspected.

Only the two existing shop-wide counts now appear in a compact header definition
list. Today's Sales/This Month cards are gone; controller calculations unchanged.
Header range and its unused Blade variables removed; paginator's footer range stays.
Directory's existing summary/search/dropdown/Apply/Clear classes are reused with
scoped invoice token aliases. Search opens from its icon and restores focus on
Escape. Status and Payment use the existing standard/viewport select enhancer and
hide their menu scrollbars while retaining scrolling. No new JS module/dependency.

One GET form stays inline on desktop and opens in a bounded phone bottom sheet.
A44pxFilter button invokes a native dialog; it supplies modal focus/keyboard
handling without another custom trap/global body lock. Close, backdrop and Escape
dismiss; resize releases modal state for desktop, Turbo cache/render closes it.
Search is visible inside the phone sheet; desktop keeps the icon disclosure.
All search/from_date/to_date/status/payment_mode fields, values/options, suggestion
hook, active-filter chips and Clear remain. Phone dates stay together in two
shrinkable columns. No duplicate form/query state; no-JS native form fallback stays
inline. Removed obsolete duplicate drawer/lock markup/script and scoped styles. Existing
table/mobile data, amounts, badges, actions and permission gates preserved. Added
pagination-relative row numbers in table and mobile cards, adjusted empty colspan.
Backend's20rows/page unchanged; shared workspace scrolling/sticky headings and
pagination outside rows remain intact. Prior navigation commit is preserved.

Impeccable refinement reuses Directory rather than adding a new visual system.
Source/CSS and server-rendered HTML coverage added; no rendered-browser claims.

Fresh verification:

- Guard resolved testing/pgsql/127.0.0.1:5432/exactly jewelflow_testing before tests.
  RED3new Feature cases failed before UI edits;2CSS contracts failed before their
  fixes, then a narrow date-grid contract failed before its responsive rule.
  New phone-panel Feature/CSS cases also failed before the bottom-sheet addition.
- `APP_ENV=testing DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 DB_DATABASE=jewelflow_testing php8.2 artisan test --compact tests/Feature/Navigation tests/Feature/View tests/Feature/Tables tests/Feature/Historical/HistoricalIndexRenderTest.php tests/Feature/InvoiceFlowTest.php`
  →109passed/1099assertions/0failures/skips. Covers21isolated draft fixtures,
  page2row21, restricted actions, count scope, filters and tenant isolation.
- `node --test tests/Frontend/*.test.mjs` →84passed/22existing optional-browser
  skips/0failures; real Alpine handlers executed with focus/nextTick boundaries.
  CSS contracts are limited known-selector checks, not a browser layout engine.
  Native-dialog initialization/resize/Turbo and search-Escape handlers exercised
  with DOM/native-API boundaries, not claimed as real modal-focus/phone evidence.
- Vite79modules, scoped Pint, PHP/Node syntax and diff checks passed. Guarded
  preview Blade compile,384compiled PHP lints and view:clear passed.
- Source Impeccable layout scan returned0mechanical findings during the tranche,
  before final date-grid/header-range/bottom-sheet refinements. Not visual evidence.

Evidence: `/home/himanshu/jf-table-evidence/invoice-register-20261102.EhhZgr/`.
Browser URL-policy restriction still honored: no bypass/retry, screenshots, actual
phone/desktop gesture/keyboard/dropdown/Turbo acceptance. Owner refresh Invoices
for visual review. No full PHP suite/benchmark, push/deployment, staging/production,
OTP/SMTP/mobile changes. Primary checkout, preview processes and data preserved;
test-created draft records exist only in the guarded disposable test database.

## Previous checkpoint — Invoice header and Returns sidebar placement

Starting clean HEAD `c14634c543c8f7bafeadec0eb8b32c7993164dc8`, same isolated
worktree/branch/sole writer. User requested removing Open POS from the Invoices
header, placing Historical Sales there and moving Returns / Exchange into Sales.

Historical Sales now occupies the existing header action position, with the same
Retail-only/historical.view gate. Renamed its scoped CSS hook and retained existing
styling/phone hit-area declarations. Removed the redundant navigation strip and
its orphan CSS. POS remains accessible from Sales Counter; no POS workflow changed.
Returns / Exchange is one ordinary Sales sidebar entry gated by returns.view,
including roles without sales.view. Invoices no longer claims Returns/Exchanges
in its active-route match; Returns and Operations highlight their own destinations.
Historical-only roles retain their previous sidebar fallback. No route, backend,
permission definition, business calculation or data changes.

Impeccable refinement preserves the existing palette, icons, header and sidebar.
Updated the navigation feature expectations, renamed the old POS touch-area test
to InvoicesHistoricalTouchAreaTest, and synchronized existing browser/staging
acceptance-script selectors/assertions. Those browser/staging scripts were linted
only, NOT executed; no staging access or testing occurred.

Fresh checks:

- Test guard resolved testing/pgsql/127.0.0.1:5432/exactly jewelflow_testing first.
- RED:2navigation tests failed before UI edits;8passed. Added explicit Returns/
  Operations active-link assertions; corrected a test-only stale DOMXPath context
  after reparsing a new response. Focused final10tests/423assertions passed.
- `APP_ENV=testing DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 DB_DATABASE=jewelflow_testing php8.2 artisan test --compact tests/Feature/Navigation tests/Feature/View tests/Feature/Tables tests/Feature/Historical/HistoricalIndexRenderTest.php`
  →92passed/1014assertions/0failures/skips. Includes both editions and restricted
  permissions, header placement, no duplicates, sidebar section and active links.
- `node --test tests/Frontend/*.test.mjs` →75passed/22existing optional-browser
  skips/0failures. Vite79modules; scoped Pint, PHP/Node syntax and diff checks pass.
- Guarded preview Blade compile,384compiled PHP lints and view:clear passed.
- Impeccable source layout detector on the two Blade files:0mechanical findings.

Evidence: `/home/himanshu/jf-table-evidence/invoice-navigation-20261102.kCnpaY/`.
Existing browser URL-policy block respected: no retry/bypass, screenshots or fresh
desktop/phone/tap/keyboard/Turbo browser acceptance claimed. The reused hit-area
source contract is not rendered geometry evidence. Owner should fully refresh
Invoices (sidebar is Turbo-permanent) and check the new placements.
No full PHP suite/benchmark, push/deployment, production/staging, OTP/SMTP or mobile
work. Existing preview processes and synthetic data preserved.

## Previous checkpoint — Match Loyalty, EMI and Occasions to Directory

Starting clean HEAD `d148caf53299189433edda3bcedf39bf0cd56879`, same isolated
worktree/branch/sole writer. Owner requested the Directory treatment for the
three other Customers tabs, including buttons and tables. Impeccable refinement
reuses incumbent patterns rather than introducing another design system.

Removed nine separate KPI cards; their unchanged values now live in compact
definition lists inside each table header, outside scrolling rows. Reused
Directory header/summary/controls classes without new CSS. Loyalty uses the same
search-icon disclosure, focus-on-open and Escape-to-close. EMI Status and Occasion
window use Directory's compact shared-enhancer wrapper, viewport placement and
hidden scrollbar without disabling scrolling. Visible labels remain available to
assistive technology; native GET controls/fallback and query names are preserved.
Apply remains available alongside Clear after filtering (previously replaced).
EMI's filtered total is labelled Plans in View, not misleadingly Active Plans.
Create EMI Plan keeps its existing sales.create gate. Loyalty Adjust uses the
shared named icon action on desktop; mobile retains explicit text actions.

Shared tables, row numbering, contained scrolling/sticky headings, mobile cards,
pagination/query propagation and business calculations remain unchanged. Scope:
Customers tabs only, not standalone EMI/Occasion reports or backend permissions.

Checks actually performed:

- Guard resolved testing/pgsql/127.0.0.1:5432/exactly jewelflow_testing before
  RefreshDatabase; preview/primary development databases not used by tests.
- RED:2new rendered-toolbar tests failed before layout edits (old KPI cards;
  absent Apply after filtering). Initial broader run caught an old Directory
  assertion counting every newly shared form; scoped it to its intended form.
- `APP_ENV=testing DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 DB_DATABASE=jewelflow_testing php8.2 artisan test --compact tests/Feature/Tables tests/Feature/Customer/CustomerManagementTest.php`
  →75passed/562assertions/0failures/skips, covering pagination/indexing and roles.
- `node --test tests/Frontend/*.test.mjs` →75passed/22existing optional-browser
  skips/0failures. Actual Directory/Loyalty Alpine handlers exercised with focus
  boundaries, not claimed as browser interaction.
- Vite build79modules; scoped Pint, PHP/Node syntax and diff checks passed.
  Guarded preview Blade compile,384compiled PHP lints, then view:clear passed.
- Existing synthetic-demo read-only controller/Blade harness --verify passed
  10cases, including page2 and filtering; no fixture/business-data writes.
- Impeccable source layout detector returned0mechanical findings. No visual claim.

Evidence: `/home/himanshu/jf-table-evidence/customer-tabs-match-20261102.gYYfpd/`.
Existing browser URL-policy block respected; no retry/bypass, screenshots or
post-change rendered geometry/wheel/touch/keyboard/Turbo acceptance claimed.
Owner should refresh Customers and inspect all three tabs, including phone width.
No full PHP suite/benchmark needed for scoped presentation changes. Local only;
no push/deployment, production/staging, OTP/SMTP or mobile-app work. Processes and
existing synthetic preview data preserved.

## Previous checkpoint — Hide Customer Status menu scrollbar

Starting clean HEAD `54af151ea0849d7c09bdd7a89398fc013ef14840`, same isolated
worktree/branch/sole writer. Owner requested hiding only this dropdown's scrollbar.
Directory-only Firefox/WebKit scrollbar rules leave the existing `overflow:auto`
and all shared menu behavior intact. Table scrollbar, EMI styling, compact width,
options and filtering are unchanged. No JavaScript, Blade, backend or data edits.

Impeccable refinement stayed scoped to the incumbent dropdown. Regression contract
failed before the CSS edit, then passed12/12 at427/1280px. Full frontend run:
72passed/22existing optional-browser skips/0failures. `npm run build` succeeded
(79modules); Node syntax and `git diff --check` passed. PHP/database tests not
rerun for this CSS-only change. Built assets ignored/uncommitted.

Evidence: `/home/himanshu/jf-table-evidence/customer-dropdown-scrollbar-20261102.1e4lwz/`.
These are source/CSS contracts, not rendered gesture checks. Existing Browser Use
URL-policy block respected; post-change visual, wheel/touch and keyboard acceptance
remain UNVERIFIED. Owner should refresh Customers and open Status. Local only;
no push/deployment or process/database changes. Exact source files: app.css,
customer-header-layout.test.mjs and this handoff.

## Previous checkpoint — Reuse EMI dropdown for compact Customer Status

Starting clean HEAD `dd3906bd501f531259cf801d38b65389596407c5`, same isolated
worktree/branch/sole writer. Owner explicitly requested the existing inline
EMI / Installments dropdown, with a small width rather than its wide form layout.

Directory Status now opts into the SAME existing shared select enhancer as EMI:
standard variant, viewport placement. Existing EMI trigger/hover/open/focus/menu/
chevron selector groups also apply to Directory, instead of another dropdown
implementation. No application JS or shared enhancer behavior changed. All native
field names, values/counts, selection, explicit GET Apply and Clear are preserved.

Only the Directory wrapper differs: content-sized,8rem minimum/10rem maximum
(normally128–160px), no flex growth. It does not inherit EMI's wide grid or
ui-filter-field minimum width. The menu uses the same existing viewport placement
and trigger width. Superseded standalone/native-picker CSS is removed. A named
Status group provides context; after enhancement the invisible native select is
visibility-hidden so only the visible trigger enters the tab order. Before that
marker exists, the native select remains available. Other tabs and search unchanged.

Exact five files: `resources/views/customers/index.blade.php`,
`resources/css/app.css`, `tests/Feature/Tables/CustomerDirectoryToolbarUiTest.php`,
`tests/Frontend/customer-header-layout.test.mjs` and this handoff.

Fresh checks:

- Resolved guard confirmed testing/pgsql/127.0.0.1:5432/exactly jewelflow_testing
  before RefreshDatabase. Demo database not used for destructive tests.
- RED: new opt-in Feature test failed on missing enhancement;4CSS parity/width
  tests failed before edits. A later focus-ring contract failed before removing
  the obsolete Directory-wrapper outline, so it does not double EMI's own ring.
  GREEN: focused5Feature/49assertions and11header/CSS
  cases passed. Native-only contracts were superseded by owner-requested EMI
  parity contracts, not retained as contradictory design requirements.
- `APP_ENV=testing DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 DB_DATABASE=jewelflow_testing php8.2 artisan test --compact tests/Feature/Tables tests/Feature/Customer/CustomerManagementTest.php`
  →73passed/522assertions,0failures/skips.
- `node --test tests/Frontend/*.test.mjs` →71passed/22existing optional browser
  skips/0failures. CSS contracts compare known selector declarations at427/1280px
  and compact wrapper at320/427/1280px; NOT rendered geometry/interaction evidence.
- `npm run build` →79modules, success; assets ignored/uncommitted. Scoped Pint,
  PHP/Node lint, guarded Blade compile/lint384files/clear and diff check passed.
-10guarded actual-demo controller/Blade cases passed; synthetic batch preserved
  without mutation. Impeccable layout detector0mechanical findings.

Evidence: `/home/himanshu/jf-table-evidence/customer-status-emi-20261101.u2PoXT/`.
Browser Use URL-policy block not retried/circumvented. Actual popup, width,
keyboard focus, phone layout and Turbo navigation remain visually UNVERIFIED;
no screenshots or full PHP suite/benchmark claimed. Next: owner refresh
`http://localhost:8001/customers` and compare Directory Status with EMI.
Local-only; no push/deploy, primary checkout/DB, staging/production, OTP/SMTP or
mobile-app changes. Preview8001/privatePG55432 and companion8000 preserved.

## Previous checkpoint — Plain native Customer Status dropdown

Starting clean HEAD `63e70ea38177a19494478e1769cb6a80d15cc765`, same isolated
worktree/branch/sole writer. Owner's desktop screenshot shows two arrows and high
text in the closed Status control. Source contains legacy global select resets
and an image arrow plus the new base-select rendering mode. Exact live browser
computed-style/engine interaction remains unverified because of the existing
Browser Use URL-policy block; the screenshot is owner-supplied rendered evidence.

Removed the optional base-select picker/option/checkmark styling entirely.
Directory Status now explicitly uses ordinary native appearance, including the
prefixed aliases; no background-image arrow, normal native text line height and
less padding. Its open menu is intentionally the platform's standard menu.
Kept the subtle border,36px desktop/44px phone sizing, accessible label and native
Status field/values. No Blade, JavaScript, backend, filter behavior or data change;
compact summaries, icon search, Apply and other tabs remain unchanged.

Exact files: `resources/css/app.css`,
`tests/Frontend/customer-header-layout.test.mjs` and this handoff.

Fresh checks:

- RED: two new Status CSS-contract cases failed before editing; six existing
  header cases passed. GREEN:8/8, including427px/1280px, with base-select support
  enabled/disabled in the limited known-selector cascade contract.
- `node --test tests/Frontend/*.test.mjs`:68passed,22existing optional browser
  skips,0failures. New/changed Node test syntax and diff checks passed.
- `npm run build`:79modules, success. Built stylesheet rules inspected through
  PostCSS confirm native appearance, no background image and normal line height;
  generated assets ignored/uncommitted. No live computed-style claim.
- Impeccable layout detector:0mechanical findings. Not visual acceptance.
- No PHP/Blade/database tests repeated for this CSS-only correction. Prior
  candidate's72PHP/511assertion result remains prior evidence, not a fresh run.

Evidence: `/home/himanshu/jf-table-evidence/customer-status-native-20261101.cXyXRt/`.
No browser retry/workaround or new screenshots. Native popup/keyboard, actual
text centering/arrow count and phone geometry still need owner visual confirmation.
Next: refresh `http://localhost:8001/customers`. Local-only; no push/deploy,
primary checkout/DB, staging/production, OTP/SMTP or mobile-app work. Preview8001,
privatePG55432 and companion8000 preserved.

## Previous checkpoint — Compact Customer Directory register tools

Starting clean HEAD `11487080590c23a7fe7d09878390a925b9bb24fb`, same isolated
worktree/branch/sole writer. Local-only presentation change requested by owner:
smaller totals inside the table header, icon-expand search, redesigned Status.
Scope is Customer Directory (Retail and manufacturer variant); other three tab
KPI/filter layouts remain unchanged, not part of this completion claim.

Moved the existing three values into a semantic definition list beside/below the
register title. No separate Directory KPI cards or duplicate total subtitle.
Counts retain their exact controller scopes: selected customer paginator total,
all Retail invoices or manufacturer's current-page gold, and shop-wide With Email.
No model/controller/query/accounting/permission/route/data changes.

Search uses the existing Alpine runtime, with no new application JS module:
named44px icon toggles the field, nextTick focuses it, Escape closes it and returns
focus without clearing typed text. A server-applied search starts expanded.
The original suggestion/live-filter hooks, GET route and field names remain.
Apply still explicitly submits the chosen status/search; Clear retains its prior
reset behavior. Native input/submit have no new cloak; existing whole-page Alpine
tab dependency is unchanged (not a claim that the whole app works without JS).

Owner rejected the first wide Status-label/tinted-wrapper treatment before any
commit. Final Status is one plain native select:36px desktop,44px on phones,
natural content width with no flex growth,8px radius, white background and subtle border.
Its associated Status label remains for assistive technology, visually hidden.
Native CSS base-select enhancement themes its top-layer picker, compact desktop
options/44px phone options, selected
state/checkmark where supported; other browsers retain the ordinary native picker.
No custom JS dropdown, hidden duplicate select, shared enhancer change or dependency.
Reference checked: https://developer.mozilla.org/en-US/docs/Learn_web_development/Extensions/Forms/Customizable_select
Supporting-browser popup styling is not universal visual acceptance. Phone layout
wraps controls, uses three small summary columns and16px inputs; long values wrap.
New controls/header remain outside the existing row-scrolling region.

Exact five files:

- `resources/views/customers/index.blade.php`
- `resources/css/app.css`
- `tests/Feature/Tables/CustomerDirectoryToolbarUiTest.php`
- `tests/Frontend/customer-directory-search.test.mjs`
- This handoff.

Fresh checks actually run:

- Resolved guard verified testing/pgsql/127.0.0.1:5432/exactly jewelflow_testing
  before RefreshDatabase. Automatic per-test guard retained; demo DB untouched.
- RED:4feature tests and3handler tests failed before implementation on missing
  summary/form/disclosure. Test scope subsequently corrected to exclude the other
  three tabs' intentional KPIs and the pre-existing ancestor tab cloak.
- Focused Feature4pass/38assertions. Handler tests3pass exercise actual Alpine
  expressions with focus/nextTick doubles; NOT native browser keyboard evidence.
- `APP_ENV=testing DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 DB_DATABASE=jewelflow_testing php8.2 artisan test --compact tests/Feature/Tables tests/Feature/Customer/CustomerManagementTest.php`
  →72passed/511assertions,0failures/skips. Includes filtered page2/status counts,
  restricted-role actions, empty/default/search states and manufacturer summary.
- `node --test tests/Frontend/*.test.mjs` →66passed/22existing optional browser
  skips/0failures; new Node syntax and scoped Pint checks passed.
- `npm run build` →79modules, success; generated assets ignored/uncommitted.
  Guarded Blade compile/lint384files/clear, changed PHP lint and diff check passed.
- Guarded actual-demo controller/Blade verifier:10cases passed; synthetic batch
  24customers/32sales/24EMIs and43directory records preserved without mutation.
- Impeccable layout detector:0mechanical findings. Not visual acceptance.

Evidence: `/home/himanshu/jf-table-evidence/customer-directory-toolbar-20261101.3dw29A/`.
Existing Browser Use URL-policy block not retried/circumvented. Actual popup
rendering, mobile geometry, native keyboard/phone/zoom and Turbo Back/Forward
interaction remain NOT RUN; no new screenshots/full PHP suite/benchmarks claimed.
Next: owner refresh `http://localhost:8001/customers` on desktop and phone to review.
Preview8001/privatePG55432 and companion8000 preserved. No push/deploy, primary
checkout/DB, staging/production, OTP/SMTP or mobile-app changes.

## Previous checkpoint — Customer header phone correction

Starting clean HEAD `ec176852e639aba9837699a96112b9cf9f8b0fd8`, same isolated
worktree/branch/sole writer. Owner-supplied 427x952 Chrome-emulation screenshot
confirmed overlapping header controls and a clipped Add Customer after the prior
header move. The earlier source-only checks did not establish mobile visual
acceptance. Later 640px legacy Customers rules set nowrap and actions flex:0 0 auto;
the global mobile notice bell also reserves header padding for an absolute bell.

Narrow correction in the existing 768px media query: only Customer Directory uses
a grid with menu/title/notice on its first row and full-width actions below.
Existing two-column view switches and Add Customer wrap within that full width.
The bell occupies its own column instead of overlaying controls, and its obsolete
reserved right padding is removed for this header only. Add Customer has a 44px
minimum target. The taller phone header scrolls normally, avoiding a fixed block
over rows on short/zoomed screens. Desktop header, other page headers, tab state,
labels, filters, pagination, financial data and permissions are unchanged. No JS,
Blade/component, backend or data changes in this correction.

Exact files: `resources/css/app.css`,
`tests/Frontend/customer-header-layout.test.mjs` and this handoff.

Fresh verification:

- Explicit resolved guard: testing/pgsql/127.0.0.1:5432/exactly jewelflow_testing
  before RefreshDatabase; automatic per-test guard retained.
- RED: five phone CSS-cascade cases failed on the old flex header; desktop passed.
  GREEN: 6/6 cases passed at 320/390/427/640/768px and desktop 1280px. This limited
  contract resolves known competing selectors; it is NOT browser geometry,
  keyboard, real-device, zoom or full CSS-engine evidence.
- `node --test tests/Frontend/*.test.mjs`: 63 passed, 22 existing optional browser
  skips, 0 failures. `node --check tests/Frontend/customer-header-layout.test.mjs`
  passed.
- `APP_ENV=testing DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 DB_DATABASE=jewelflow_testing php8.2 artisan test --compact tests/Feature/Tables tests/Feature/Customer/CustomerManagementTest.php`:
  68 passed / 473 assertions, 0 failures/skips.
- `npm run build`: 79 modules, success; assets rebuilt for local preview only and
  ignored/uncommitted. Guarded Blade compile, 384 PHP lints, clear and diff check passed.
- Ten actual-demo controller/Blade cases passed. 43 directory records and synthetic
  24-customer / 32-sale / 24-EMI batch preserved without mutation.
- Impeccable layout scan: 0 mechanical findings, NOT visual acceptance.

Evidence: `/home/himanshu/jf-table-evidence/customer-header-mobile-20261101.eKjKGL/`.
Existing Browser Use URL-policy block was not retried or circumvented. No new
screenshots or browser-driven mobile/desktop/keyboard/zoom acceptance performed.
Next: owner refresh `http://localhost:8001/customers` at the same phone width to
confirm actual geometry. Preview 8001/private PG 55432 and companion 8000 preserved;
no push/deploy, primary checkout/DB, OTP/SMTP or mobile-app changes.

## Previous checkpoint — Compact Customer header views

Starting clean HEAD `ebc8731d6f08351fcaeabb4d32af57c3563d6a0a`, same isolated
worktree/branch/sole writer. Local commit only; no push or deployment.

Moved the four existing Retail view switches into the Customers page header,
removing the separate content row. Existing labels/icons and gold active state
remain. Header/table share the same existing Alpine `view` state through one
parent wrapper; no store, extra controller, duplicate controls or JS module.
Server-rendered and dynamic aria-pressed indicate the active view. Add Customer
keeps its permission check; manufacturers still have no Retail view switches.
Desktop buttons use compact spacing; phones retain the incumbent two-column
wrapping pattern with44px targets and full labels allowed to wrap. No shared
header component or unrelated page/header selectors were changed.

Exact files: `resources/views/customers/index.blade.php`, `resources/css/app.css`,
`tests/Feature/Tables/ListWorkspaceUiTest.php` and this handoff. New CSS is scoped
to customers-directory-header; incumbent Customer tab styling is extended to it.
List-workspace markers, KPI/filter/pagination location, row indexing, routes and
financial data are unchanged. Header remains outside the table's row scroller.

Fresh checks:

- Test guard resolved testing/pgsql/127.0.0.1:5432/exactly jewelflow_testing before
  RefreshDatabase; same automatic per-test guard remains in force.
- RED:2new tests failed on absent header navigation before implementation.
- `APP_ENV=testing DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 DB_DATABASE=jewelflow_testing php8.2 artisan test --compact tests/Feature/Tables tests/Feature/Customer/CustomerManagementTest.php`
  →68passed/473assertions, no failures/skips. Tests cover one header group,
  unchanged labels, direct EMI selection, common Alpine ancestor with4panels,
  read-only Add-action denial and manufacturer variant.
- `node --test tests/Frontend/*.test.mjs` →57pass/22existing optional browser skips.
- `npm run build` →79modules, success. Assets ignored/uncommitted.
- Guarded preview launcher `view:cache`;384compiled Blade PHP files linted;
  guarded `view:clear`; changed Blade/test PHP lint and git diff check passed.
-10fresh actual-demo controller+Blade cases passed; synthetic24customer/32sale/
  24EMIbatch preserved. No fixture/data mutation for this UI change.
- Impeccable layout scans before/after both0findings. This is mechanical/source
  evidence, NOT browser/computed-style acceptance. Existing Browser Use URL policy
  block was not retried/circumvented. New desktop/phone visual, native keyboard
  and real zoom checks remain manual; no new screenshots or full PHP suite run.

Logs: `/home/himanshu/jf-table-evidence/customer-header-tabs-20261101.nNZLyh/`.
Preview8001/privatePG55432 and companion8000 remain running. Primary checkout/DB,
staging/production, OTP/SMTP and mobile apps untouched. Next: owner refresh
`http://localhost:8001/customers` for visual review; no broader redesign scheduled.

## Previous checkpoint — Customer table row numbering

Starting HEAD `85233dbc98a8178451935cee2fb0490a1172e4c9`, clean tree on
`feature/table-consistency`, same isolated ui-integration worktree/sole writer.
This checkpoint is one local commit; no push or deployment authorized/performed.

Added a narrow, right-aligned `#` column, tabular figures and subtle separator
using the existing shared table palette. Header is named "Row number" for
assistive technology. Directory/Loyalty/EMI use their own paginator offsets:
page2 starts16, and history page2 starts21. Filtered results start1. Unpaginated
occasion/recent-history lists use displayed order. Mobile cards carry matching
numbers. Empty/no-match colspans include the new column. These are row positions,
not persistent customer/invoice/payment IDs. All routes/IDs and receipt targets
are preserved. EMI payment rows now count1..N in their existing newest-first order.

Exact source files (plus this handoff):

- `resources/css/tables.css`: opt-in number-column/mobile-label styles.
- `resources/css/app.css`: preserve EMI column proportions after reserving4rem;
  exclude number/screen-reader spans from mobile metadata's block styles.
- `resources/views/customers/{index,show,history}.blade.php` and
  `resources/views/customers/_occasion-cards.blade.php`.
- `resources/views/installments/{index,show}.blade.php`.
- `resources/views/loyalty/history.blade.php`.
- `resources/views/retailer-reports/occasions.blade.php`.
- `tests/Feature/Tables/CustomerRowNumbersUiTest.php`:6 focused render tests.

No controller/model/service/route/permission/financial/JavaScript changes. Existing
viewport scrolling, pagination, filters and product palettes retained. No changes
to primary checkout, staging/production, OTP/SMTP or mobile applications.

Fresh checks actually performed:

```bash
APP_ENV=testing DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 DB_DATABASE=jewelflow_testing php8.2 artisan test --compact tests/Feature/Tables tests/Feature/Customer/CustomerManagementTest.php
#66 passed /456 assertions, no failures/skips
node --test tests/Frontend/*.test.mjs
#57 passed /22 existing optional browser tests skipped /0 failed
npm run build
#79 modules, succeeded; generated assets stay ignored/uncommitted
php8.2 /home/himanshu/jf-table-evidence/local-preview-20261101.U5Egtv/preview.php view:cache
find storage/framework/views -maxdepth 1 -name '*.php' -print0 | xargs -0 -r -n1 php8.2 -l
#384 compiled views linted
php8.2 /home/himanshu/jf-table-evidence/local-preview-20261101.U5Egtv/preview.php view:clear
git diff --check
```

Resolved test guard verified testing/pgsql/127.0.0.1:5432/exactly jewelflow_testing
before RefreshDatabase; guard also runs automatically before each test setup.
RED: main/history tests failed on missing row header, then passed. Extra profile/
payment tests likewise failed before their markup edits. Test fixture corrections
provided explicit tenant binding context, created_at and shared form errors; no
application gates were bypassed. EMI/history payment snapshots remain unsaved;
receipt targets are checked against deliberately non-sequential fixture IDs.

Ten fresh actual-demo controller+Blade checks passed, including populated
Directory pages1/2/3, Loyalty/EMI page2, searches and both occasion routes. The24
customer/32sales/24EMI synthetic batch is preserved. These are server-render checks,
NOT browser/keyboard/computed-style acceptance. Browser Use URL policy previously
blocked access; no workaround/retry or new screenshots. Desktop/tablet/phone visual
review, keyboard interaction and actual zoom for this addition remain manual.
Full PHP suite not repeated for this scoped presentation change, per task scope.

Impeccable reused incumbent table styling, not a new design. One detector pass
reported6 warnings in unchanged app.css sections (baseline line contents verified
against starting HEAD); none touch the new rules. No unrelated cleanup performed.
Logs: `/home/himanshu/jf-table-evidence/customer-row-numbers-20261101.h2pNoH/`.
Preview remains running at `http://localhost:8001/customers`; refresh to load rebuilt
CSS. Private database55432 and companion8000 processes preserved. Next: user visual
review; no broader redesign/push/deployment.

## Previous checkpoint — Loyalty / EMI / Occasions follow-up

Source `5a8169974f9d861b0ceac7c3d06aad9124039229`, parent
`8202a7adaf9116f7b7c20e7c4f3845520a8277aa`, subject
`fix(customers): complete occasion workspace and mobile records`.
Clean starting tree, same branch/worktree/sole writer; this update is docs only.

All three Customers tabs already inherited the preceding shared viewport layout.
Verified actual tab switches, filters, sticky headings and phone fallbacks without
duplicating layout code. `/loyalty` redirects to its Customers tab; `/installments`
already opts in. Found and closed a separate live-page gap: `/report/occasions`
had neither workspace markers nor mobile cards. Its inherited Customers CSS hides
the table on phones, leaving records/empty feedback unavailable. It now uses the
same fitted rows/amber scrollbar and reuses the inline tab's existing mobile cards.

Exact source files:

- `resources/views/retailer-reports/occasions.blade.php`: workspace/frame/named
  focusable scroll-region markers; include shared mobile cards after the table.
- `resources/views/customers/_occasion-cards.blade.php`: extracted unchanged
  mobile markup, including date/due-in/profile link and empty feedback.
- `resources/views/customers/index.blade.php`: replace the original card block
  with that include, preserving the same fields, routes, copy and framing.
- `tests/Feature/Tables/ListWorkspaceUiTest.php`: report contract, populated mobile
  records for both routes, filtered Loyalty pagination and unsaved EMI snapshots.

No CSS/JS/controller/route/model/permission/calculation changes. Existing Loyalty/
EMI pagination remains outside rows. Occasions is an existing unpaginated collection;
no new business paging rule. Static table inventory unchanged; now25 explicit
workspace templates/28 row regions (previous24/27 plus the Occasion report).

Fresh verification:

```bash
APP_ENV=testing DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 DB_DATABASE=jewelflow_testing php8.2 artisan test --compact tests/Feature/Tables tests/Feature/Customer/CustomerManagementTest.php
#60 passed /392 assertions, no failures/skips
node --test tests/Frontend/list-workspace.test.mjs tests/Frontend/table-row-actions.test.mjs
#18 passed, no failures/skips
npx vite build
#79 modules
php8.2 artisan view:cache
find storage/framework/views -maxdepth 1 -name '*.php' -print0 | xargs -0 -r -n1 php8.2 -l
#384 compiled Blade views linted
php8.2 artisan view:clear
```

All4 changed/new PHP/Blade files linted; diff check passed; scoped Impeccable scan
returned[]. Guard resolved testing/pgsql/127.0.0.1:5432/exactly jewelflow_testing
before RefreshDatabase. RED caught both missing report markers and mobile cards.
Date fixture frozen at midnight; Loyalty assertions scoped to its panel because
the hidden Directory also renders names. No application behavior altered to pass.
PHP covers2 actual synthetic occasion records on both routes and Loyalty page2
of16 test members. EMI renders15 unsaved snapshots with31-total paginator: NO
persisted invoices, plans or payments. Demo data untouched.

Actual local browser: all3 tabs fit588px row regions at1440x1000 with sticky
headings/page1000. Loyalty3 demo records, search retainsview; EMI Completed GET
retainsview; Occasions90-day GET retainsview. Enhanced menus visible over rows.
Standalone Installments fits; Loyalty alias stays local. Separate Occasion report
now fits515px rows with sticky head/amber thumb.390x844: before had0 visible tables
and0 card containers; after shows the shared mobile feedback/card container.
All3 tabs at390px retain normal flow/no horizontal page overflow; Loyalty3cards,
EMI/Occasions empty feedback. The latter have no populated browser fixtures: the
populated PHP renders above are not presented as populated browser acceptance.

Evidence under the same external directory: `customer-tabs-{red,php,frontend,build,
blade}.log`, `customer-tabs-detector.json` and `customer-tabs-phone-checks.json`.
Screenshots: `customer-loyalty-desktop.jpg`, `customer-emi-desktop.jpg`,
`customer-occasions-tab-desktop.jpg`, `customer-{loyalty,emi,occasions}-phone.jpg`,
`customer-occasions-report-desktop-after.jpg`,
`customer-occasions-report-phone-before.jpg`, `customer-occasions-report-phone-after.jpg`.
Physical phone, native keyboard traversal/real zoom and dense populated browser
EMI/Occasions remain unrun. Full PHP suite not repeated for this focused Blade
change. Local preview/private PostgreSQL preserved; no deployment/financial actions.
Next: owner visual review. Viewport override reset and Loyalty retained for review.

## Previous checkpoint — responsive list workspaces and scrollbars

Source commit `07b42d0a9e4aee1eabea83c44d13f1e30177ef36`, parent
`76a331220d22381268e0746d6f8f61e4f76cc345`, subject
`fix(tables): retain list controls while records scroll`.
Same isolated ui-integration worktree/feature/table-consistency, sole writer.
Clean start; no unidentified work discarded. This checkpoint is documentation only.

Shared opt-in layout measures actual remaining viewport across Retail's internal
page scroller and Dhiran/admin document layouts. At >=1024x800, actions, tabs,
KPIs, filters and pagination remain outside vertically scrolling rows; original
table headers are sticky, including existing pinned action-column intersections.
No duplicated header table, fixed table height, dependency or body overflow rule.
Short/narrow, unavailable observers, multiple visible lists, or controls too tall
for a12rem minimum row area fall back to ordinary page flow. Lifecycle handles
font/header resizing, Alpine visibility and Turbo cleanup/restoration. Native
scrollbars use the owner's14px/3px-radius sample in existing amber/light palettes,
with Firefox fallback and forced-colors reset. No business calculation changes.

Exact source files (29):

- `resources/css/tables.css`, `resources/js/app.js`, new `resources/js/list-workspace.js`.
- `resources/views/customers/index.blade.php` (4tabs), `vendors/index.blade.php`,
  `invoices/index.blade.php`, `quick-bills/index.blade.php`, `installments/index.blade.php`,
  `inventory/items/index.blade.php` (stock register ONLY), `inventory/purchases/index.blade.php`,
  `karigars/index.blade.php`, `products/index.blade.php`, `job-orders/index.blade.php`,
  `karigar-invoices/index.blade.php` (all under `resources/views/`).
- `resources/views/dhiran/borrowers/index.blade.php`, `dhiran/loans.blade.php` and
  `dhiran/reports/{active,overdue,interest,forfeiture,profitability,cashbook}.blade.php`.
- `resources/views/super-admin/{shops,users,subscriptions,invoices,edition-requests}/index.blade.php`.
  Selected-shop toolbar reserves its own space in fitted mode; no business action changed.
- New `tests/Feature/Tables/ListWorkspaceUiTest.php` and `tests/Frontend/list-workspace.test.mjs`.

24 templates/27regions. Existing static inventory remains214=178shared+32exceptions+
4editable grids. This does not claim every table is a viewport workspace. Repairs
includes intake, Historical has two independent registers, Returns/Exchange mixes
workflows, Reorder mixes alerts/rules, Cash Book has a drawer form, Staff has cards/
role sections. These, Stock Aging/Sell Trend analytics, detail pages, dashboards,
onboarding, print/PDF/email/receipts and editable POS/Historical/receive grids stay
normal flow. Other non-register/reporting documents are not globally opted in.

Verification actually performed:

```bash
APP_ENV=testing DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 DB_DATABASE=jewelflow_testing php8.2 artisan test --compact tests/Feature/Tables tests/Feature/Customer/CustomerManagementTest.php
#56 passed /357 assertions,0 failures/skips
node --test tests/Frontend/list-workspace.test.mjs tests/Frontend/table-row-actions.test.mjs
#18 passed,0 failures/skips
node --test tests/Frontend/*.test.mjs
#57 passed,22 existing optional customer-photo browser tests skipped,0 failed
npx vite build
#79 modules; build artifacts remain ignored/uncommitted
php8.2 artisan view:cache
find storage/framework/views -maxdepth 1 -name '*.php' -print0 | xargs -0 -r -n1 php8.2 -l
#383 compiled views linted
php8.2 artisan view:clear
git diff --name-only -- '*.php' | xargs -r -n1 php8.2 -l
php8.2 -l tests/Feature/Tables/ListWorkspaceUiTest.php
node --check resources/js/list-workspace.js
node --check resources/js/app.js
git diff --check
```

Resolved guard verified before destructive setup and again at closure: testing,
pgsql,127.0.0.1:5432,exactly jewelflow_testing. Private demo55432 untouched by tests.
RED recorded before module/markers. Additional test fixture corrections (Retail
job-work edition, Dhiran host/email and deterministic created_at ordering) changed
only the new test file, no middleware bypass/application behavior. Full PHP suite
not repeated for this scoped layout change; prior full-suite evidence stays prior.

Actual authenticated local browser evidence:

- Customers31 synthetic rows: search plus pages1/2/3(15/15/1); wheel moves rows557px
  while page stays0. Sticky head top378.375 equals row viewport top. Last real row
  bottom897.375<=viewport897.797; pager bottom950.797. First/last focused View and
  BODY/top-layer labels visible; last focused button844.375..880.375 is unobscured.
  Loyalty panel remeasures. View and repeated safe Back/Forward restore fitting.
-1440x1000 and1024x800 fit (latter330px row viewport/pager bottom762.5).1440x650
  and960x640 revert to page flow;390x844 retains mobile cards with no page overflow.
 960x640 is an effective-viewport check, NOT actual browser zoom evidence.
-1024px Invoices horizontal wheel337px: head/cell left both-96, pinned action
  right both1002.797, no horizontal page overflow. Status dropdown opened/closed.
- Normal local Dhiran login: existing synthetic borrower/loan; headers sticky,
  page fits1000px.390px Borrowers table820px stays inside324px region, no page overflow.
- Normal local admin login via log-mail challenge: Users5 demo records, Shops2.
  Selection reserves78px toolbar rather than covering rows/footer; cleared without
  submission.390px Users table720px stays in360px region, no page overflow.
-23of24templates opened:9Retail registers +8Dhiran +5admin +Customers. Products
  has manufacturer HTTP/render coverage only. Empty financial/report message rows
  are NOT evidence of populated reports; no loans/accounting entries created.

Limits: native Tab/PageDown attempts did not advance under this browser driver;
focus visibility was verified, but a complete real keyboard walkthrough is still
manual. Physical phone, actual browser zoom, screen reader, Firefox/forced-colors
rendering, manufacturer browser and densely populated financial reports remain
unrun. Impeccable's single scoped scan found one inherited hidden Products modal
img src=""; baseline identical and openImageModal fills src before display. It was
not changed/waived as an unrelated scrolling defect. No blanket theme/CSS rewrite.

External evidence remains `/home/himanshu/jf-table-evidence/takeover-20261101.KzNebv/`:
`LIST-WORKSPACE-COVERAGE.md`, `list-workspace-live-coverage.json`, RED/final PHP and
frontend logs, build/source/Blade lint logs and detector JSON. Representative shots:
`list-customers-desktop.jpg`, `list-customers-phone.jpg`, `list-customers-short.jpg`,
`list-customers-reduced-viewport.jpg`, `list-dhiran-borrowers-desktop.jpg`,
`list-dhiran-loans-desktop.jpg`, `list-dhiran-borrowers-phone.jpg`,
`list-admin-users-desktop.jpg`, `list-admin-users-phone.jpg`,
`list-admin-selection-desktop.jpg` (all synthetic data).

No customer/financial mutations/new fixtures, account deletion, service interruption
or deployment. Synthetic logins/local log-mail challenge and transient GET filters/
tabs/selection only. Initial localhost /dhiran/login unexpectedly followed its
existing remote login alias; no remote credentials/forms/actions followed. All
Dhiran acceptance then used explicit `http://dhiran.localhost:8001` exclusively.
Preview8001/private PostgreSQL55432 remain running. Next: owner visual review and
manual native-keyboard/zoom check. Owner additionally asked why Search/Status look
different: measured both40px height/same border/background/radius/shadow; different
widths and icon/arrow padding explain proportions. No extra filter redesign made.

## Previous checkpoint — Masters grouped list (supersedes cards)

- Owner explicitly approved a non-card design: plain grouped headings and slim
  full-width clickable rows with icons, titles, descriptions, chevrons and light
  dividers; no card boxes/shadows. Start was clean at
  `148ce45000cb0e57a4adae255d861ca2a1300aec`, same branch/worktree, sole writer.
- Source commit `14106255ffb8302596c7381d1225de541d47970b`, parent
  `148ce45000cb0e57a4adae255d861ca2a1300aec`, subject
  `refactor(masters): replace cards with grouped navigation rows`.
  Exact files: `resources/css/app.css`, `resources/views/masters/index.blade.php`,
  `tests/Feature/Masters/MastersHubTest.php`,
  `tests/Feature/Masters/MastersHubUiTest.php`. This handoff update is docs only.
- Follow-up heading hierarchy source commit
  `9aa6dc84373d8f4ed6200817fe11333a565d4209`, parent
  `14106255ffb8302596c7381d1225de541d47970b`, subject
  `fix(masters): distinguish section headings from link labels`.
  Exact file: `resources/css/app.css`. Owner identified group headings that were
  visually too similar to links: actual browser group/row sizes were both14px.
  Groups now18px/700 vs rows14px/600,12px heading clearance and28px between groups.
  Actual size comparison failed before the CSS change and passed after it, at
 1440px and320px with no overflow. Vite78modules and diff check passed again.
  No PHP/JS rerun for this CSS-only follow-up; the preceding273/25 results remain
  application/render evidence, not a new full-suite claim.
- Native ul/li groups retain list semantics, named sections, accessible link
  titles/descriptions and existing destination/fragments/order/permissions.
  The stable data-masters-card attribute is deliberately preserved for the
  existing route/access tests; it no longer describes the visual treatment.
  All other Masters-card presentation selectors were removed/renamed.
- Desktop descriptions sit beside titles; at900px and below they stack under
  titles. Row links have transparent backgrounds,0px borders/radii and no shadows;
  li elements provide1px separators. Even the no-access explanation is unboxed.
  Hover supplies a flat tint without movement; focus-visible uses a2px inset
  outline. Existing header/sidebar, controllers, routes and JavaScript unchanged.

Verification:

```bash
APP_ENV=testing DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 DB_DATABASE=jewelflow_testing php8.2 artisan test --compact tests/Feature/Masters tests/Feature/Tables/TableConsistencyUiTest.php
# 273 passed /1187assertions, no failures/skips
APP_ENV=testing DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 DB_DATABASE=jewelflow_testing php8.2 artisan test --compact tests/Feature/Masters/MastersHubUiTest.php tests/Feature/Masters/MastersHubTest.php
# final explicit list-role/indentation refinement:25 passed /190assertions
```

Guard again resolved testing/pgsql/127.0.0.1:5432/jewelflow_testing, including URL
expansion. The new real-render grouped-list test failed before implementation.
No unrelated business/permission tests changed. Vite78modules passed;384 compiled
Blade views linted then cleared; all three changed PHP/Blade files linted and diff
check passed. The same cache/lint/build commands are recorded in the preceding
checkpoint. Detail-only Impeccable scan for Masters returned[]; rendered QA is
separate. No JS changed, no frontend/full-repository rerun or benchmark.

Actual owner UI at1440/1024/390/320px: every row fills its list width; no page or
row overflow, title/arrow overlap or clipped text. Desktop1116px rows and tablet
764px rows are64px high; phone rows grow85.19..104.69px to retain all copy.
Focus outline and named links verified; actual Customers click and Back return to
Masters passed. Hover has no transform/geometry movement. Restricted, multi-edition
and empty states remain actual PHP-render evidence, not separate browser sessions.
No business actions/data changes. Physical phone, screen reader and a complete
native Tab traversal remain unrun. No staging/production deployment.

External evidence directory unchanged: `masters-list-red.log`,
`masters-list-final-php.log`, `masters-list-render-final.log`,
`masters-list-build.log`, `masters-list-blade-lint.log`,
`masters-list-detector.json`, `masters-list-final-measurements.json` and
`masters-list-desktop.jpg`, `masters-list-tablet.jpg`, `masters-list-phone.jpg`,
`masters-list-small-phone.jpg`. Final heading-refinement screenshots:
`masters-list-hierarchy-desktop.jpg`, `masters-list-hierarchy-phone.jpg`;
`masters-list-hierarchy-build.log` records the final asset build. Earlier card and
pre-hierarchy screenshots are superseded, preserved as review history.
Source changes committed; this handoff is documentation-only. Preview remains running at
localhost:8001 with Masters retained for owner review. Next: visual review only.

## Previous checkpoint — Masters hub card refinement (superseded)

- Owner approved the preceding Masters comparison findings. Clean start
  `821f9513068c483272d637c8f86e04dd5d1422ac`, sole writer, same branch/worktree.
- Source commit `43188c17927c207f08b48477db03003e74a32d6b`, parent
  `821f9513068c483272d637c8f86e04dd5d1422ac`, subject
  `fix(masters): align hub navigation with the shared UI`.
- Exact files: `resources/css/app.css`, `resources/views/masters/index.blade.php`,
  `tests/Feature/Masters/MastersHubTest.php`,
  `tests/Feature/Masters/MastersHubUiTest.php`. This handoff update is docs only.
- Kept grouped navigation; replaced generic shadowed utility cards with flat
  links using existing ERP tokens,10px corners/gaps,24px group spacing, smaller
  unboxed icons and decorative chevrons. Wrapping links grow into incomplete rows:
  one fills the group; two split it; tablet's third link fills the next row.
  No fixed/min card width forces phone overflow. Hover does not move the link.
- Visible titles supply accessible names, descriptions remain associated via
  aria-describedby, and decorative icons/arrows stay hidden from the name.
  Native links keep focus-visible2px outlines and existing navigation behavior.
  Card set/order, destinations/fragments/copy, edition/permission filtering and
  empty-state explanation are unchanged. No controller, route, shared layout,
  JavaScript, pricing or business-rule change. CSS remains in the existing Vite
  pipeline rather than a new inline-style system; Reports itself was not rewritten.

Verification actually performed:

```bash
APP_ENV=testing DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 DB_DATABASE=jewelflow_testing php8.2 artisan test --compact tests/Feature/Masters tests/Feature/Tables/TableConsistencyUiTest.php
npx vite build
php8.2 artisan view:cache
find storage/framework/views -name '*.php' -print0 | xargs -0 -r -n 1 php8.2 -l
php8.2 artisan view:clear
php8.2 -l resources/views/masters/index.blade.php
php8.2 -l tests/Feature/Masters/MastersHubTest.php
php8.2 -l tests/Feature/Masters/MastersHubUiTest.php
git diff --check
```

Guard again resolved testing/pgsql/127.0.0.1:5432/jewelflow_testing, including URL
expansion, before tests. **272PHP tests /1183assertions passed; no failures/skips.**
Three new render cases failed before implementation. Initial broader run had
270pass/2fail because two existing UI tests required the intentionally removed
focus:ring/grid-cols utilities. Updated only those UI expectations; all permission,
edition, route and business tests stayed intact. Final fresh run is green.
Vite78modules;384 compiled Blade files linted then cleared; syntax/diff checks pass.
Scoped Impeccable source scan returned[]; visual evidence is separate. No new JS,
so frontend suites were not repeated; no full-repository suite or benchmark.

Actual owner page at1440/1024/390/320px: no horizontal page overflow or title/arrow
overlap. Desktop links94px high, singleton1116px wide, two links553px each; tablet
third-party link fills764px. At320px, text wraps and cards grow to111.69px as needed
rather than clipping. Hover background changes without moving geometry/transform.
Keyboard-visible focus has2px primary outline with2px offset; accessibility tree
uses concise link names. Clicking Customers opens the existing directory and Back
returns to Masters. Restricted/empty/multi-edition states verified by actual PHP
renders, not claimed as separately browsed role sessions. No business actions or
demo-data edits. Physical phone, screen reader and complete native Tab walk remain
unrun. No staging/production work, and paused OTP/photo work remains untouched.

External evidence in `/home/himanshu/jf-table-evidence/takeover-20261101.KzNebv/`:
`masters-red-php.log`, `masters-final-php.log`, `masters-build.log`,
`masters-blade-lint.log`, `masters-final-detector.json`,
`masters-final-browser-measurements.json`; screenshots
`masters-polished-desktop.jpg`, `masters-polished-tablet.jpg`,
`masters-polished-phone.jpg`, `masters-polished-small-phone.jpg`.
Source commit was completely clean. Preview remains running at localhost:8001,
Masters retained for owner review. Next: visual review only; no broader redesign
or deployment without a new request.

## Previous checkpoint — intake polish and repair-detail closure

- Verified clean start `cbf984bc3a4294034d19826bb87b5316072d7934`, sole writer.
  Code commit `5a12c201022e14f10461103fb4e634bca59ccf04`, parent `cbf984bc`,
  `fix(repairs): align intake fields and contain photo previews`.
- Exact intake code files: `resources/views/repairs.blade.php` and
  `tests/Feature/Tables/TableConsistencyUiTest.php`.
- Subsequent detail code commit `22337fcef010d91500c5078cee9aae8017abd8de`,
  parent `5a12c201022e14f10461103fb4e634bca59ccf04`,
  `fix(repairs): polish record progress and accessible actions`.
  Exact files: `resources/css/app.css`, `resources/js/table-row-actions.js`,
  `resources/views/repairs/show.blade.php`,
  `tests/Feature/Tables/TableConsistencyUiTest.php`,
  `tests/Frontend/table-row-actions.test.mjs`. This handoff change is docs only.
- Owner approved all intake-review refinements. Controls now44px, consistent
  font sizing, form-scoped existing border/background tokens, optional hints outside
  labels, compact quantity/cost/date/file widths, aligned tablet notes and tighter
  footer. Container-width breakpoints prevent sidebar-related label wrapping.
- Owner's subsequent tall-photo screenshot exposed grid-row growth. Preview is
  now a44x44 contained thumbnail beside the native chooser with44px Remove.
  Native chooser guidance matches existing JPEG/PNG/WebP/5MB constraints.
  Form action/names/error blocks and server rules unchanged; intake JavaScript
  verified byte-for-byte identical to parent. No business behavior changes.
- Real browser file selection, portrait300x900 and landscape900x300, replacement
  and removal checked without submitting. At320/390/1024/1440/1920, selection leaves
  form height and notes' form-relative positions unchanged; no page overflow.
  Filled customer name/mobile fits within the control; Silver925 and date popup
  work. Desktop form399.5→385px;1024px tablet622.375→489.375px. Phone remains
  full-width single-column1125.734px with explicit hints, not a claimed phone-height
  reduction. Remove preserves all other typed inputs.

Scoped commands (intake and subsequent detail verification):

```bash
APP_ENV=testing DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 DB_DATABASE=jewelflow_testing php8.2 artisan test --compact tests/Feature/Tables/TableConsistencyUiTest.php tests/Feature/Repair tests/Feature/RepairDeliveredVisibilityTest.php tests/Feature/RepairWebImageUploadFlowTest.php
node --test tests/Frontend/table-row-actions.test.mjs tests/Frontend/repair-row-navigation.test.mjs tests/Frontend/repair-a11y.test.mjs tests/Frontend/toast.test.mjs tests/Frontend/repair-challan-share.test.mjs tests/Frontend/customer-photo.test.mjs
npx vite build
php8.2 artisan view:cache
find storage/framework/views -name '*.php' -print0 | xargs -0 -r -n 1 php8.2 -l
php8.2 artisan view:clear
php8.2 -l resources/views/repairs.blade.php
php8.2 -l resources/views/repairs/show.blade.php
php8.2 -l tests/Feature/Tables/TableConsistencyUiTest.php
node --check resources/js/table-row-actions.js
node --check tests/Frontend/table-row-actions.test.mjs
git diff --check
```

Guard resolved testing/pgsql/127.0.0.1:5432/jewelflow_testing, including URL
expansion, before destructive setup. Intake results:64PHP tests/427assertions;
46frontend tests; zero failures/skips.
Vite78modules;383 compiled views linted/cleared; syntax/diff checks passed. Full
repository suite not rerun for these scoped refinements; no performance benchmark.
Impeccable's scoped intake scan flags only the known hidden empty-src preview,
which actually loads selected files. No blanket ignore or design-system rewrite.

The initial **read-only Impeccable audit of `/repairs/14`** found the following.
The owner then explicitly approved all fixes and requested a modern progress
indicator instead of connecting lines. The original audit is retained as baseline.

1. P1: mobile Back/Edit/Print/PDF links lose all accessible/visible labels (36px).
2. P2: view-only role sees Edit, which correctly reaches Access Restricted.
3. P2: desktop/tablet progress connectors strike through labels; current step is
   class-only rather than aria-current.
4. P2: nested main landmarks (shell + detail layout).
5. P3: challan buttons have0px top clearance from header divider.
6. P3: lengthy sharing help persists in the missing-mobile state.

All six are closed in `22337fce`:

- RD01: only compact header text is hidden; links have explicit matching names
  and reuse the existing body/popover focus labels with Escape dismissal. Header
  controls44x44 on narrow screens; challan actions retain their visible text and
  minimum44px height. The320px title fits alongside actions and existing notice bell.
- RD02: Edit now follows `repairs.edit`, within the unchanged lifecycle condition.
  The view-only browser session has no Edit link; direct edit remains403 in tests.
- RD03: connector pseudo-elements removed. Four numbered/checkmarked stage tiles
  distinguish complete/current/upcoming using the existing Retail palette.
  Four columns on desktop;2x2 at1024px and below. One current step uses aria-current.
  This is a read-only display, not a new status mutation control.
- RD04: replaced the nested main with a div; one shell main remains.
- RD05: challan actions have16px header clearance,13px on phones.
- RD06 refined: the existing challan regression requires attachment instructions
  even without a number. Preserve exact instructions inside native expandable
  help, with the missing-number message always visible and live sharing status
  unchanged. No required help was removed.

Four new PHP render/permission tests and a real-controller frontend label test
failed first for the intended defects. A subsequent progress-heading assertion
also failed before the redesign. Final focused checks on the detail source:
**68PHP tests /450assertions;47frontend tests; zero failures/skips**. Vite78modules;
383 compiled Blade files linted then cleared; source PHP/JS lint and diff check
passed. The detail-only Impeccable source scan returned[]; actual browser evidence,
not the detector alone, supports closure. No broad/full-suite rerun or benchmark.

Actual owner browser widths320/390/769/820/1024/1440: no horizontal page overflow,
readable stage labels and responsive actions; one main/current step. At769px,
four tiles initially cramped their inner right padding: tablet2x2 fixes that.
Actual focus label appears in BODY, remains within viewport, and Escape dismisses
without moving focus. Native help click open/close verified; focus-visible summary
verified, but a complete native Tab walk/keyboard-only toggle is not claimed.
View-only rendering checked in its separate existing synthetic session. The bounded
item photo and neutral header treatment were intentional and remain unchanged.
No repair, delivery, billing, payment, messaging, print or download was performed.
No physical phone, screen reader or fresh final Back/Forward pass; an optional
history-tool call timed out and supplied no usable result. No staging/production.

Evidence remains outside Git in `/home/himanshu/jf-table-evidence/takeover-20261101.KzNebv/`:
`RECEIVE-NEW-REPAIR-UI-CLOSURE.md`, `REPAIR-DETAIL-IMPECCABLE-AUDIT.md`,
`repair-form-final-php.log`, `repair-form-final-frontend.log`,
`repair-form-browser-measurements.json`, `repair-detail-audit-measurements.json`,
`REPAIR-DETAIL-UI-CLOSURE.md`, `repair-detail-final-style-measurements.json`,
`repair-detail-final-php.log`, `repair-detail-final-frontend.log`.
Screenshots: `receive-form-polished-photo-wide-desktop.jpg`,
`receive-form-polished-photo-tablet.jpg`, `receive-form-polished-photo-phone.jpg`,
`repair-detail-audit-desktop.jpg`, `repair-detail-audit-phone-top.jpg`,
`repair-detail-modern-desktop.jpg`, `repair-detail-modern-tablet.jpg`,
`repair-detail-modern-phone.jpg`, `repair-detail-modern-small-phone.jpg`.
Existing user/demo records, other worktrees/processes, private PostgreSQL and paused
OTP/photo work preserved. Preview deliberately remains at localhost:8001 and the
owner's repair-detail page is retained for visual review. Scoped code committed;
normal branch push authorized. Next: owner visual review, no additional redesign
or deployment without a new request.

## Previous checkpoint — Codex continuation, 2026-11-01

This section supersedes the outstanding-work and verification statements in the
original Claude handoff below; those remain as historical evidence.

- Verified takeover: `636273ce9026bf0ee3550156d2cd0fa54c754f45`, clean tree,
  branch `feature/table-consistency`, same `ui-integration` worktree. Claude stopped.
- Tested source commit: `5c69a7b76be840c4072b0c5af989dd4a0414cf2b`, parent
  `636273ce9026bf0ee3550156d2cd0fa54c754f45`, subject
  `fix(ui): finish table consistency and accessible repair intake`.
- This handoff update is documentation only. No staging or production deployment.
  The owner authorized an ordinary scoped branch push, not a main merge.

### Completed changes

- Forfeiture is an interactive shared table, with its existing numeric values and
  alignment preserved, actual paginator links exposed, and accurate “Page totals”.
- Shared icon labels use one body/native-popover layer instead of clipped table
  pseudo-elements; focus, hover, Escape and Turbo cleanup are covered. Accessible
  names remain on the actual controls. No further blind icon conversions.
- The legacy30px button rule now excludes `.jf-table`, allowing shared36/44px
  targets. Customer Edit follows `customers.edit`. POS remove controls and action
  headers have meaningful names; entry calculations/validation are unchanged.
- At the owner's explicit request, Repairs intake is above the full-width register,
  with a horizontal desktop form and no four KPI cards. Active/Delivered links sit
  beside search and reflow on phones. Status is in its own column; identifiers are
  plain text and Complete & bill remains explicit text.
- Repairs web pages show10 rows with continuous numbering; existing JSON pagination
  remains25. Search submits through the existing GET endpoint across all pages,
  retaining status; local preview filtering remains available while typing.
  The fixed560px nested vertical scroller is removed; narrow table scrolling stays
  within its card. Clicking non-interactive row space follows the native view link.
- Four subsequently audited issues are fixed: delegated row clicks preserve native
  TR semantics and child keys; placeholder contrast4.52–4.76:1; dynamically rebuilt
  purity choices are native buttons; named native delivery dialog supplies modal
  focus entry/return, Cancel autofocus, Escape/backdrop and before-cache cleanup.
  Customer/metal/purity triggers announce field/expanded state. Quick-created
  customer choices follow the same button presentation; endpoints remain unchanged.

### Exact source/test files in the code commit

```text
app/Http/Controllers/RepairController.php
resources/css/app.css
resources/css/tables.css
resources/js/app.js
resources/js/table-row-actions.js
resources/views/customers/index.blade.php
resources/views/dhiran/reports/forfeiture.blade.php
resources/views/pos_customer_retailer.blade.php
resources/views/repairs.blade.php
tests/Feature/Repair/RepairPageRevisitTest.php
tests/Feature/Tables/TableConsistencyUiTest.php
tests/Frontend/repair-a11y.test.mjs
tests/Frontend/repair-row-navigation.test.mjs
tests/Frontend/table-row-actions.test.mjs
```

The controller change is only the web/JSON paginator size split, not delivery,
pricing, permissions or financial behavior. The existing revisit test still
requires null-safe off-page Escape handling, now using the native close API.

### Checks actually performed

The destructive test guard explicitly resolved `APP_ENV=testing`,
`DB_CONNECTION=pgsql`, `DB_HOST=127.0.0.1`, `DB_PORT=5432`,
`DB_DATABASE=jewelflow_testing`, including URL expansion, before test setup.
No overlapping PHPUnit processes. Preview remains on its separate private55432 DB.

```bash
APP_ENV=testing DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 DB_DATABASE=jewelflow_testing php8.2 artisan test --compact tests/Feature/Tables/TableConsistencyUiTest.php tests/Feature/Customer/CustomerManagementTest.php tests/Feature/Repair tests/Feature/RepairDeliveredVisibilityTest.php tests/Feature/RepairWebImageUploadFlowTest.php tests/Feature/Historical/HistoricalMobileUiTest.php tests/Feature/Icons/LucideFirstLocationsTest.php tests/Feature/PosSalesTest.php
node --test tests/Frontend/table-row-actions.test.mjs tests/Frontend/repair-row-navigation.test.mjs tests/Frontend/repair-a11y.test.mjs tests/Frontend/toast.test.mjs tests/Frontend/repair-challan-share.test.mjs tests/Frontend/customer-photo.test.mjs
npx vite build
php8.2 artisan view:cache
find storage/framework/views -name '*.php' -print0 | xargs -0 -r -n 1 php8.2 -l
php8.2 artisan view:clear
git diff --check
```

Results: **114 PHP tests /4674 assertions;46 frontend tests**, zero failures/skips.
Vite78modules;384 compiled Blade files linted; PHP/JS syntax and diff checks passed.
The old3851PASS/1SKIP full-suite result is Claude's supplied evidence, not a newly
rerun full repository suite. These scoped regressions failed for their intended
reasons before the relevant fixes. The row regression now runs the real global
onclick upgrader, not only the isolated row handler that missed the integration.

Actual local browser checks used synthetic accounts/records, not injected rows:

- Customers search across3 true pages:15/15/1 rows,31 distinct links; Archived2.
  View-only role sees no Create/Edit/Archive; direct edit is access restricted.
- View-only Repairs has View/history, no intake/status/edit/bill controls. Actual
  web paginator shows10 rows, page2 starts11; an off-page synthetic repair is found
  through normal search. Row mouse click opens its view without a business action.
- Day Book7 current-month and3 previous-month rows; numeric credit sorting asc/desc;
  running balances return on date sorting. Safe Back/Forward checked.
- POS1440/1024/390: actual item picker and discount recomputation53560→53457 for
  discount100, then reset; Historical actual10g×7200.1234→72001.23, automatic blank
  row and details/purity controls. Nothing sold, saved, paid, delivered or published.
- Repairs1440/1024/390: no page overflow, readable fields/actions, contained tablet
  scrolling. Coarse-pointer actions44px. Shared focus label uses BODY/top-layer,
  stays inside viewport and dismisses on Escape without losing control focus.
- Actual contrast, rebuilt Silver925 and scrolled Custom, native dialog entry,
  named close, cancellation/Escape focus return were checked. Native Tab synthesis
  was unreliable in this tool: a complete physical/native Tab walk is NOT claimed.

### Inventory, evidence and remaining exceptions

Static inventory reconciles214 tables:178 shared,4 editable grids,32 exceptions
(13 headerless onboarding,19 print/PDF/email). The old20-document count was off by
one. The old72-of-73 crawl was a subset, not whole-inventory coverage. No full
recrawl or performance benchmarking.48 row-action source locations have names/tips.

Evidence is local, outside Git: `/home/himanshu/jf-table-evidence/takeover-20261101.KzNebv/`.
Representative images: `repairs-a11y-desktop.jpg`,
`repairs-a11y-dialog-desktop.jpg`, `repairs-a11y-phone-form.jpg`,
`repairs-a11y-dialog-phone.jpg`, `repairs-register-tablet.jpg`,
`forfeiture-desktop.png`, `pos-tablet.png`, `historical-desktop.png`.
Logs: `final-focused-php.log`, `final-frontend.log`, `final-build.log`,
`blade-lint.log`; audit/closure and live measurements are in the same directory.
Old scaled/tiled screenshots are superseded by the final viewport-only JPGs.

Local fixtures retained for owner review: customers8..40 (31 active/2 archived),
repairs3..14, view-only role7/user5. Existing financial row counts did not change
when these fixtures were created. A later repair15 appeared during owner's preview
use; it was preserved, not created or removed by the audit. Existing users/roles,
other worktrees/processes and private PostgreSQL were preserved. No clean/stash/reset.

Impeccable was installed globally for Codex at the owner's request, without hooks
or repository dependency changes. Operate/hardening guidance kept the incumbent
design; scoped detector's hidden-image warning was verified as intentional preview
markup, not a visible broken image. Inherited decorative CSS elsewhere was not
used as justification for a blanket rewrite.

**Prior separate request: read-only Receive New Repair visual review.** It was
subsequently implemented in `5a12c201`, recorded above. That review found41.5px selectors vs46px
inputs; narrow13px picker text vs16px inputs;40px label reservations; tablet notes
textareas offset20px; generic short-value/file widths; pale field edges; file type/
size guidance missing; spacious action footer. Outer18/14px padding and16px desktop
gaps are consistent. Full details: `RECEIVE-NEW-REPAIR-UI-REVIEW.md` in evidence.

Still not checked: physical phone, actual screen-reader session, complete native
Tab order, populated forfeiture in the browser (PHP-rendered unsaved snapshots
cover its values/paging), populated onboarding, new admin MFA/permission panels.
Earlier baseline admin2fa500 remains outside this task. No staging/production,
mobile app/APK/OTA, OTP or SMTP work. The local preview is deliberately left running
at `http://localhost:8001/repairs` for the owner's visual review.

- **Branch:** `feature/table-consistency` (pushed, no force). **Base:** `3df1baf7` (the integrated UI branch). **Worktree:** `/home/himanshu/Desktop/jewelflow-worktrees/ui-integration`.
- **Commits on top of the base:** `2f928975` shared table pattern + Customers/Invoices/Repairs, `5fec8462` all other tables, `7d4758e3` first review notes, `9971976b` Customers filter row, `a71ea974` action-cell heights, `5032f245` table round 2 (below), then this handoff. `git log 3df1baf7..HEAD` is the full list.
- The older [table-consistency-review-2026-11-01.md](table-consistency-review-2026-11-01.md) describes only the first two commits; where it differs from this file, this file is current.

## What the branch contains
- **Icons:** every icon in Retail, Dhiran and platform admin is a Lucide glyph drawn through `<x-icon name="..." size="..">` (Blade precompiler → `App\View\Icons::svg()`, 150 glyphs in `app/View/Icons.php`, fixtures in `tests/Fixtures/lucide-1.66.0`); icons built by scripts use `@json(\App\View\Icons::svg(...))`. 23 inline SVGs stay on purpose (brand marks, logos/barcodes, printed-document seals). From `feature/lucide-icons-batch1` (`090436da`, merged as `fe4d1a07`) plus `d63d542e`, `5d6e336e`, `3df1baf7`.
- **Companion-web changes in the ancestry:** `feature/mobile-companion-api` at `b1d7f5c4` (companion API and web parity, 2026-10-17..31, including the web Record-payment retry fix) and its 23 previously uncommitted UI files, committed as `4502088c` (header notice bell, quick-bill pricing mode, repair edit purity/revisit, reorder, dashboard).
- **Tables:** 214 `<table>` tags in `resources/views`: 177 on `.jf-table`, 4 on `.jf-grid` (header look only), 33 intentionally or knowingly left (see below).

## Shared table styles: entry points
Everything is in `resources/css/tables.css`, imported by `resources/css/app.css` (Dhiran and admin layouts load it through `app.css` too). Opt-in by class; selectors use child combinators so they beat per-cell Tailwind utilities without `!important`.

| Class | Use |
|---|---|
| `.jf-table-wrap` | scroll container (the table scrolls inside it, never the page) |
| `.jf-table` | header (12px/600/0.05em uppercase), cells (14px/12px, first/last +8px), separators, zebra, hover, focus ring, tabular numbers on `.text-right`/`.jf-num`, phone: tighter + `min-width: 720px` |
| `.jf-table--compact` | 8px side padding (Repairs) |
| `.jf-table--fit` | no forced min-width on phones (bill previews, summaries) |
| `.jf-table--sticky-end` | pins the last column (still used on Invoices only) |
| `.admin-table` / `.dh-app .jf-table` | platform-admin palette (light grey header) / Dhiran palette, through custom properties `--jf-t-*` |
| `.jf-table-actions` (`--center`) | wrapping action cell, every control the same height (36px, 44px on touch) |
| **`.jf-row-action`** (`--view/--edit/--neutral/--danger/--success/--primary`) | **icon-only row action with a floating label**: markup `<a class="jf-row-action jf-row-action--edit" data-tip="Edit"><x-icon name="pencil" size="16" /><span class="jf-sr-only">Edit</span></a>`; label is `data-tip` (above the button on hover and keyboard focus), accessible name stays in the hidden span; 36px, 44px on touch |
| `td.jf-table-empty`, `.jf-indent`, `.jf-cell-clip` | empty/no-match row, indented summary row, focusable expandable clip (unused so far) |
| `.jf-grid` | editable entry grids: only the header text takes the shared look; their cells, borders, inputs and keyboard behaviour are untouched |

Other entry points: the older global "Data tables" block in `app.css` excludes `.jf-table` (`table:not(.jf-table)`); the global typography rule that forces weight 500 on `.font-semibold/.font-bold` inside tables now skips `.jf-table thead th` (that rule made some headers lighter than others).

**Remaining page-specific overrides (not removed on purpose):** column widths and wrapping rules in page `<style>` blocks (Repairs: Customer/Item wrap, action cell `width: 1%`; Vendors `min-width: 820px`; karigar-invoice show `min-width: 860px`; the Customers toolbar grid override in `app.css`); mobile card lists next to the tables (untouched, they keep text buttons). **Dead CSS left behind** by the row-action conversion (no longer referenced by table cells, safe to delete in a clean-up): `customers-row-action`, `vendors-link-btn`, `sp-row-action`, `qb-action`, `karigars-row-btn`, `reorder-action-*`, `installments-show-receipt-link`, `sub-billing-view`.

## Still inconsistent or untouched, by exact file and reason
1. **`resources/views/dhiran/reports/forfeiture.blade.php`: a real screen table that was missed.** Route `dhiran.reports.forfeiture` (`/dhiran/reports/forfeiture`), a plain `w-full border-collapse` table with Tailwind header/cells. It was classified as a print document by file name and not on the crawl list. **Not migrated; first thing to do** (add `jf-table`, check numeric alignment, Principal/Written Off already right-aligned, Items Count centred).
2. **`onboarding/index.blade.php` (12 tables) and `onboarding/_staged.blade.php` (1):** header-less, inline-styled key/value lists in the one-time opening-balance wizard; no header row to share and not renderable with demo data. Untouched. (`onboarding/suppliers` is migrated.)
3. **Print / PDF / email / receipt documents, kept separate by design (20 tables):** `invoice_print` (2), `quick-bills/print` (2), `karigar-invoices/print` (2), `repairs/challan` (3), `job-orders/challan`, `job-orders/return-doc`, `returns/exchanges/receipt` (2), `schemes/payment-receipt`, `dhiran/receipt` (also shown at `/dhiran/loans/{id}/receipt`), `dhiran/closure-certificate`, `emails/platform-invoice`, `reporting/layouts/report-document` (PDF layout), `reports/gst` (the print-only summary head).
4. **Editable grids, header look only (`.jf-grid`):** `historical/_manual-form-fields` (desktop grid), `job-orders/receive`, POS `items-table` and `items-tablet-table` in `pos_customer_retailer`. Spacing, borders and inputs deliberately unchanged. **Not looked at on screen after the header change** (see "not verified").
5. **Row actions still text:** create, financial and approval actions (Pay, Collect, Approve/Deny, New loan ...) and any control whose label is dynamic (`@if` or `{{ }}` inside the control) were left as text on purpose; I did not enumerate them. Example: the `Reverse` button in `karigar-invoices/show` payments. A grep of `tbody` buttons/links with visible words is the way to review them.

Tabs, dialogs and expanded sections: the conformity crawl un-hid every hidden tab/dialog/`<details>` table on the 107 crawled pages and read its real computed styles (synthetic rows where empty); all but the Dhiran loan receipt were on `.jf-table`. Pages not in the crawl list were checked only through the static count above, which is how item 1 surfaced.

## Repairs pinned-action overlap: fixed
The sticky action column is gone (`jf-table--compact`, no `--sticky-end`), so it can no longer cover Purity or Status. Customer and Item wrap, the action cell shows icon-only View, status select, Edit, "Complete & bill" (or View invoice). Measured: at 1918px wide the table fits (no scroll); at a 1280px viewport the Repairs list card is 624px and the 940px table scrolls inside the card with nothing overlapping. Not done: further column merging to avoid that scroll at 1280.

## Bugs found, fixes, outstanding
- Fixed: Customers toolbar wrapped the Filter button under Search; Archive was 44px next to 36px links (now one height for every control); header cells centred by default (now start-aligned); numeric dates mis-right-aligned (Next Due); table headers with `font-semibold` rendered at 500 by a global `!important` rule; Vendors kept its own 11px/0.18em header and 18px/24px cells; admin dashboard "Users" column was centred under a left header; admin tables briefly got a dark header (light `#f1f3f9` now); `php -l` of compiled views is needed because `view:cache` does not catch parse errors; 3 tests asserting the old text-button markup (`HistoricalMobileUiTest` x2, `LucideFirstLocationsTest`) now assert the icon-only row actions.
- Outstanding: item 1 above; leftover dead CSS; `/admin/2fa/manage` returns 500 on the baseline too (unrelated, already reported); in the admin permissions panel the checkboxes look faint (only seen with injected markup, unverified whether pre-existing); the platform-admins table scrolls inside its card at 1440 (last column partly off-screen until scrolled: contained scroll, no overlay).

## Checks actually run
- **Tested commit `5032f245`** (the same code as this branch's HEAD; this handoff changes docs only). Quick gate (assets build, `view:cache`, `php -l` on all 383 compiled views): 0 errors, run on the same tree before the commit. Full PHP suite run on exactly that commit: 3851 passed, 1 skipped, 0 failed (22,744 assertions).
- Earlier full run on the uncommitted tree before three test fixes: 3 failed, 3848 passed, 1 skipped; the three failures were the markup assertions above; both files re-run after the fix: 27 passed.
- **Conformity crawl** (headless Chrome against the local server, desktop 1440, 107 pages as the Retail owner, Dhiran owner and platform admin, hidden tabs/dialogs revealed, synthetic client-side rows for empty tables): 73 tables, 72 on `.jf-table`, 1 print receipt, 1 flagged for alignment that is a synthetic-row artifact (real cells carry the same `is-center` class). Before the corrections: 3 non-`.jf-table` and 16 deviating.
- **Admin `<details>` panels** (platform-admins permissions and role, edition-requests approve/deny) opened with synthetic rows injected into the DOM (nothing saved): 4 of 4 not clipped, text contrast 4.6:1 or better. The real markup was copied; the injected row has fewer columns than the real one.
- **Interactions** in the browser pane: Repairs client search (match, no-match row, restore), Customers search and status filter (6 rows, 1, 0, 0 for archived), invoice list queries, the day-book report column sort ascending and descending, Tab to the first row action on Vendors (focus ring + floating label), hover label on Repairs, 44px size under a touch pointer, no page overflow at 375px on Customers, Invoices, Inventory.
- Earlier (first batch): 214 page views audited desktop + phone, interaction checks, a cashier account, long text. Details in the older review notes.

## Not verified
Staging, a physical phone, tablet width, restricted roles (cashier) for this round, populated onboarding pages, multi-page pagination (the demo has 3 invoices, page 2 renders the empty state with links), POS and Historical grids after the `.jf-grid` header change, real (not injected) admin panels, screenshots of the after state of this round (only the two admin panel shots exist), any Dhiran page interaction.

## Evidence and before/after
Folder `/home/himanshu/jf-customer-photo-evidence/ui-icons-impl-20261031/rollout/` (outside the repo): `shots/before-retail-{customers,invoices,repairs}-desktop.png` vs `shots/final-retail-*-desktop.png`; `shots/<page>-<viewport>-{base,after}.png` for the icon rollout; `audit-before.json` / `audit-final.json` / `audit-conform.json` (DOM audits); `details-open.json` and `shots/details-platform-admins.png`, `shots/details-edition-requests.png`; `diff-report.txt`. Representative changes: Repairs (pinned action column over Purity/Status → unpinned, wrapped, icon actions); Invoices (clipped Actions column and fixed widths → all ten columns fit at 1440, icon actions through the shared component); Customers (crumpled header and 44px Archive → even header, one control height); header weight (500 vs 600 on `font-semibold` headers → 600); Vendors (11px/0.18em/18px cells → shared 12px/0.05em/14px).

## Local preview
- **Preview server (left running deliberately):** `php8.2 artisan serve --host=127.0.0.1 --port=8001 --no-reload` from the worktree, in the Claude terminal tab "UI integrated 8001". Retail: `http://localhost:8001`, Dhiran: `http://dhiran.localhost:8001`, platform admin: `http://localhost:8001/admin/login`. `.env` (not committed) points at a private PostgreSQL on `127.0.0.1:55432`, database `jewelflow_mobile_demo`, data dir `/tmp/claude-1000/mobile-demo-pg` (lost on reboot); mail goes to the log, so the admin MFA code is read from `storage/logs/laravel*.log`.
- Also running, not needed for tables: the companion API demo backend on `:8000` (from the `mobile-companion-api` worktree, same private database). Stop it only if the mobile demo is not in use.
- **Demo sign-ins:** Retail owner `9000000111` (seed password, see the seeders), Dhiran owner `9000000301` (local test password kept outside the repo), platform admin `9000000100` (seed password + the emailed code), cashier `9000000113`. Never type credentials into any remote site.
- **Rebuild after CSS changes:** `npx vite build` in the worktree (the server serves `public/build`). Quick gate script: `/home/himanshu/Desktop/jewelflow-worktrees/build-logs/ui-integration-quick.sh`.
- **Tests:** `php8.2 artisan test` against `jewelflow_testing` on port 5432 (`.env.testing`); tests wipe that database, so do not run two runs at once.
- **Synthetic data:** no database rows were added for the table work. Empty tables are checked by injecting rows into the DOM: `build-logs/tables-conform.js` (all tables, hidden ones un-hidden) driven by `tables-crawl.cjs`; `admin-details.cjs` injects admin panels. Codemods used, all in `build-logs/` (outside the repo, local): `tables-row-actions.py` (dry-run by default, `--write`), `bill-previews.py`, `grids-and-summaries.py`, `css-drop-rules.py`.
- **Sessions to reuse:** the Browser pane tab on `localhost:8001` is signed in as the Retail owner; the terminal tabs and that tab belong to the Claude app, so Codex should open its own browser session and sign in with the demo accounts above.

## Next concrete task for Codex
Migrate `dhiran/reports/forfeiture.blade.php` to `.jf-table` (item 1), look at the POS and Historical grids on screen after the `.jf-grid` header change, then review the remaining text-labelled row actions (item 5) one table at a time.

## Standing instructions (owner)
Work on the web UI element by element: icons, then complete tables; no unrelated redesign. Prioritise UI/UX; no dedicated performance benchmarking. Preserve functionality, permissions, accessibility and responsive behaviour. No staging deployment or testing until the owner explicitly asks. No production, mobile-app, APK/AAB, OTA, OTP or SMTP work.
