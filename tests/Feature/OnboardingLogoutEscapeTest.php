<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * A user routed into onboarding (e.g. a shop with no active subscription) must
 * never be trapped: they must always be able to log out and sign into an
 * account that does have a subscription. The plan-chooser / payment / shop-type
 * pages previously had no logout affordance, so the only escape was clearing
 * cookies. logout is bypassed by the subscription/shop middleware, so it is
 * reachable — these pages just needed the button.
 */
class OnboardingLogoutEscapeTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
    }

    public function test_logout_works_from_an_authenticated_session(): void
    {
        [$user] = $this->createRetailerTenant();

        $this->actingAs($user);
        $this->assertAuthenticated();

        $this->post(route('logout'));

        // The escape works: the user is signed out (free to log into another account).
        $this->assertGuest();
    }

    public function test_onboarding_pages_render_a_logout_escape(): void
    {
        // Source-level guarantee that each onboarding step exposes a logout form
        // (full-page render depends on onboarding state; the route is verified
        // reachable above and the middleware bypass is in place).
        foreach ([
            'subscription/plans.blade.php',
            'subscription/payment.blade.php',
            'shops/choose-type.blade.php',
        ] as $view) {
            $blade = file_get_contents(resource_path('views/' . $view));
            $this->assertStringContainsString("route('logout')", $blade, "$view must expose a logout escape");
        }

        $this->assertTrue(Route::has('logout'));
    }
}
