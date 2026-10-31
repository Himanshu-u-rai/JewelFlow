<?php

namespace Tests\Feature\Icons;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/**
 * <x-icon>: the one place interface icons are drawn. Inline SVG, Lucide glyphs copied verbatim from the
 * official package (fixtures in tests/Fixtures/lucide-1.66.0), decorative unless given a label, no layout
 * side effects (no stray whitespace, no forced size).
 */
class LucideIconComponentTest extends TestCase
{
    private const GLYPHS = ['search', 'pencil', 'trash-2', 'printer', 'users'];

    private function icon(string $markup): string
    {
        return Blade::render($markup);
    }

    private function attr(string $svg, string $name): ?string
    {
        preg_match('/<svg\b[^>]*>/', $svg, $tag);

        return preg_match('/\s' . preg_quote($name, '/') . '="([^"]*)"/', $tag[0] ?? '', $m) ? $m[1] : null;
    }

    public function test_a_decorative_icon_adds_nothing_for_a_screen_reader_to_announce(): void
    {
        $svg = $this->icon('<x-icon name="pencil" />');

        $this->assertSame('true', $this->attr($svg, 'aria-hidden'));
        $this->assertSame('false', $this->attr($svg, 'focusable'));
        $this->assertNull($this->attr($svg, 'role'));
        $this->assertNull($this->attr($svg, 'aria-label'));
        $this->assertStringNotContainsString('<title', $svg);
        $this->assertSame('pencil', $this->attr($svg, 'data-icon'));
    }

    public function test_a_standalone_icon_can_carry_an_accessible_name(): void
    {
        $svg = $this->icon('<x-icon name="search" label="Search records" />');

        $this->assertSame('img', $this->attr($svg, 'role'));
        $this->assertSame('Search records', $this->attr($svg, 'aria-label'));
        $this->assertNull($this->attr($svg, 'aria-hidden'));
    }

    public function test_size_is_only_set_when_asked_so_existing_css_keeps_sizing_the_icon(): void
    {
        $this->assertNull($this->attr($this->icon('<x-icon name="printer" />'), 'width'));
        $this->assertNull($this->attr($this->icon('<x-icon name="printer" />'), 'height'));

        $sized = $this->icon('<x-icon name="printer" size="12" />');
        $this->assertSame('12', $this->attr($sized, 'width'));
        $this->assertSame('12', $this->attr($sized, 'height'));
    }

    public function test_classes_and_other_attributes_reach_the_svg(): void
    {
        $svg = $this->icon('<x-icon name="trash-2" class="w-4 h-4 qb-input-icon" data-probe="x" />');

        $this->assertSame('w-4 h-4 qb-input-icon', $this->attr($svg, 'class'));
        $this->assertSame('x', $this->attr($svg, 'data-probe'));
    }

    public function test_the_markup_has_no_surrounding_whitespace_so_inline_layouts_do_not_shift(): void
    {
        foreach (self::GLYPHS as $name) {
            $svg = $this->icon("<x-icon name=\"{$name}\" />");
            $this->assertSame(trim($svg), $svg, "{$name} renders stray whitespace");
            $this->assertStringStartsWith('<svg ', $svg);
            $this->assertStringEndsWith('</svg>', $svg);
        }
    }

    public function test_every_glyph_is_the_official_lucide_svg(): void
    {
        $norm = fn (string $s) => preg_replace(['/\s+/', '/\s\/>/', '/>\s+</'], [' ', '/>', '><'], trim($s));

        foreach (self::GLYPHS as $name) {
            $official = file_get_contents(__DIR__ . "/../../Fixtures/lucide-1.66.0/{$name}.svg");
            preg_match('/<svg\b([^>]*)>(.*)<\/svg>/s', $official, $o);
            $ours = $this->icon("<x-icon name=\"{$name}\" />");
            preg_match('/<svg\b([^>]*)>(.*)<\/svg>/s', $ours, $m);

            $this->assertSame($norm($o[2]), $norm($m[2]), "{$name}: shapes differ from lucide-static 1.66.0");
            foreach (['viewBox', 'fill', 'stroke', 'stroke-width', 'stroke-linecap', 'stroke-linejoin'] as $a) {
                $this->assertSame($this->attr($official, $a), $this->attr($ours, $a), "{$name}: {$a} differs from the official SVG");
            }
        }
    }

    public function test_plain_tags_compile_to_a_direct_call_and_anything_dynamic_stays_a_component(): void
    {
        $static = Blade::compileString('<x-icon name="pencil" size="12" class="w-4 h-4" />');
        $this->assertStringContainsString('Icons::svg(', $static);
        $this->assertStringNotContainsString('AnonymousComponent', $static);

        foreach (['<x-icon :name="$n" />', '<x-icon name="{{ $n }}" />', '<x-icon name="pencil" x-show="open" />', '<x-icon name="pencil" @click="go" />', '<x-icon name="pencil"></x-icon>'] as $dynamic) {
            $this->assertStringNotContainsString('Icons::svg(', Blade::compileString($dynamic), "{$dynamic} must stay a component");
        }
    }

    public function test_the_compiled_call_and_the_component_fallback_render_the_same_svg(): void
    {
        $cases = [
            '<x-icon name="%s" />', '<x-icon name="%s" size="12" />', '<x-icon name="%s" class="w-4 h-4 qb-input-icon" />',
            '<x-icon name="%s" size="14" class="a" data-probe="x" />', '<x-icon name="%s" label="Search records" />',
        ];
        foreach (self::GLYPHS as $glyph) {
            foreach ($cases as $tag) {
                $static = sprintf($tag, $glyph);
                $viaComponent = str_replace('name="' . $glyph . '"', ':name="$n"', $static);

                $this->assertSame(
                    trim(Blade::render($viaComponent, ['n' => $glyph])),
                    trim(Blade::render($static)),
                    "{$static}: the compiled call and the component draw different markup"
                );
            }
        }
    }

    public function test_a_compiled_tag_leaves_the_whitespace_around_it_exactly_as_inline_svg_did(): void
    {
        // Blade's own component syntax swallows the newline after a tag; inline SVG (and this compiled call) keep it.
        $svg = \App\View\Icons::svg('pencil', ['class' => 'w-4 h-4'], 12);

        $this->assertSame(
            "<a>\n    {$svg}\n    <span>Edit</span>\n</a>",
            Blade::render("<a>\n    <x-icon name=\"pencil\" size=\"12\" class=\"w-4 h-4\" />\n    <span>Edit</span>\n</a>")
        );
    }

    public function test_an_unknown_icon_fails_loudly_while_developing_and_renders_nothing_in_production(): void
    {
        try {
            $this->icon('<x-icon name="no-such-icon" />');
            $this->fail('an unknown icon name must throw outside production');
        } catch (\Throwable $e) {
            $this->assertStringContainsString('Unknown icon [no-such-icon]', $e->getMessage());
        }

        $this->app['env'] = 'production';
        $this->assertSame('', trim($this->icon('<x-icon name="no-such-icon" />')));
    }
}
