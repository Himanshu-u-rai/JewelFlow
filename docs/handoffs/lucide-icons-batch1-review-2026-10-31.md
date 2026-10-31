# Lucide icons, first step: notes for source review

Branch `feature/lucide-icons-batch1`, based on `origin/main` d7dcfb03. Code commit **7fa675b7**; the commit that adds this file sits on top of it and is the branch head. Review the whole change with:

```bash
git diff d7dcfb03 feature/lucide-icons-batch1          # 19 files, code + tests + this note
git diff d7dcfb03 7fa675b7                             # code and tests only (18 files)
```

Scope of this step: six icon locations (quick-bill header Print/Edit and its phone floating menu, quick-bills list search field and Print, Categories rename/delete, Customers list Edit, Masters Customers card). Nothing else was changed: no CSS, JavaScript, controllers, routes, permissions or labels.

## Files to read first
| File | What it is |
|---|---|
| `app/View/Icons.php` | Glyph map, the one renderer `svg()`, and the compiler `compileTags()` |
| `app/Providers/IconServiceProvider.php` | Registers the compile hook (`Blade::prepareStringsForCompilationUsing`) |
| `bootstrap/providers.php` | One added line registering that provider |
| `resources/views/components/icon.blade.php` | `<x-icon>` fallback component; calls the same `svg()` |
| `docs/third-party/lucide-LICENSE.txt` | Lucide licence (ISC + Feather MIT notice) |

## The compile hook
A plain `<x-icon name="…" />` as a Blade component costs about 57 µs per tag (measured in isolation: roughly 32 µs of component machinery plus attribute merging), which adds up on pages with many icons. So `IconServiceProvider` registers `Blade::prepareStringsForCompilationUsing(...)`; for any template that contains the text `<x-icon` it calls `Icons::compileTags()`, which rewrites eligible tags into a direct call. Measured cost after: about 2.4 µs per icon.

**Tags it handles** (`Icons.php`, `compileTags`): a *self-closing* `<x-icon … />` whose attributes are all plain text, i.e. each attribute is `name` or `name="value"` where `name` starts with a letter and the value contains none of `"`, `{`, `}`, `$`, `<`, `>`, `@`. Multi-line tags are matched. `name` is required; `size` and `label` must be strings; everything else (`class`, `data-*`, …) is passed through as an attribute. The rewrite is `{!! \App\View\Icons::svg('pencil', ['class' => 'w-4 h-4'], '12', null) !!}`, which Blade then compiles like any echo, so whitespace around the tag is preserved exactly as inline SVG had it.

**Tags it leaves alone** (they stay components): bound or event attributes (`:name`, `@click`), any Alpine `x-*` attribute, values containing `{{ }}`, `$var` or `@`, single-quoted or unquoted values, non-self-closing `<x-icon></x-icon>`, and a non-string `name`/`size`/`label`.

**Escaping.** Generated PHP string literals come from `var_export`, so values cannot break out of the call. At render time every attribute *value* goes through `e()` (HTML-escaped, quotes included; double-encoding is on, so a literal `&amp;` in a template would print as `&amp;amp;`; none exist). Attribute *names* are written as-is: they come only from template text matched by `[a-z][\w.:-]*` (compile path) or from the component's attribute bag (fallback), never from user data. `svg()` is therefore not meant for untrusted attribute names. Glyph markup is a constant map; `name` is looked up, never interpolated.

**Fallback.** `components/icon.blade.php` is `@props(['name','size'=>null,'label'=>null])` plus `{!! Icons::svg($name, $attributes->getAttributes(), $size, $label) !!}`, so dynamic uses (`<x-icon :name="$icon" />`) draw the same svg. It costs about 38 µs per icon and Blade's component syntax drops the newline after the tag, so avoid it in loops and inline-whitespace contexts. An unknown icon name throws `InvalidArgumentException` in `local`/`testing` and renders nothing elsewhere.

**Output defaults.** `viewBox 0 0 24 24`, `fill none`, `stroke currentColor`, `stroke-width 2`, round caps/joins, `data-icon`. Without `label`: `aria-hidden="true" focusable="false"`. With `label`: `role="img" aria-label`. `size` sets width and height; leave it off where CSS already sizes the icon (as at five of the six locations).

## View-cache handling
- The hook runs only when a view is compiled (cache miss), and is skipped for templates without `<x-icon`. Compiled output is cached in `storage/framework/views` and invalidated by Blade's normal rule (template modified after compiled file), so request-time cost is the function call only.
- Adding or changing a glyph does **not** need a recompile: compiled views call `Icons::svg()` at runtime.
- Changing `compileTags()` itself does **not** invalidate already compiled views (the cache is keyed on the template, not the compiler). After deploying a compiler change, run `php artisan view:clear` (or `view:cache`). Not run in this change: `view:cache` against the full view set.

## Known limits (not fixed here)
- The precompiler runs before Blade extracts `@verbatim` blocks and before comments are removed, so an `<x-icon … />` written inside `@verbatim`, a Blade/HTML comment, or a string in `@php` would also be rewritten. None exist today.
- The tag match is case-insensitive (`/i`); Blade itself treats component tags as lowercase.
- The `data-icon` attribute adds about 20 bytes per icon (used by the tests and for auditing).

## Tests
`tests/Feature/Icons/LucideIconComponentTest.php` (10 tests, no database): decorative icons are hidden from screen readers; `label` gives role and name; `size` only when asked; class/attribute pass-through; no surrounding whitespace; **every glyph equals the official Lucide SVG** (fixtures in `tests/Fixtures/lucide-1.66.0`, attributes and shapes); plain tags compile to `Icons::svg(` while dynamic/Alpine/`{{ }}`/closing-tag forms stay components; compiled output equals the fallback component's svg; a compiled tag keeps the whitespace around it exactly as inline SVG did; unknown name throws outside production and renders nothing in production.

`tests/Feature/Icons/LucideFirstLocationsTest.php` (5 tests, test database): the six locations draw the expected icon, keep their link text/`aria-label`, sizes (`w-4`, `w-3.5`, 12px, 20px, 14px) and permissions, the old drawings are gone, and every icon on those pages is decorative inside a control that still has an accessible name.

```bash
php artisan test tests/Feature/Icons
```
Result at 7fa675b7: 15 passed (181 assertions); full suite 3705 passed, 1 skipped, 0 failed.

## One behaviour change to confirm
The sub-category Delete button used an X; it now uses the same trash icon as the category Delete (action meaning unchanged: it submits the same confirmed delete form). Confirmed by the owner.
