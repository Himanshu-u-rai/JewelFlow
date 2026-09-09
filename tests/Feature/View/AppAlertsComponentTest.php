<?php

namespace Tests\Feature\View;

use Illuminate\Support\Facades\View;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Tests\TestCase;

/**
 * `<x-app-alerts />` is the single validation-error surface for ~8 screens
 * (historical manual entry and preview, upload, map, batch, document, index,
 * inventory items). It guarded its error bag with
 * `method_exists($errors, 'all') && method_exists($errors, 'first')`.
 *
 * Laravel shares `$errors` as an `Illuminate\Support\ViewErrorBag`, which
 * declares NEITHER method — both are forwarded to the default `MessageBag`
 * through `__call()`. `method_exists()` does not see magic methods, so the
 * guard was false for every genuine validation failure and the component
 * substituted an empty `MessageBag`. Result: the operator was redirected back
 * with their input intact and no visible reason, on every one of those screens.
 *
 * Session-level assertions (`assertSessionHasErrors`) cannot catch this — they
 * read the session, not the rendered page. These tests render the component.
 */
class AppAlertsComponentTest extends TestCase
{
    /**
     * `$errors` must be SHARED on the view factory, not passed as blade() data.
     * A Blade component renders in its own scope and reads `$errors` from the
     * factory's shared data (that is what ShareErrorsFromSession populates) —
     * a local variable in the calling template never reaches it. Passing it as
     * data produces a component that always sees the empty shared bag, which
     * looks exactly like the bug under test and would make this suite lie.
     *
     * That same scoping is also why manual-preview.blade.php's local `$errors`
     * never actually shadowed the component; it is still renamed, because a
     * variable that collides with a framework-shared name by accident is a
     * trap for the next reader.
     */
    private function renderWith(ViewErrorBag $bag): string
    {
        View::share('errors', $bag);

        return (string) $this->blade('<x-app-alerts />');
    }

    private function bagOf(array $messages, string $key = 'default'): ViewErrorBag
    {
        return (new ViewErrorBag())->put($key, new MessageBag($messages));
    }

    /**
     * The regression itself. A stock `ViewErrorBag` — exactly what
     * `ShareErrorsFromSession` puts in every `web` response — must render.
     */
    public function test_a_view_error_bag_renders_its_messages(): void
    {
        $html = $this->renderWith($this->bagOf([
            'document_date' => ['The document date field is required.'],
        ]));

        $this->assertStringContainsString('Please fix the following:', $html);
        $this->assertStringContainsString('The document date field is required.', $html);
    }

    /**
     * Pins the mechanism, not just the symptom: asserting only "the message
     * appears" would also pass if someone reintroduced the `method_exists`
     * guard alongside a second, duplicate alert block. This documents WHY the
     * guard was wrong.
     */
    public function test_view_error_bag_hides_all_and_first_from_method_exists(): void
    {
        $bag = $this->bagOf(['document_date' => ['required']]);

        $this->assertFalse(method_exists($bag, 'all'), 'ViewErrorBag now declares all() — the normalization can be simplified.');
        $this->assertFalse(method_exists($bag, 'first'), 'ViewErrorBag now declares first() — the normalization can be simplified.');
        $this->assertTrue(is_callable([$bag, 'all']), 'all() must still be reachable through __call.');
    }

    /** A plain MessageBag (older shares put one directly) must keep working. */
    public function test_a_plain_message_bag_still_renders(): void
    {
        View::share('errors', new MessageBag(['grand_total' => ['The grand total must be a number.']]));

        $html = (string) $this->blade('<x-app-alerts />');

        $this->assertStringContainsString('The grand total must be a number.', $html);
    }

    /**
     * The `message` key is the app's convention for a single top-level error
     * and is rendered separately, so it must not also appear in the list.
     */
    public function test_the_message_key_is_not_duplicated_into_the_validation_list(): void
    {
        $html = $this->renderWith($this->bagOf([
            'message' => ['Something went wrong.'],
            'document_date' => ['The document date field is required.'],
        ]));

        $this->assertSame(1, substr_count($html, 'Something went wrong.'));
        $this->assertStringContainsString('The document date field is required.', $html);
    }

    /**
     * A non-default bag must not leak. Laravel's `$errors->first()` reads
     * 'default'; normalizing to the whole ViewErrorBag would have widened
     * that silently.
     */
    public function test_only_the_default_bag_is_rendered(): void
    {
        $bag = (new ViewErrorBag())
            ->put('default', new MessageBag(['a' => ['Default bag message.']]))
            ->put('other', new MessageBag(['b' => ['Other bag message.']]));

        $html = $this->renderWith($bag);

        $this->assertStringContainsString('Default bag message.', $html);
        $this->assertStringNotContainsString('Other bag message.', $html);
    }

    /** No errors, no session flashes: the component renders nothing at all. */
    public function test_an_empty_bag_renders_no_alert(): void
    {
        $this->assertStringNotContainsString(
            'Please fix the following:',
            $this->renderWith(new ViewErrorBag())
        );
    }

    /**
     * The SECOND silent-validation cause, found in the browser after the bag
     * normalization was already fixed: the markup was correct and the messages
     * were in the DOM, but every error branch carried
     * `x-init="setTimeout(() => show = false, …)"`. Alpine flipped `x-show` to
     * `display:none` six to eight seconds later, so an operator who was still
     * reading the list watched it delete itself. A DOM-presence assertion
     * passes throughout — the alert element exists, it is merely invisible.
     *
     * Errors are instructions and must persist until dismissed. Success is an
     * acknowledgement and may still expire, which is the asymmetry pinned here.
     */
    public function test_error_alerts_do_not_auto_dismiss_while_success_still_does(): void
    {
        $errorHtml = $this->renderWith($this->bagOf([
            'message' => ['Something went wrong.'],
            'document_date' => ['The document date field is required.'],
        ]));

        $this->assertStringNotContainsString('setTimeout', $errorHtml,
            'An error alert that removes itself on a timer is the silent-validation bug again.');

        View::share('errors', new ViewErrorBag());
        session()->flash('success', 'Historical bill saved.');

        $this->assertStringContainsString('setTimeout', (string) $this->blade('<x-app-alerts />'),
            'Success acknowledgements are transient by design; only errors must persist.');
    }

    /** Defensive: the component is also used where `$errors` was never shared. */
    public function test_a_non_bag_errors_value_does_not_explode(): void
    {
        View::share('errors', 'not a bag');

        $html = (string) $this->blade('<x-app-alerts />');

        $this->assertStringNotContainsString('Please fix the following:', $html);
    }
}
