<?php

namespace Tests\Feature\View;

use Tests\TestCase;

/**
 * The app has one toast: #global-toast, shown for four seconds by
 * window.showToast() in app.js. It sat in the bottom-right corner at every
 * width. On phones (768px and under) that corner is taken: modals there are
 * bottom sheets with their Cancel/Save row pinned to the bottom edge, and a
 * page's extra header actions collect in a round button bottom right
 * (initMobileHeaderActionFabs). A message shown after a save lay over the
 * Save button of the modal the user had just reopened, and took its taps.
 *
 * A browser showed the failure and the fix (at 320 to 430px wide, the point
 * at the centre of each modal button is that button while a toast shows).
 * PHP cannot lay a page out, so these pin the causes.
 */
class GlobalToastTest extends TestCase
{
    /**
     * On phones the toast is anchored under the bar across the top of the page
     * and no longer at the bottom. That bar's height differs from page to page
     * (63 to 133px measured), so showToast() measures it for the stylesheet.
     */
    public function test_on_phones_the_toast_sits_under_the_page_header_not_at_the_bottom(): void
    {
        $this->assertSame(1, preg_match(
            '~@media \(max-width: 768px\) \{\s*\.global-toast \{([^}]*)\}~',
            file_get_contents(resource_path('css/app.css')),
            $rule
        ), 'app.css has no rule for the toast at phone widths.');

        $this->assertStringContainsString('bottom: auto;', $rule[1]);
        $this->assertStringContainsString(
            'top: calc(max(var(--toast-header-bottom, 0px), env(safe-area-inset-top, 0px)) + 8px);',
            $rule[1]
        );

        $this->assertSame(1, preg_match(
            "~const header = document\.querySelector\('\.content-header, \.admin-topbar, \.pos-topbar, \.pos-header'\);\s*"
            ."toast\.style\.setProperty\('--toast-header-bottom', `\\$\{header \? header\.getBoundingClientRect\(\)\.bottom : 0\}px`\);~",
            file_get_contents(resource_path('js/app.js'))
        ), 'showToast() does not tell the stylesheet where the page header ends.');
    }

    /**
     * Turbo keeps a copy of each page for Back/Forward. A toast still showing
     * when the page was left was kept in that copy and came back with it, for
     * good: the timer that hides it belongs to the page that was left. Found
     * in a browser (leave within four seconds, come Back: the message is
     * there and stays). Wherever it sits, it would cover that spot until the
     * next message.
     */
    public function test_a_toast_still_showing_is_not_kept_in_turbos_copy_of_the_page(): void
    {
        $this->assertSame(1, preg_match(
            "~document\.addEventListener\('turbo:before-cache', \(\) => \{\s*"
            ."const toast = document\.getElementById\('global-toast'\);\s*"
            ."if \(toast\) hideToast\(toast\);\s*\}\);~",
            file_get_contents(resource_path('js/app.js'))
        ), 'A toast is not hidden before Turbo caches the page.');
    }
}
