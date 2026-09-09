<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestTenant;

    protected function setUp(): void
    {
        parent::setUp();

        // Web PATCH/DELETE through the real stack need CSRF disabled (else 419).
        // Per-class pattern, like other web tests.
        $this->withoutMiddleware([
            \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
            \Illuminate\Routing\Middleware\ThrottleRequests::class,
        ]);
    }

    /**
     * The /profile routes live behind the auth+tenant+subscription.active+
     * account.active+shop.exists group and require the settings.edit ability.
     * The old bespoke setup created a shop with no subscription and a user with
     * no role, so every request redirected (302) at the subscription gate. Use
     * the shared tenant factory, which provisions an active subscription and a
     * fully-permissioned owner — the real shape of a logged-in owner.
     */
    private function createUserWithShop(): User
    {
        [$user] = $this->createManufacturerTenant();

        return $user;
    }

    public function test_profile_page_is_displayed(): void
    {
        $user = $this->createUserWithShop();

        $response = $this
            ->actingAs($user)
            ->get('/profile');

        $response->assertOk();
    }

    public function test_profile_information_can_be_updated(): void
    {
        $user = $this->createUserWithShop();

        // ProfileController::update redirects back(), so set the referer so the
        // assertion resolves to /profile (in the browser the form posts from
        // /profile).
        $response = $this
            ->actingAs($user)
            ->from('/profile')
            ->patch('/profile', [
                'name' => 'Test User',
                'email' => 'test@example.com',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile');

        $user->refresh();

        $this->assertSame('Test User', $user->name);
        $this->assertSame('test@example.com', $user->email);
        $this->assertNull($user->email_verified_at);
    }

    public function test_email_verification_status_is_unchanged_when_the_email_address_is_unchanged(): void
    {
        $user = $this->createUserWithShop();
        // The factory leaves email/email_verified_at null. This test asserts the
        // verification timestamp survives a profile update that keeps the same
        // email, so the user must start with a verified email address.
        $user->forceFill([
            'email' => 'verified@example.com',
            'email_verified_at' => now(),
        ])->save();

        $response = $this
            ->actingAs($user)
            ->from('/profile')
            ->patch('/profile', [
                'name' => 'Test User',
                'email' => $user->email,
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile');

        $this->assertNotNull($user->refresh()->email_verified_at);
    }

    /**
     * Closing an account DEACTIVATES it — the row survives with is_active=false.
     * A tenant user owns invoices, sales and audit trail, so hard-deleting the
     * row would orphan financial history; only the shop owner can reactivate.
     */
    public function test_user_can_deactivate_their_account(): void
    {
        $user = $this->createUserWithShop();

        $response = $this
            ->actingAs($user)
            ->post('/profile/deactivate', [
                'password' => 'password',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/login');

        $this->assertGuest();
        $this->assertNotNull($user->fresh(), 'the user row must survive deactivation');
        $this->assertFalse((bool) $user->fresh()->is_active);
    }

    public function test_correct_password_must_be_provided_to_deactivate_account(): void
    {
        $user = $this->createUserWithShop();

        $response = $this
            ->actingAs($user)
            ->from('/profile')
            ->post('/profile/deactivate', [
                'password' => 'wrong-password',
            ]);

        $response
            ->assertSessionHasErrorsIn('userDeactivation', 'password')
            ->assertRedirect('/profile');

        $this->assertTrue((bool) $user->fresh()->is_active, 'a wrong password must not deactivate');
    }
}
