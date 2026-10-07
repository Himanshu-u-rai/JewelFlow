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
     * On phones the toast lies over nothing. With the page in view it gets a
     * strip of its own at the top: the page, its header included, moves down
     * by the toast's height for as long as the toast shows (showToast() says
     * how tall) and gets the strip back when it hides. Under the page header,
     * where the toast sat before, it covered whatever a page starts with: the
     * Stock page's tabs, the Invoices row, the POS filters.
     *
     * With a modal, a sheet, a drawer or a dialog over the page there is
     * nothing to move, so the toast goes to the highest place where it covers
     * no control and no validation message of whatever is on top.
     */
    public function test_on_phones_the_page_makes_room_for_the_toast_instead_of_lying_under_it(): void
    {
        $this->assertSame(1, preg_match(
            '~@media \(max-width: 768px\) \{\s*\.global-toast \{([^}]*)\}\s*(?:/\*.*?\*/\s*)?\.content-area \{([^}]*)\}~s',
            file_get_contents(resource_path('css/app.css')),
            $rule
        ), 'app.css has no rule for the toast and the page under it at phone widths.');

        $this->assertStringContainsString('bottom: auto;', $rule[1]);
        $this->assertStringContainsString('var(--toast-top', $rule[1]);
        $this->assertStringContainsString('padding-top: var(--toast-space, 0px);', $rule[2]);

        $js = file_get_contents(resource_path('js/app.js'));

        // Room is made for exactly the toast, and given back with it.
        $this->assertStringContainsString("root.style.setProperty('--toast-space', `\${toast.offsetHeight + 16}px`);", $js);
        $this->assertSame(1, preg_match("~function hideToast\(toast\) \{[^}]*removeProperty\('--toast-space'\);~", $js),
            'The strip is not given back when the toast hides.');

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
