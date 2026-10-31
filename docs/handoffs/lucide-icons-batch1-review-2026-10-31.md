# Lucide icons: notes for source review

Branch `feature/lucide-icons-batch1`, based on `origin/main` d7dcfb03. Interface icons only: no CSS, JavaScript, controllers, routes, permissions, labels or layout were changed, and the mobile app is untouched.

| Commit | What |
|---|---|
| `7fa675b7` | Step 1: the `<x-icon>` component, the compile hook, six first locations (reviewed) |
| `d7fa3433` | Review notes for step 1 (reviewed) |
| `5c89a11a` | Renderer fixes from that review: attribute values escaped exactly once; Blade-protected blocks left alone |
| `f4c2e1ef` | Rollout pass 1: 749 inline SVG icons in 165 views drawn through `<x-icon>` |
| next commit | Rollout pass 2 (102 more), platform-admin emoji icons, Karigars hammer, tests |
| this note | Final coverage and verification |

```bash
git diff d7dcfb03 feature/lucide-icons-batch1 --stat      # whole branch
git diff d7fa3433 feature/lucide-icons-batch1 -- app tests bootstrap   # code and tests since the reviewed head
```

## Files to read first
| File | What it is |
|---|---|
| `app/View/Icons.php` | Glyph table (137 official Lucide 1.66.0 glyphs), the one renderer `svg()`, the compiler `compileTags()` |
| `app/Providers/IconServiceProvider.php` | Registers the compile hook (`Blade::prepareStringsForCompilationUsing`) |
| `resources/views/components/icon.blade.php` | `<x-icon>` fallback component; calls the same `svg()` |
| `tests/Feature/Icons/*`, `tests/Fixtures/lucide-1.66.0/*` | Tests; one official SVG fixture per glyph |

## The renderer (after the review)
- **Compile hook.** A plain `<x-icon name="…" />` costs about 57 µs as a Blade component, so templates containing `<x-icon` are rewritten at compile time into a direct `Icons::svg()` call (about 2.5 µs). Only self-closing tags whose attributes are plain text are rewritten; `:bound`, `@event`, Alpine `x-*`, `{{ }}`/`$var`/`@` values, single-quoted values and closing tags stay components (about 38 µs, avoid in loops).
- **Escaping.** Blade has already escaped a bound attribute (`:title="$t"`) before it reaches the attribute bag, and a static attribute is HTML the author wrote. `svg()` therefore escapes with `e($value, false)` (no double-encoding): a bare `& < > "` is escaped once, an existing entity is left alone. Tests assert the real DOM attribute value for bound, static and `label` paths, through both the compiled call and the component.
- **Protected blocks.** The hook runs before Blade stores `@verbatim`, `@php` blocks and `{{-- --}}` comments, so `compileTags()` splits on the same three patterns and rewrites only the text between them. A tag shown inside `@verbatim`, kept as a string in `@php`, or written in a comment is left exactly as written.
- **Output.** `viewBox 0 0 24 24`, `fill none`, `stroke currentColor`, `stroke-width 2` unless the tag gives its own, round caps/joins, `data-icon`. Decorative by default (`aria-hidden="true" focusable="false"`); `label` gives `role="img" aria-label`. `size` sets width and height; where CSS or a class already sizes the icon the tag carries only the class.
- **View cache.** Changing `compileTags()` does not invalidate compiled views; run `php artisan view:clear` after deploying a compiler change. Adding a glyph needs no recompile.

## The rollout
Method: every inline `<svg>` in the Blade views was inventoried (985), grouped by drawing (360 distinct), each drawing was matched against all 2,292 Lucide icons and the match was **reviewed by eye on contact sheets** against the words next to the icon; only drawings with a clear like-for-like Lucide equivalent were mapped. A codemod then swapped them, re-checking the original markup byte for byte first. It carries over `width`/`height` (as `size`) or the existing sizing classes, `class`, `style`, `id`, colour (`stroke`), non-default `stroke-width` and `opacity`.

| Area | Inline SVGs | Drawn through `<x-icon>` |
|---|---|---|
| Retail | 869 | 744 |
| Dhiran | 112 | 104 |
| Platform admin | 4 (+12 emoji icons) | 4 (+11 emoji icons through `<x-icon>`, 1 through `Icons::svg()`) |

Branch total: 881 `<x-icon>` tags in 172 views, 137 glyphs. Pass 1 mapped 204 drawings, pass 2 another 100 (using the surrounding label text), plus hand edits for the admin emoji.

Mapping decisions that are the owner's: Pencil = Edit (`pencil`, `pencil-line`, `square-pen` for the boxed variant); `users` for customer lists/groups, `user` for an individual; `plus` stays on Add/Create; `wrench` = Repairs; **`hammer` = Karigars** (card and sidebar, so Repairs and Karigars no longer share an icon); `trash-2` for every Delete; balance-scale drawings → `scale` like for like; Gem untouched.

