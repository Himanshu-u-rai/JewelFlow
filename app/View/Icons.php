<?php

namespace App\View;

use InvalidArgumentException;

/**
 * Interface icons: the single renderer behind <x-icon> (resources/views/components/icon.blade.php).
 *
 * Inline SVG only: no JavaScript, no icon font, no extra request. Glyphs are copied verbatim from Lucide
 * (lucide-static 1.66.0, https://lucide.dev, ISC licence). `search` and `trash-2` derive from Feather, so the
 * Feather MIT notice applies too: docs/third-party/lucide-LICENSE.txt. Only the icons the app uses are listed.
 * To add one, copy it from the same package and add its fixture to tests/Fixtures/lucide-1.66.0 and
 * tests/Feature/Icons/LucideIconComponentTest.php. Business logos and brand marks are not interface icons.
 *
 * Why a static renderer and not just a Blade component: a Blade component costs about 30 µs of framework work
 * per tag (measured), which adds up on pages with a hundred icons. <x-icon name="..." /> tags whose attributes are
 * all plain text are therefore compiled once into a direct call to svg() (see IconServiceProvider); any other use
 * (dynamic or Alpine attributes) falls back to the component, which calls the same svg() so the markup is identical.
 */
final class Icons
{
    private const GLYPHS = [
        'search' => '<path d="m21 21-4.34-4.34"/><circle cx="11" cy="11" r="8"/>',
        'pencil' => '<path d="M21.174 6.812a1 1 0 0 0-3.986-3.987L3.842 16.174a2 2 0 0 0-.5.83l-1.321 4.352a.5.5 0 0 0 .623.622l4.353-1.32a2 2 0 0 0 .83-.497z"/><path d="m15 5 4 4"/>',
        'trash-2' => '<path d="M10 11v6"/><path d="M14 11v6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/><path d="M3 6h18"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>',
        'printer' => '<path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><path d="M6 9V3a1 1 0 0 1 1-1h10a1 1 0 0 1 1 1v6"/><rect x="6" y="14" width="12" height="8" rx="1"/>',
        'users' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><path d="M16 3.128a4 4 0 0 1 0 7.744"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><circle cx="9" cy="7" r="4"/>',
    ];

    /**
     * @param  array<string, string|bool|null>  $attributes  extra attributes (class, data-*, ...); they win over the defaults
     * @param  string|int|null  $size  px; leave null where CSS already sizes the icon
     * @param  string|null  $label  accessible name for a standalone meaningful icon; null keeps the icon decorative
     */
    public static function svg(string $name, array $attributes = [], string|int|null $size = null, ?string $label = null): string
    {
        $glyph = self::GLYPHS[$name] ?? null;
        if ($glyph === null) {
            if (app()->environment('local', 'testing')) {
                throw new InvalidArgumentException("Unknown icon [{$name}]. Add it to App\\View\\Icons.");
            }

            return '';
        }

        $attrs = ['viewBox' => '0 0 24 24', 'fill' => 'none', 'stroke' => 'currentColor', 'stroke-width' => '2', 'stroke-linecap' => 'round', 'stroke-linejoin' => 'round', 'data-icon' => $name];
        if ($size) {
            $attrs['width'] = $size;
            $attrs['height'] = $size;
        }
        $attrs += $label ? ['role' => 'img', 'aria-label' => $label] : ['aria-hidden' => 'true', 'focusable' => 'false'];

        $html = '';
        foreach (array_merge($attrs, $attributes) as $key => $value) {
            if ($value === false || $value === null) {
                continue;
            }
            // Values arrive the way Blade's attribute bag carries them: a bound value (:title="$x") was already escaped
            // once by Blade, a static value is HTML the author wrote. Escaping without double-encoding keeps both exactly
            // once (an entity is left alone, a bare & < > or quote is escaped), so nothing is escaped twice or left raw.
            $html .= $value === true ? " {$key}" : " {$key}=\"" . e((string) $value, false) . '"';
        }

        return "<svg{$html}>{$glyph}</svg>";
    }

    /** Raw content Blade protects before it compiles component tags: verbatim blocks, @php blocks, {{-- comments --}}. */
    private const PROTECTED_BLOCKS = '/((?<!@)@verbatim.*?@endverbatim|(?<!@)@php.*?@endphp|\{\{--.*?--\}\})/s';

    /**
     * Blade precompiler: rewrite self-closing <x-icon .../> tags whose attributes are all plain text into a direct
     * svg() call. Anything else (a :bound, @event or x-alpine attribute, a {{ }} or $variable value, a closing tag)
     * is left alone and handled by the component.
     *
     * This runs before Blade stores its raw blocks (BladeCompiler::compileString), so it skips the same three kinds of
     * block itself (patterns copied from storeVerbatimBlocks, storePhpBlocks and compileComments): a tag shown inside
     * @verbatim, kept as a string inside @php ... @endphp, or written in a Blade comment is left exactly as written.
     */
    public static function compileTags(string $template): string
    {
        $parts = preg_split(self::PROTECTED_BLOCKS, $template, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($parts === false) {
            return $template; // pattern backtracking limit: leave the template to Blade's own component compiler
        }
        foreach ($parts as $i => $part) {
            if ($i % 2 === 0) { // even entries are the text between protected blocks
                $parts[$i] = self::rewrite($part);
            }
        }

        return implode('', $parts);
    }

    private static function rewrite(string $text): string
    {
        return preg_replace_callback('/<x-icon((?:\s+[a-zA-Z][\w.:-]*(?:="[^"{}$<>@]*")?)*)\s*\/>/', function (array $m): string {
            preg_match_all('/\s+([a-zA-Z][\w.:-]*)(?:="([^"]*)")?/', $m[1], $pairs, PREG_SET_ORDER);
            $attrs = [];
            foreach ($pairs as $p) {
                if (str_starts_with($p[1], 'x-')) {
                    return $m[0];
                }
                $attrs[$p[1]] = $p[2] ?? true;
            }

            $name = $attrs['name'] ?? null;
            $size = $attrs['size'] ?? null;
            $label = $attrs['label'] ?? null;
            if (! is_string($name) || ($size !== null && ! is_string($size)) || ($label !== null && ! is_string($label))) {
                return $m[0];
            }
            unset($attrs['name'], $attrs['size'], $attrs['label']);

            return '{!! \\App\\View\\Icons::svg(' . var_export($name, true) . ', ' . var_export($attrs, true) . ', ' . var_export($size, true) . ', ' . var_export($label, true) . ') !!}';
        }, $text) ?? $text;
    }
}
