<?php

namespace Tests\Feature\Feedback;

use Illuminate\Support\Facades\View;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Tests\TestCase;

/**
 * F06: a session success/error flash has ONE owner, the global toast, which the
 * layout feeds through <meta name="flash-success|flash-error|flash-warning">
 * (resources/js/toast.js). <x-app-alerts /> and customers/show used to render the
 * same message in the page body as well, so one save produced two messages.
 * No database is needed: the component is rendered directly, the layout and the
 * customer page are checked from their source.
 */
class SingleFlashTest extends TestCase
{
    private function render(string $template = '<x-app-alerts />'): string
    {
        View::share('errors', new ViewErrorBag());

        return (string) $this->blade($template);
    }

    public function test_app_alerts_does_not_repeat_the_success_flash_the_toast_delivers(): void
    {
        session()->flash('success', 'Cash entry saved.');

        $this->assertStringNotContainsString('Cash entry saved.', $this->render());
        $this->assertStringNotContainsString('Cash entry saved.', $this->render('<x-app-alerts :floating="true" />'));
    }

    public function test_app_alerts_does_not_repeat_the_session_error_flash(): void
    {
        session()->flash('error', 'Could not post the entry.');

        $this->assertStringNotContainsString('Could not post the entry.', $this->render());
        $this->assertStringNotContainsString('Could not post the entry.', $this->render('<x-app-alerts :floating="true" />'));
    }

    public function test_app_alerts_still_renders_what_the_toast_does_not_carry(): void
    {
        session()->flash('success', 'Saved.');
        View::share('errors', (new ViewErrorBag())->put('default', new MessageBag([
            'message' => ['Top level problem.'],
            'amount' => ['The amount field is required.'],
        ])));

        $html = (string) $this->blade('<x-app-alerts />');

        $this->assertSame(1, substr_count($html, 'Top level problem.'));
        $this->assertStringContainsString('The amount field is required.', $html);
        $this->assertStringNotContainsString('Saved.', $html);
    }

    public function test_the_layout_still_delivers_success_and_error_as_flash_meta_once(): void
    {
        $layout = file_get_contents(resource_path('views/layouts/app.blade.php'));

        foreach (['success', 'error', 'warning'] as $key) {
            $this->assertSame(1, substr_count($layout, "name=\"flash-{$key}\""), $key);
        }
    }

    public function test_customer_profile_no_longer_renders_the_flash_in_its_body(): void
    {
        $page = file_get_contents(resource_path('views/customers/show.blade.php'));

        $this->assertStringNotContainsString("session('success')", $page);
        $this->assertStringNotContainsString("session('error')", $page);
        $this->assertStringNotContainsString('customers-show-retailer-alert--success', $page);
    }
}
