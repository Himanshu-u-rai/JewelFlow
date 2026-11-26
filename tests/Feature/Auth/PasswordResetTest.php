<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Web POSTs through the real stack need CSRF disabled (else 419) and the
        // forgot-password throttle disabled (the array cache accumulates hits
        // across the suite → 429). Per-class pattern, like other web tests.
        $this->withoutMiddleware([
            \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
            \Illuminate\Routing\Middleware\ThrottleRequests::class,
        ]);
    }

    public function test_reset_password_link_screen_can_be_rendered(): void
    {
        $response = $this->get('/forgot-password');

        $response->assertStatus(200);
    }

    public function test_reset_password_link_can_be_requested(): void
    {
        Notification::fake();

        // The mobile-first UserFactory leaves email null; email-based reset needs
        // a real address so the password broker can locate the user.
        $user = User::factory()->create(['email' => 'reset1@example.com']);

        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_the_reply_does_not_reveal_whether_an_account_uses_the_email(): void
    {
        Notification::fake();
        User::factory()->create(['email' => 'known@example.com']);

        $known = $this->from('/forgot-password')->post('/forgot-password', ['email' => 'known@example.com']);
        $knownStatus = session('status');
        $this->flushSession();
        $unknown = $this->from('/forgot-password')->post('/forgot-password', ['email' => 'nobody@example.com']);

        $unknown->assertRedirect('/forgot-password')->assertSessionHasNoErrors();
        $known->assertRedirect('/forgot-password');
        $this->assertNotEmpty($knownStatus);
        $this->assertSame($knownStatus, session('status'), 'Known and unknown emails must get the same reply.');
        $this->assertStringNotContainsStringIgnoringCase("can't find", (string) $knownStatus);
    }

    public function test_a_repeat_request_gets_the_same_reply_and_sends_only_one_email(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'repeat@example.com']);

        $this->post('/forgot-password', ['email' => $user->email]);
        $first = session('status');
        $this->flushSession();
        $second = $this->post('/forgot-password', ['email' => $user->email]);

        $second->assertSessionHasNoErrors();
        $this->assertSame($first, session('status'), 'The per-user resend throttle must not reveal that the account exists.');
        Notification::assertSentToTimes($user, ResetPassword::class, 1);
    }

    public function test_an_invalid_email_is_still_rejected_as_invalid(): void
    {
        $this->post('/forgot-password', ['email' => 'not-an-email'])->assertSessionHasErrors('email');
    }

    public function test_reset_password_screen_can_be_rendered(): void
    {
        Notification::fake();

        $user = User::factory()->create(['email' => 'reset2@example.com']);

        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function ($notification) {
            $response = $this->get('/reset-password/'.$notification->token);

            $response->assertStatus(200);

            return true;
        });
    }

    public function test_password_can_be_reset_with_valid_token(): void
    {
        Notification::fake();

        $user = User::factory()->create(['email' => 'reset3@example.com']);

        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function ($notification) use ($user) {
            $response = $this->post('/reset-password', [
                'token' => $notification->token,
                'email' => $user->email,
                // Must satisfy the app's hardened password policy.
                'password' => 'ResetPassword123!',
                'password_confirmation' => 'ResetPassword123!',
            ]);

            $response
                ->assertSessionHasNoErrors()
                ->assertRedirect(route('login'));

            return true;
        });
    }
}
