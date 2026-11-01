<?php

namespace Tests\Feature\View;

use Tests\TestCase;

/**
 * Historical Sales reuses the compact header action's extended phone hit area.
 * Pins the existing CSS declarations only, not rendered geometry or tapping.
 * The relocated action requires fresh browser acceptance at phone widths.
 */
class InvoicesHistoricalTouchAreaTest extends TestCase
{
    public function test_historical_sales_retains_the_phone_header_action_touch_area_rule(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));

        $this->assertSame(1, preg_match(
            '~\.invoices-page-header \.invoices-historical-btn \{\s*position: relative;\s*min-height: 34px;~',
            $css
        ), 'The Historical Sales header action lost its compact phone rule.');

        $this->assertSame(1, preg_match(
            '~\.invoices-page-header \.invoices-historical-btn::after \{\s*content: \'\';\s*position: absolute;\s*inset: -6px 0;\s*\}~',
            $css
        ), 'Historical Sales has no touch area beyond its 34px.');
    }
}
