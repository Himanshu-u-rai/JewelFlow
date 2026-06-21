<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class EmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_email_verification_screen_can_be_rendered(): void
    {
        $user = User::factory()->unverified()->create();

        $response = $this->actingAs($user)->get('/verify-email');

        $response->assertStatus(200);
    }

    public function test_email_can_be_verified(): void
    {
        $user = User::factory()->unverified()->create();

        Event::fake();

        $verificationUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => sha1($user->email)]
        );

        $response = $this->actingAs($user)->get($verificationUrl);

        Event::assertDispatched(Verified::class);
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
        $response->assertRedirect(route('dashboard', absolute: false).'?verified=1');
    }

    public function test_email_is_not_verified_with_invalid_hash(): void
    {
        $user = User::factory()->unverified()->create();

        $verificationUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => sha1('wrong-email')]
        );

        $this->actingAs($user)->get($verificationUrl);

        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    /**
     * F1: the same email may be verified on both an ERP and a Dhiran account
     * (login + reset are realm-scoped). The email-OTP "already taken" check must
     * be realm-scoped, so an ERP account holding a verified email does NOT block a
     * Dhiran account from verifying the same email.
     */
    public function test_email_otp_taken_check_is_realm_scoped(): void
    {
        \Illuminate\Support\Facades\Mail::fake();

        // ERP account already verified with this email.
        User::create([
            'mobile_number' => '9300004001', 'password' => bcrypt('x'), 'realm' => 'erp',
            'is_active' => true, 'email' => 'shared@example.com', 'email_verified_at' => now(),
        ]);

        // A Dhiran account tries to verify the SAME email — must be allowed (not "taken").
        $dhiran = User::create([
            'mobile_number' => '9300004001', 'password' => bcrypt('x'), 'realm' => 'dhiran',
            'is_active' => true,
        ]);

        $this->actingAs($dhiran);
        $req = \Illuminate\Http\Request::create('/dhiran/verify-email/send', 'POST', ['email' => 'shared@example.com']);
        $resp = app(\App\Http\Controllers\EmailVerificationOtpController::class)->sendOtp($req);

        $this->assertSame(200, $resp->getStatusCode());
        $this->assertStringContainsString('sent', json_encode($resp->getData()));
        $this->assertSame('shared@example.com', $dhiran->fresh()->email);
    }

    /** F1 negative: same-realm verified email IS still blocked. */
    public function test_email_otp_blocks_same_realm_duplicate(): void
    {
        \Illuminate\Support\Facades\Mail::fake();

        User::create([
            'mobile_number' => '9300004010', 'password' => bcrypt('x'), 'realm' => 'dhiran',
            'is_active' => true, 'email' => 'dup@example.com', 'email_verified_at' => now(),
        ]);
        $other = User::create([
            'mobile_number' => '9300004011', 'password' => bcrypt('x'), 'realm' => 'dhiran', 'is_active' => true,
        ]);

        $this->actingAs($other);
        $req = \Illuminate\Http\Request::create('/dhiran/verify-email/send', 'POST', ['email' => 'dup@example.com']);
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(\App\Http\Controllers\EmailVerificationOtpController::class)->sendOtp($req);
    }
}
