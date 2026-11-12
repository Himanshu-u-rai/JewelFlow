<?php

namespace Tests\Feature\Errors;

use Illuminate\Http\Request;
use Tests\TestCase;

/** UI audit F16: every error / recovery page uses one shared surface, the app's primary button, and the host's brand. */
class ErrorPagesTest extends TestCase
{
    private const RETAIL = 'https://jewelflows.com/missing';
    private const DHIRAN = 'https://dhiran.jewelflows.com/dhiran/missing';

    /** @return array<string, array{0: string, 1: list<string>}> view => expected action keys */
    public static function pages(): array
    {
        return [
            '401' => ['errors.401', ['signin']],
            '403' => ['errors.403', ['back', 'dashboard']],
            '404' => ['errors.404', ['back', 'dashboard']],
            '419' => ['errors.419', ['reload', 'dashboard']],
            '429' => ['errors.429', ['reload', 'dashboard']],
            '500' => ['errors.500', ['back', 'dashboard']],
            '503' => ['errors.503', ['reload']],
        ];
    }

    private function on(string $url): void
    {
        $this->app->instance('request', Request::create($url));
    }

    /** @dataProvider pages */
    public function test_each_page_uses_the_shared_surface_and_actions(string $view, array $actions): void
    {
        $this->on(self::RETAIL);
        $html = view($view)->render();

        $this->assertStringContainsString('data-error-surface', $html);
        $this->assertStringContainsString('background:#b45309', $html, 'app primary colour');
        $this->assertStringNotContainsString('#14213d', $html, 'old hard-coded navy button');
        $this->assertStringNotContainsString('font-size:72px', $html, 'old large isolated icon');
        $this->assertStringContainsString('JewelFlows', $html);

        preg_match_all('/data-error-action="([^"]+)"/', $html, $m);
        $this->assertSame($actions, $m[1], $view);
        $this->assertStringContainsString('width=device-width', $html);
    }

    /** @dataProvider pages */
    public function test_dhiran_host_says_dhiran_and_links_its_dashboard(string $view, array $actions): void
    {
        $this->on(self::DHIRAN);
        $html = view($view)->render();

        $this->assertStringContainsString('Dhiran', $html);
        $this->assertDoesNotMatchRegularExpression('/jewel\s?flows/i', strip_tags($html), $view);
        if (in_array('dashboard', $actions, true)) {
            $this->assertMatchesRegularExpression('#data-error-action="dashboard"#', $html);
            $this->assertMatchesRegularExpression('#href="[^"]*/dhiran"[^>]*data-error-action="dashboard"#', $html);
        }
    }

    public function test_retail_dashboard_action_goes_to_the_retail_dashboard(): void
    {
        $this->on(self::RETAIL);
        $html = view('errors.404')->render();
        $this->assertStringContainsString('href="' . route('dashboard') . '" class="ep-btn ep-btn-primary" data-error-action="dashboard"', $html);
        $this->assertStringNotContainsString('/dhiran"', $html);
    }

    public function test_opening_setup_pages_use_the_shared_surface(): void
    {
        foreach (['opening-setup-pending', 'opening-setup-in-progress'] as $name) {
            $src = file_get_contents(resource_path("views/errors/{$name}.blade.php"));
            $this->assertStringContainsString('<x-error-page', $src, $name);
            $this->assertStringNotContainsString('font-size:72px', $src, $name);
        }
        $this->assertStringContainsString('Realm::DHIRAN', file_get_contents(resource_path('views/errors/opening-setup-pending.blade.php')));
    }

    public function test_no_error_view_keeps_the_old_navy_or_inline_styling(): void
    {
        foreach (glob(resource_path('views/errors/*.blade.php')) as $file) {
            $src = file_get_contents($file);
            $this->assertStringNotContainsString('#14213d', $src, basename($file));
            $this->assertStringNotContainsString('font-size:72px', $src, basename($file));
        }
    }
}
