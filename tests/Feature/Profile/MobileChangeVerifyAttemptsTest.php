<?php

namespace Tests\Feature\Profile;

use App\Models\MobileChangeRequest;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Audit N18 (2026-11-13): the mobile-change code allows 4 guesses. The counter was raised in the database, but the
 * cap was checked on the request's own earlier read, so guesses sent at once all saw a low count and were all
 * checked: the cap held only for one guess at a time. And two correct submits both applied the change. Each guess
 * now takes a slot in the database, refused once the slots are gone, and only one submit consumes the request.
 */
class MobileChangeVerifyAttemptsTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    private const ERP = 'https://jewelflows.com';
    private const NEW = '9811122233';

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
        Mail::fake();
    }

    /** @return array{User, int, MobileChangeRequest} */
    private function pendingChange(): array
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $owner->forceFill(['email' => 'owner@example.test', 'email_verified_at' => now()])->save();
        $request = MobileChangeRequest::create([
            'user_id' => $owner->id, 'new_mobile_number' => self::NEW, 'otp_hash' => Hash::make('123456'),
            'expires_at' => now()->addMinutes(10), 'attempts' => 0,
        ]);

        return [$owner->refresh(), $shop->id, $request];
    }

    private function verify(User $owner, int $shopId, string $otp)
    {
        return TenantContext::runFor($shopId, fn () => $this->actingAs($owner)
            ->post(self::ERP . '/profile/mobile/change-verify', ['otp' => $otp]));
    }

    /** Right after the submit loads the request, other submits change it in the database. */
    private function changedOnceLoaded(MobileChangeRequest $request, array $columns): void
    {
        $done = false;
        Event::listen('eloquent.retrieved: ' . MobileChangeRequest::class, function (MobileChangeRequest $loaded) use ($request, $columns, &$done) {
            if (! $done && $loaded->id === $request->id) {
                $done = true;
                DB::table('mobile_change_requests')->where('id', $request->id)->update($columns);
            }
        });
    }

    public function test_guesses_counted_by_other_submits_meanwhile_still_use_up_the_slots(): void
    {
        [$owner, $shopId, $request] = $this->pendingChange();
        $this->changedOnceLoaded($request, ['attempts' => 4]); // four guesses landed at the same moment

        $this->verify($owner, $shopId, '123456')->assertSessionHas('error');

        $this->assertNotSame(self::NEW, $owner->fresh()->mobile_number, 'a fifth guess is never checked');
    }

    public function test_a_request_consumed_by_another_submit_meanwhile_is_not_applied_again(): void
    {
        [$owner, $shopId, $request] = $this->pendingChange();
        $this->changedOnceLoaded($request, ['consumed_at' => now()]);

        $this->verify($owner, $shopId, '123456')->assertSessionHas('error');

        $this->assertNotSame(self::NEW, $owner->fresh()->mobile_number);
    }

    public function test_four_guesses_are_allowed_one_at_a_time(): void
    {
        [$owner, $shopId] = $this->pendingChange();
        foreach (['000001', '000002', '000003'] as $wrong) {
            $this->verify($owner, $shopId, $wrong)->assertSessionHasErrors('otp');
        }

        $this->verify($owner, $shopId, '123456')->assertSessionHas('success');
        $this->assertSame(self::NEW, $owner->fresh()->mobile_number);
    }

    /**
     * Found breaking N18: the success step signed other devices out with Auth::logoutOtherDevices(current_password),
     * but the code form sends no password, so it threw and rolled the change back: no self-service mobile change
     * ever completed. Other sessions of the user are now ended directly, as the admin's mobile change does.
     */
    public function test_a_right_code_changes_the_number_and_ends_the_users_other_sessions(): void
    {
        [$owner, $shopId] = $this->pendingChange();
        DB::table('sessions')->insert(['id' => 'other-device', 'user_id' => $owner->id, 'payload' => '', 'last_activity' => time()]);

        $this->verify($owner, $shopId, '123456')->assertSessionHas('success');

        $this->assertSame(self::NEW, $owner->fresh()->mobile_number);
        $this->assertFalse(DB::table('sessions')->where('id', 'other-device')->exists());
    }

    public function test_the_fifth_guess_is_refused_even_when_right(): void
    {
        [$owner, $shopId] = $this->pendingChange();
        foreach (['000001', '000002', '000003', '000004'] as $wrong) {
            $this->verify($owner, $shopId, $wrong)->assertSessionHasErrors('otp');
        }

        $this->verify($owner, $shopId, '123456')->assertSessionHas('error');
        $this->assertNotSame(self::NEW, $owner->fresh()->mobile_number);
    }
}
