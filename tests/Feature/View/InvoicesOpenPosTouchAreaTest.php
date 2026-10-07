<?php

namespace Tests\Feature\View;

use Tests\TestCase;

/**
 * On a phone the Invoices header holds its title and Open POS on one row, so
 * the button is 34px tall there: a small target for the page's main action. A
 * taller button would grow the header over the navigation row beneath it.
 *
 * The button keeps its size and its touch area reaches 5px past it above and
 * below, inside the header's own padding: 44px. (The rule says -6px: the area
 * is measured from inside the button's 1px border.) A browser measured it (a tap
 * 4px above or below the button's edge is the button; nothing else is there).
 * PHP cannot lay a page out, so this pins the rule.
 */
class InvoicesOpenPosTouchAreaTest extends TestCase
{
    public function test_on_phones_open_pos_is_touched_over_44px_though_it_is_drawn_34px_tall(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));

        $this->assertSame(1, preg_match(
            '~\.invoices-page-header \.invoices-open-pos-btn \{\s*position: relative;\s*min-height: 34px;~',
            $css
        ), 'The phone rule for Open POS no longer draws it 34px tall, or cannot hold a larger touch area.');

        $this->assertSame(1, preg_match(
            '~\.invoices-page-header \.invoices-open-pos-btn::after \{\s*content: \'\';\s*position: absolute;\s*inset: -6px 0;\s*\}~',
            $css
        ), 'Open POS has no touch area beyond its 34px.');
    }
}
