<?php

namespace Tests\Feature;

use App\Models\Platform\Plan;
use App\Models\User;
use App\Services\OnboardingResumeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * The dev:onboard-complete command is the local-only escape hatch from the
 * pay-before-shop onboarding wall: it activates a free trial so a shop can be
 * created without a real Razorpay payment. It must NEVER run outside local.
 */
class DevOnboardCompleteTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();

        // startTrial needs platform_products, plans and a super-admin actor.
        // Seed explicitly (not $seed=true): in the full suite another class
        // triggers the initial migration, so $seed never fires for this class.
        $this->seed([
            \Database\Seeders\PlatformProductSeeder::class,
            \Database\Seeders\PlanSeeder::class,
            \Database\Seeders\PlatformSuperAdminSeeder::class,
        ]);
    }

    private function dhiranUser(): User
    {
        return User::create([
            'mobile_number' => '9811111111',
            'password' => Hash::make('secret-pass-1'),
            'realm' => 'dhiran',
        ]);
    }

    public function test_refuses_to_run_outside_local(): void
    {
        $this->app['env'] = 'production';
        $this->dhiranUser();

        $this->artisan('dev:onboard-complete', ['mobile' => '9811111111'])
            ->assertExitCode(1);

        $this->assertDatabaseCount('shop_subscriptions', 0);
    }

    public function test_grants_a_dhiran_trial_that_unblocks_onboarding(): void
    {
        $this->app['env'] = 'local';
        $user = $this->dhiranUser();

        $this->artisan('dev:onboard-complete', ['mobile' => '9811111111'])
            ->assertExitCode(0);

        $sub = OnboardingResumeService::findPendingSubscription($user->fresh());
        $this->assertNotNull($sub, 'A pending subscription should unblock the Dhiran shop form.');
        $this->assertSame('trial', $sub->status);
        $this->assertNull($sub->shop_id);
        $this->assertSame('dhiran', Plan::find($sub->plan_id)->grantsEdition());
    }
}