## Intentional exceptions (left as they were)
1. **Not interface icons**: logos and illustrations (`viewBox` 32/10x6/220, 5 svgs).
2. **Not plain markup**: 20 svgs inside `<script>`/`<style>`/`@php`/comments, 2 built inside JS or attribute strings, 19 with Blade/Alpine attributes (`:class`, `x-show`, `{{ }}`) stay inline.
3. **Solid/filled glyphs** (36 svgs, 20 drawings, e.g. status dots, filled info/chat marks): Lucide is an outline set; mixing would change the look.
4. **One-off outline drawings with no clear Lucide equivalent or unclear meaning** (51 svgs, 38 drawings), including **Gem** (deferred until destination and meaning are mapped), the generic report KPI variants, a few settings tabs (Plan & Billing), the purity mark and the dashboard search trigger (page CSS fills it).
5. **Emoji and text symbols**: `<option>` text cannot hold an SVG (platform-admin bulk-action menu); JS-built strings (`⚠ ` in a script); inline `✓`/`⚠` characters that are part of a sentence (warnings, "Reconciled", "Verified"); the large 🔧/📷 on the maintenance page and the camera-permission screen.
6. **Stroke weights** keep the original per-icon values (1.5 to 2.5, mostly 2 to 2.25), so no icon changed weight; unifying them is a one-line follow-up if wanted.
7. `super-admin/layout.blade.php` (gear and lock-with-key in its header) was changed too, but that legacy layout does not render today (`/admin/2fa/manage` returns 500 on the pristine build as well), so it has no page to test.

## Final pass on the integrated branch (`integration/ui-all`)
The icon branch was merged with the companion web branch (header notice bell and the other UI work that was uncommitted there) and the remaining icons were finished on the combined tree. **This supersedes the exception list above**: 975 `<x-icon>` tags plus 22 `Icons::svg()` calls (icons that scripts build at run time), 150 glyphs, **23 inline SVGs left out of about 1,020**.
- The one-off outline drawings and the solid ones (except the groups listed below) were mapped by their labels to the closest Lucide icon (KPI cards, settings tabs, plans page, vendor/member/returns pages, receipts, status marks), solid ones becoming outline icons in the same colour.
- Icons with bound attributes or lookup tables are drawn through the component too: repair status chips, report-section icons, customer-photo actions, notice-bell rows, login modal icons, eye/eye-off toggles (`x-show`), rotating chevrons (`x-bind:class`), spinners (`animate-spin` kept). The dashboard search trigger needed one CSS line (`fill: none` instead of a fill colour) because the page filled the old solid icon.
- Icons that scripts build at run time (the dashboard Ctrl+K results, POS cart marks, the remove-image button on the retailer item forms) ask the server for the finished markup with `@json(\App\View\Icons::svg(...))`, so the glyph table stays the only copy of every drawing; the rendered scripts are syntax-checked and the palette was exercised in the browser.
- Left on purpose (23): brand marks (WhatsApp, Instagram, Facebook; 7); logos and illustrations (6); print barcodes (2); the two **Gem** marks (deferred); the metal-exchange gold/silver dots (2); the seals on printed Dhiran/EMI receipts and certificates (3); the audit-trail glyph whose path comes from a model.
- `view:cache` does not catch a parse error in the generated PHP, so the check is now `view:cache` followed by `php -l` on every compiled view (383 files, 0 errors).

## Verification (local, against a pristine baseline worktree at d7dcfb03)
- **Tests.** Icons: glyphs equal the official SVGs, table and fixtures list the same icons, every `<x-icon name>` in a view is a known glyph, escaping and protected-block regressions, admin emoji replacements. One existing test (`DhiranOnboardingTest`) treated `<x-icon>` as app chrome inside a printable document; it now ignores `<x-icon>`.
- **Views.** All 330 Blade views compile with `view:cache`; every `<x-icon>` tag in the views compiles to the direct call.
- **Pages.** Retail, Dhiran and platform admin were crawled by link (106 pages) plus three dialogs, desktop 1440 and phone 390, on both builds: 218 page views, 5,224 icons drawn. Pixel diff: nothing outside the icon boxes differs in any Retail or Dhiran page (one 2-pixel speck on the invoices phone view); the only other differences are two admin pages where an emoji became an icon (title shifts 4 px left) and admin tables whose rows are live audit events. No page gained horizontal overflow, none changed height except those admin pages, no new console or network errors, svg counts equal on all but the four admin pages with new icons.
- **Turbo.** 16 sidebar visits through Turbo, then back and forward: icons draw on every visit, none collapses to zero size.
- **Performance.** CSS and JS payload and request counts unchanged; layout shift unchanged (same CLS); server time per page within noise (10 pages, 60 alternating requests each: median −0.2 ms, range −1.8 to +0.8 ms); HTML grows −0.1 to +4.4% raw (median +1.4%) over those 10 pages and +1.3 to +4.0% gzipped (+0.3 to +0.55 KB) over the four browser-measured pages; DOM nodes +0.5 to +3.1%; browser FCP/load within the ±10–20% noise floor.

## Known limits
- The `data-icon` attribute adds about 20 bytes per icon (tests and auditing use it).
- A bare `& < >` inside a static `label`/`title` is escaped once; an existing entity such as `&amp;` is left as is.
- Raw SVG strings inside JavaScript (toast, dialogs built in script) still draw the old icons.

## Tests
```bash
php artisan test tests/Feature/Icons tests/Feature/Masters tests/Feature/DhiranOnboardingTest.php
```
