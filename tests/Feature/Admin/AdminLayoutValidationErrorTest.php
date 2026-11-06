<?php

namespace Tests\Feature\Admin;

use App\Http\Middleware\EnsurePlatformAdminMfa;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * The admin layout toasted only session('error'). A form refused by validation (Apply Subscription Change,
 * plan edit, ...) came back with the refusal in the error bag, which no admin screen rendered: nothing happened
 * on screen. The first validation error must reach the toast.
 */
class AdminLayoutValidationErrorTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestTenant;

    public function test_a_refused_admin_form_shows_its_first_validation_error(): void
    {
        $this->withoutVite();
        $shop = $this->createShop('retailer');
        $errors = (new ViewErrorBag())->put('default', new MessageBag(['starts_at' => 'A future-dated active term needs a scheduled state.']));

        $this->actingAs($this->createPlatformAdmin(), 'platform_admin')
            ->withSession([EnsurePlatformAdminMfa::SESSION_PASSED => true, 'errors' => $errors])
            ->get('/admin/shops/' . $shop->id)
            ->assertOk()
            ->assertSee('<meta name="flash-error" content="A future-dated active term needs a scheduled state.">', false);
    }
}
