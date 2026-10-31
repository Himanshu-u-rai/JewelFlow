<?php

namespace Tests\Feature\Icons;

use App\View\Icons;
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

    /** The attribute value as a browser sees it (entities decoded), read from the real DOM. */
    private function domAttr(string $html, string $name): ?string
    {
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?><body>' . $html . '</body>');
        libxml_clear_errors();
        $svg = $dom->getElementsByTagName('svg')->item(0);

        return $svg && $svg->hasAttribute($name) ? $svg->getAttribute($name) : null;
    }

    public function test_a_bound_attribute_is_escaped_exactly_once(): void
    {
        // Blade escapes bound attributes before they reach the attribute bag; svg() must not escape them again.
        $title = 'A & B "quoted" <b>bold</b> it\'s';
        $html = Blade::render('<x-icon name="search" :title="$title" />', ['title' => $title]);

        $this->assertSame($title, $this->domAttr($html, 'title'), 'the DOM value must equal the original text');
        $this->assertStringNotContainsString('&amp;amp;', $html);
        $this->assertStringNotContainsString('&amp;quot;', $html);
        $this->assertStringNotContainsString('<b>', $html, 'a raw angle bracket reached the markup');
        $this->assertStringContainsString('A &amp; B &quot;quoted&quot; &lt;b&gt;bold&lt;/b&gt;', $html);

        // Text that already looks like an entity is data when bound: it must print literally, escaped once.
        $literal = Blade::render('<x-icon name="search" :title="$t" />', ['t' => 'Fish &amp; chips']);
        $this->assertSame('Fish &amp; chips', $this->domAttr($literal, 'title'));
    }

    public function test_static_html_entities_are_kept_as_written_and_never_escaped_twice(): void
    {
        $static = '<x-icon name="search" title="A &amp; B &lt;tag&gt; &quot;q&quot;" />';
        $viaComponent = '<x-icon :name="$n" title="A &amp; B &lt;tag&gt; &quot;q&quot;" />';

        foreach (['compiled call' => Blade::render($static), 'component fallback' => Blade::render($viaComponent, ['n' => 'search'])] as $path => $html) {
            $this->assertSame('A & B <tag> "q"', $this->domAttr($html, 'title'), "{$path}: DOM value");
            $this->assertStringNotContainsString('&amp;amp;', $html, "{$path}: double-escaped");
            $this->assertStringNotContainsString('<tag>', $html, "{$path}: raw angle brackets in the tag");
        }

        // A bare ampersand written in a static attribute is escaped once.
        $bare = Blade::render('<x-icon name="search" title="Fish & chips" />');
        $this->assertSame('Fish & chips', $this->domAttr($bare, 'title'));
        $this->assertStringContainsString('Fish &amp; chips', $bare);
    }

    public function test_accessible_labels_are_escaped_once_on_both_rendering_paths(): void
    {
        $cases = [
            'compiled, static label' => [Blade::render('<x-icon name="search" label="Search &amp; replace" />'), 'Search & replace'],
            'fallback, static label' => [Blade::render('<x-icon :name="$n" label="Search &amp; replace" />', ['n' => 'search']), 'Search & replace'],
            'fallback, bound label' => [Blade::render('<x-icon :name="$n" :label="$l" />', ['n' => 'search', 'l' => 'Fish & "chips" <3']), 'Fish & "chips" <3'],
        ];
        foreach ($cases as $path => [$html, $expected]) {
            $this->assertSame($expected, $this->domAttr($html, 'aria-label'), "{$path}: aria-label");
            $this->assertSame('img', $this->domAttr($html, 'role'), "{$path}: role");
            $this->assertNull($this->domAttr($html, 'aria-hidden'), "{$path}: a named icon must not be hidden");
            $this->assertStringNotContainsString('&amp;amp;', $html, "{$path}: double-escaped");
            $this->assertStringNotContainsString('&amp;quot;', $html, "{$path}: double-escaped");
        }
    }

    public function test_content_blade_protects_is_left_alone_by_the_compile_hook(): void
    {
        $tag = '<x-icon name="search" />';
        $protected = [
            'verbatim block' => "@verbatim\n{$tag}\n@endverbatim",
            'tag as a string inside @php' => "@php \$example = '{$tag}'; @endphp",
            'Blade comment' => "{{-- {$tag} --}}",
            'html-escaped example' => '&lt;x-icon name="search" /&gt;',
        ];
        foreach ($protected as $label => $template) {
            $this->assertSame($template, Icons::compileTags($template), "{$label}: the hook rewrote protected content");
        }

        // (Real views start with markup; plain Blade leaves a raw-block placeholder uncompiled when a template starts with one.)
        $verbatim = Blade::render("<p>x</p>\n@verbatim\n{$tag}\n@endverbatim");
        $this->assertStringContainsString($tag, $verbatim, 'verbatim must print the tag as written');
        $this->assertStringNotContainsString('<svg', $verbatim);

        $php = Blade::render("<p>x</p>\n@php \$example = '{$tag}'; @endphp\n{{ \$example }}");
        $this->assertStringContainsString(e($tag), $php, 'the string kept in @php must print as text');
        $this->assertStringNotContainsString('<svg', $php);

        $this->assertStringNotContainsString('<svg', Blade::render("<p>x</p>{{-- {$tag} --}}"));
        $this->assertSame('&lt;x-icon name="search" /&gt;', Blade::render('&lt;x-icon name="search" /&gt;'));
    }

    public function test_live_tags_beside_protected_content_still_compile_and_an_escaped_verbatim_is_not_a_block(): void
    {
        $tag = '<x-icon name="search" />';
        $mixed = "<p>x</p>\n@verbatim {$tag} @endverbatim\n<x-icon name=\"pencil\" />\n@php \$s = '{$tag}'; @endphp\n<x-icon name=\"printer\" />";

        $this->assertSame(2, substr_count(Blade::compileString($mixed), 'Icons::svg('), 'only the two live tags compile');
        $out = Blade::render($mixed);
        $this->assertSame(2, substr_count($out, '<svg'));
        $this->assertStringContainsString($tag, $out, 'the verbatim copy is still printed as written');

        // `@@verbatim` is the escape for a literal "@verbatim", not a raw block: the tag after it is live.
        $this->assertStringContainsString('Icons::svg(', Icons::compileTags("@@verbatim {$tag} @endverbatim"));
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
