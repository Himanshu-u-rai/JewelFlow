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
 * It was first moved under the page header, which only moved the problem: it
 * then lay over whatever the page starts with. It now takes room of its own.
 *
 * A browser showed the failures and the fix (at 320 to 430px wide the toast
 * overlaps no control and no message, on a page and over a modal). PHP cannot
 * lay a page out, so these pin the causes.
 */
class GlobalToastTest extends TestCase
{
    /**
     * On phones the toast floats at the top of the screen and the page never moves (owner 2026-11-13, keeping the
     * UI audit's F05 fix: the page used to move down by the toast's height, which the audit found jarring). It sits
     * clear of the bottom edge, where bottom sheets keep their Cancel/Save row; for its few seconds it may lie over
     * the top of the page.
     *
     * With a modal, a sheet, a drawer or a dialog over the page, the toast goes to the highest place where it covers
     * no control and no validation message of whatever is on top.
     */
    public function test_on_phones_the_toast_floats_at_the_top_and_the_page_never_moves(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));
        $this->assertSame(1, preg_match('~@media \(max-width: 768px\) \{\s*\.global-toast \{([^}]*)\}~s', $css, $rule),
            'app.css has no rule for the toast at phone widths.');
        $this->assertStringContainsString('bottom: auto;', $rule[1]);
        $this->assertStringContainsString('var(--toast-top', $rule[1]);
        $this->assertDoesNotMatchRegularExpression('~\.content-area \{[^}]*--toast-space~', $css, 'The page must not move for a toast.');

        $js = file_get_contents(resource_path('js/app.js'));
        $this->assertStringNotContainsString("root.style.setProperty('--toast-space'", $js, 'No room is made: the toast floats.');

        // Over a modal the place is looked for, not assumed; with none clear the toast waits.
        $this->assertSame(1, preg_match('~const clear = clearToastTop\(toast, top\);\s*if \(clear === null\) return false;~', $js),
            'A toast with no clear place over a modal is shown anyway.');
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
