<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Web POSTs through the real stack need CSRF disabled (else 419) and the
        // register throttle disabled (array cache accumulates → 429). Per-class
        // pattern, like other web tests.
        $this->withoutMiddleware([
            \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
            \Illuminate\Routing\Middleware\ThrottleRequests::class,
        ]);
    }

    public function test_registration_screen_can_be_rendered(): void
    {
        $response = $this->get('/register');

        $response->assertStatus(200);
    }

    public function test_new_users_can_register(): void
    {
        $mobileNumber = '9999999999';

        $response = $this->withSession([
            'otp_verified_mobile' => $mobileNumber,
        ])->post('/register', [
            'mobile_number' => $mobileNumber,
            // Must satisfy the app's hardened policy (Password::min(10)->mixedCase
            // ->numbers->symbols, see AppServiceProvider). The stock-Breeze weak
            // password failed validation, so the user was never created/authenticated.
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'otp_verified' => true,
        ]);

        $this->assertAuthenticated();
        // Post-registration the user is sent to choose a shop type first
        // (RegisteredUserController redirects to shops.choose-type); the old
        // shops.create destination predates that two-step onboarding flow.
        $response->assertRedirect(route('shops.choose-type', absolute: false));
    }
}
