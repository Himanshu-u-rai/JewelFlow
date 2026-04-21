<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

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
            'password' => 'password',
            'password_confirmation' => 'password',
            'otp_verified' => true,
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('shops.create', absolute: false));
    }
}
