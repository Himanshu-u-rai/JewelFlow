<?php

namespace Tests\Feature\Profile;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * F04: the Change Mobile page must show the same eligibility the server
 * enforces (email present AND verified), never "Not set / Verified", and never
 * offer a send-code form that the server will refuse.
 */
class MobileChangeStateTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    private function owner(?string $email, bool $verified): User
    {
        $admin = $this->createPlatformAdmin();
        $plan  = $this->createPlan('manufacturer');
        $shop  = $this->createShop('manufacturer');
        $role  = $this->createOwnerRole($shop->id);
        $user  = $this->createOwnerUser($shop, $role);
        $this->createBillingSettings($shop->id);
        $this->markShopOpeningSetupComplete($shop->id);
        $this->createSubscription($shop->id, $admin, $plan);

        $user->forceFill([
            'email'             => $email,
            'email_verified_at' => $verified ? now() : null,
        ])->save();

        return $user->refresh();
    }

    public function test_no_email_shows_guidance_and_no_send_code_form(): void
    {
        $user = $this->owner(null, true); // stale verified stamp with no address

        $res = $this->actingAs($user)->get(route('profile.mobile.change'));

        $res->assertOk();
        $res->assertDontSee('Verified', false);
        $res->assertDontSee('we will email a 6-digit code', false);
        $res->assertDontSee(route('profile.mobile.request'), false);
        $res->assertSee('Add an email address', false);
        $res->assertSee(route('profile.edit'), false);
    }

    public function test_unverified_email_shows_verify_guidance_and_no_send_code_form(): void
    {
        $user = $this->owner('owner@example.com', false);

        $res = $this->actingAs($user)->get(route('profile.mobile.change'));

        $res->assertOk();
        $res->assertSee('owner@example.com', false);
        $res->assertSee('Not verified', false);
        $res->assertSee('You must verify your email address', false);
        $res->assertSee(route('profile.edit'), false);
        $res->assertDontSee(route('profile.mobile.request'), false);
    }

    public function test_verified_email_shows_send_code_form(): void
    {
        $user = $this->owner('owner@example.com', true);

        $res = $this->actingAs($user)->get(route('profile.mobile.change'));

        $res->assertOk();
        $res->assertSee('owner@example.com', false);
        $res->assertSee('Verified', false);
        $res->assertSee(route('profile.mobile.request'), false);
        $res->assertSee('Send verification code', false);
        $res->assertSee('for="mobile-change-new-number"', false);
        $res->assertSee('id="mobile-change-current-password"', false);
    }

    public function test_code_request_without_email_is_still_refused(): void
    {
        Mail::fake();
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
        $user = $this->owner(null, true);

        $res = $this->actingAs($user)->post(route('profile.mobile.request'), [
            'current_password'  => 'password',
            'new_mobile_number' => '9876543210',
        ]);

        $res->assertSessionHas('error');
        $this->assertDatabaseCount('mobile_change_requests', 0);
        Mail::assertNothingSent();
    }
}
