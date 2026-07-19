<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsurePlatformAdminMfa;
use App\Http\Middleware\EnsureSubscriptionIsActive;
use App\Jobs\SendPlatformInvoiceEmail;
use App\Models\Platform\Plan;
use App\Models\Platform\PlatformAdmin;
use App\Models\Platform\ShopSubscription;
use App\Models\Shop;
use App\Support\SubscriptionTerm;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Pins the JF-0001 guided-manual-extension flow. The Super Admin billing path:
 *   - trusts the operator's submitted From/To EXACTLY (never recomputes them),
 *   - derives only the grace window (To + plan.grace_days),
 *   - rejects a To that already ended for an entitling status, a future-dated
 *     entitling term, and a To ≤ From,
 *   - requires an override reason when dates diverge from the safe suggestion,
 *   - retires competing same-product subscriptions without touching other
 *     products (Retail vs Dhiran).
 * Also pins that the scheduler + enforcement middleware leave a valid paid term
 * alone while still downgrading a genuinely expired one.
 */
class AdminBillingTermTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Build a subscription row with explicit calendar dates for boundary tests. */
    private function makeSub(Shop $shop, Plan $plan, string $status, string $endsAt, string $graceEndsAt, string $startsAt = '2020-01-01'): ShopSubscription
    {
        return ShopSubscription::create([
            'shop_id' => $shop->id,
            'plan_id' => $plan->id,
            'status' => $status,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'grace_ends_at' => $graceEndsAt,
            'billing_cycle' => 'yearly',
            'price_paid' => 50000,
            'updated_by_admin_id' => null,
        ]);
    }

    private function retailPlan(): Plan
    {
        return Plan::create([
            'code' => 'retailer_billing_' . fake()->unique()->numberBetween(1000, 999999),
            'name' => 'Retailer',
            'price_monthly' => 4999,
            'price_yearly' => 50000,
            'trial_days' => 20,
            'grace_days' => 7,
            'downgrade_to_read_only_on_due' => true,
            'is_active' => true,
        ]);
    }

    private function dhiranPlan(): Plan
    {
        return Plan::create([
            'code' => 'dhiran_billing_' . fake()->unique()->numberBetween(1000, 999999),
            'name' => 'Dhiran',
            'price_monthly' => 2999,
            'price_yearly' => 30000,
            'trial_days' => 20,
            'grace_days' => 7,
            'downgrade_to_read_only_on_due' => true,
            'is_active' => true,
        ]);
    }

    /** A plan that suspends (does NOT downgrade to read-only) once grace lapses. */
    private function suspendPlan(): Plan
    {
        return Plan::create([
            'code' => 'retailer_suspend_' . fake()->unique()->numberBetween(1000, 999999),
            'name' => 'Retailer (suspend on due)',
            'price_monthly' => 4999,
            'price_yearly' => 50000,
            'trial_days' => 20,
            'grace_days' => 7,
            'downgrade_to_read_only_on_due' => false,
            'is_active' => true,
        ]);
    }

    /** A verified super admin — admin.mfa redirects to verify-email for a null email_verified_at. */
    private function verifiedAdmin(): PlatformAdmin
    {
        $admin = $this->createPlatformAdmin();
        $admin->forceFill(['email_verified_at' => now()])->save();

        return $admin;
    }

    private function actingAsAdmin(PlatformAdmin $admin): self
    {
        return $this->actingAs($admin, 'platform_admin')
            ->withSession([EnsurePlatformAdminMfa::SESSION_PASSED => true]);
    }

    /** Post a subscription change through the real Super Admin route. */
    private function submit(PlatformAdmin $admin, Shop $shop, array $payload)
    {
        return $this->actingAsAdmin($admin)
            ->patch(route('admin.shops.subscription', $shop), $payload);
    }

    /**
     * Payload submitting the SAFE SUGGESTED dates for a fresh shop
     * (From = today, To = today + cycle) — so no override reason is needed.
     */
    private function basePayload(Plan $plan, array $overrides = []): array
    {
        return array_merge([
            'plan_id'    => $plan->id,
            'status'     => 'active',
            'billing_cycle' => 'yearly',
            'starts_at'  => now()->toDateString(),
            'ends_at'    => now()->addYear()->toDateString(),
            'price_paid' => 50000,
            'reason'     => 'test',
        ], $overrides);
    }

    // 1 — yearly activation persists the operator's exact submitted term; grace derived.
    public function test_yearly_activation_persists_submitted_dates(): void
    {
        $admin = $this->verifiedAdmin();
        $shop  = $this->createShop('retailer');
        $plan  = $this->retailPlan();

        $this->submit($admin, $shop, $this->basePayload($plan))->assertRedirect();

        $sub = ShopSubscription::where('shop_id', $shop->id)->latest('id')->first();
        $this->assertSame('active', $sub->status);
        $this->assertSame(now()->toDateString(), Carbon::parse($sub->starts_at)->toDateString());
        $this->assertSame(now()->addYear()->toDateString(), Carbon::parse($sub->ends_at)->toDateString());
        $this->assertSame(
            Carbon::parse($sub->ends_at)->addDays(7)->toDateString(),
            Carbon::parse($sub->grace_ends_at)->toDateString(),
            'Grace must be derived from the submitted To + plan grace_days.'
        );
        $this->assertSame('active', $shop->fresh()->access_mode);
    }

    // 2 — monthly activation persists the submitted full month.
    public function test_monthly_activation_persists_submitted_dates(): void
    {
        $admin = $this->verifiedAdmin();
        $shop  = $this->createShop('retailer');
        $plan  = $this->retailPlan();

        $this->submit($admin, $shop, $this->basePayload($plan, [
            'billing_cycle' => 'monthly',
            'starts_at' => now()->toDateString(),
            'ends_at' => now()->addMonth()->toDateString(),
        ]))->assertRedirect();

        $sub = ShopSubscription::where('shop_id', $shop->id)->latest('id')->first();
        $this->assertSame(now()->addMonth()->toDateString(), Carbon::parse($sub->ends_at)->toDateString());
    }

    // 3 & 12 — an entitling status with a To that already ended is rejected; nothing written.
    public function test_active_status_with_expired_term_is_rejected_and_writes_nothing(): void
    {
        Bus::fake();
        $admin = $this->verifiedAdmin();
        $shop  = $this->createShop('retailer');
        $plan  = $this->retailPlan();

        $res = $this->submit($admin, $shop, $this->basePayload($plan, [
            'starts_at' => now()->subDays(33)->toDateString(),
            'ends_at'   => now()->subDays(13)->toDateString(),
            'reason'    => 'override provided so we fail on the term, not the reason',
        ]));

        $res->assertSessionHasErrors('ends_at');
        $this->assertDatabaseCount('shop_subscriptions', 0);
        $this->assertDatabaseCount('platform_invoices', 0);
        Bus::assertNotDispatched(SendPlatformInvoiceEmail::class);
        $this->assertSame('active', $shop->fresh()->access_mode, 'Shop must be untouched on validation failure.');
    }

    // 4 — a future-dated entitling term is rejected (no scheduled/pending state exists).
    public function test_future_dated_active_term_is_rejected(): void
    {
        $admin = $this->verifiedAdmin();
        $shop  = $this->createShop('retailer');
        $plan  = $this->retailPlan();

        $this->submit($admin, $shop, $this->basePayload($plan, [
            'starts_at' => now()->addDays(10)->toDateString(),
            'ends_at'   => now()->addYear()->addDays(10)->toDateString(),
            'reason'    => 'scheduled start',
        ]))->assertSessionHasErrors('starts_at');

        $this->assertDatabaseCount('shop_subscriptions', 0);
    }

    // 5 — To ≤ From is rejected.
    public function test_to_not_after_from_is_rejected(): void
    {
        $admin = $this->verifiedAdmin();
        $shop  = $this->createShop('retailer');
        $plan  = $this->retailPlan();

        $this->submit($admin, $shop, $this->basePayload($plan, [
            'starts_at' => now()->toDateString(),
            'ends_at'   => now()->toDateString(),
            'reason'    => 'x',
        ]))->assertSessionHasErrors('ends_at');

        $this->assertDatabaseCount('shop_subscriptions', 0);
    }

    // 6 — dates diverging from the suggestion require an override reason; with one they're honoured exactly.
    public function test_off_suggestion_dates_require_reason_then_are_honoured(): void
    {
        $admin = $this->verifiedAdmin();
        $shop  = $this->createShop('retailer');
        $plan  = $this->retailPlan();
        $customTo = now()->addDays(45)->toDateString();

        // No reason → rejected.
        $this->submit($admin, $shop, $this->basePayload($plan, [
            'ends_at' => $customTo,
            'reason'  => '',
        ]))->assertSessionHasErrors('reason');
        $this->assertDatabaseCount('shop_subscriptions', 0);

        // With reason → persisted with the exact submitted To.
        $this->submit($admin, $shop, $this->basePayload($plan, [
            'ends_at' => $customTo,
            'reason'  => 'Pro-rated onboarding term',
        ]))->assertRedirect();

        $sub = ShopSubscription::where('shop_id', $shop->id)->latest('id')->first();
        $this->assertSame($customTo, Carbon::parse($sub->ends_at)->toDateString());
    }

    // 7 — a new term retires the prior SAME-product sub but leaves another product's sub alone.
    public function test_new_term_supersedes_same_product_but_not_other_product(): void
    {
        $admin      = $this->verifiedAdmin();
        $shop       = $this->createShop('retailer');
        $retailPlan = $this->retailPlan();
        $dhiranPlan = $this->dhiranPlan();

        // A live Dhiran subscription on the same shop — must survive a Retail edit.
        $dhiranSub = ShopSubscription::create([
            'shop_id' => $shop->id,
            'plan_id' => $dhiranPlan->id,
            'status' => 'active',
            'starts_at' => now()->subMonth()->toDateString(),
            'ends_at' => now()->addYear()->toDateString(),
            'grace_ends_at' => now()->addYear()->addDays(7)->toDateString(),
            'billing_cycle' => 'yearly',
            'updated_by_admin_id' => $admin->id,
        ]);

        // A prior Retail subscription — must be superseded by the new one.
        $priorRetail = ShopSubscription::create([
            'shop_id' => $shop->id,
            'plan_id' => $retailPlan->id,
            'status' => 'active',
            'starts_at' => now()->subMonth()->toDateString(),
            'ends_at' => now()->addMonths(11)->toDateString(),
            'grace_ends_at' => now()->addMonths(11)->addDays(7)->toDateString(),
            'billing_cycle' => 'yearly',
            'updated_by_admin_id' => $admin->id,
        ]);

        // Suggestion continues from the live prior Retail ends_at, so our today→+year
        // submission diverges → supply a reason.
        $this->submit($admin, $shop, $this->basePayload($retailPlan, [
            'reason' => 'manual re-issue',
        ]))->assertRedirect();

        $this->assertSame('cancelled', $priorRetail->fresh()->status, 'Prior same-product sub must be superseded.');
        $this->assertSame('active', $dhiranSub->fresh()->status, 'Other-product sub must be untouched.');
    }

    // 8 — scheduler leaves a valid paid yearly term alone.
    public function test_scheduler_does_not_revert_valid_paid_yearly(): void
    {
        $admin = $this->verifiedAdmin();
        $shop  = $this->createShop('retailer');
        $plan  = $this->retailPlan();

        ShopSubscription::create([
            'shop_id' => $shop->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'starts_at' => now()->toDateString(),
            'ends_at' => now()->addYear()->toDateString(),
            'grace_ends_at' => now()->addYear()->addDays(7)->toDateString(),
            'billing_cycle' => 'yearly',
            'price_paid' => 50000,
            'updated_by_admin_id' => $admin->id,
        ]);

        $this->artisan('subscription:check-expiry')->assertExitCode(0);

        $this->assertSame('active', ShopSubscription::where('shop_id', $shop->id)->latest('id')->first()->status);
        $this->assertSame('active', $shop->fresh()->access_mode);
    }

    // 9 — scheduler still downgrades a genuinely expired term.
    public function test_scheduler_downgrades_genuinely_expired_term(): void
    {
        $admin = $this->verifiedAdmin();
        $shop  = $this->createShop('retailer');
        $plan  = $this->retailPlan();

        ShopSubscription::create([
            'shop_id' => $shop->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'starts_at' => now()->subYear()->toDateString(),
            'ends_at' => now()->subDay()->toDateString(),   // expired
            'grace_ends_at' => now()->subDay()->toDateString(),
            'billing_cycle' => 'yearly',
            'price_paid' => 50000,
            'updated_by_admin_id' => $admin->id,
        ]);

        $this->artisan('subscription:check-expiry')->assertExitCode(0);

        $this->assertSame('read_only', ShopSubscription::where('shop_id', $shop->id)->latest('id')->first()->status);
        $this->assertSame('read_only', $shop->fresh()->access_mode);
    }

    // 10 — enforcement middleware does not override a valid active subscription.
    public function test_middleware_keeps_valid_active_subscription(): void
    {
        config(['platform.enforce_subscriptions' => true]);
        $admin = $this->verifiedAdmin();
        $shop  = $this->createShop('retailer');
        $role  = $this->createOwnerRole($shop->id);
        $user  = $this->createOwnerUser($shop, $role);
        $plan  = $this->retailPlan();

        ShopSubscription::create([
            'shop_id' => $shop->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'starts_at' => now()->toDateString(),
            'ends_at' => now()->addYear()->toDateString(),
            'grace_ends_at' => now()->addYear()->addDays(7)->toDateString(),
            'billing_cycle' => 'yearly',
            'price_paid' => 50000,
            'updated_by_admin_id' => $admin->id,
        ]);

        $this->actingAs($user);
        $middleware = app(EnsureSubscriptionIsActive::class);
        $response = $middleware->handle(Request::create('/dashboard', 'GET'), fn ($r) => response('ok'));

        $this->assertSame('ok', $response->getContent());
        $this->assertSame('active', $shop->fresh()->access_mode,
            'A valid active subscription must not be flipped by the enforcement middleware.');
    }

    // 11 — invoice period matches the finalized submitted subscription period (feeds the email).
    public function test_invoice_period_matches_finalized_subscription_period(): void
    {
        Bus::fake();
        $admin = $this->verifiedAdmin();
        $shop  = $this->createShop('retailer');
        $plan  = $this->retailPlan();

        $this->submit($admin, $shop, $this->basePayload($plan))->assertRedirect();

        $sub = ShopSubscription::where('shop_id', $shop->id)->latest('id')->first();
        $invoice = \App\Models\Platform\PlatformInvoice::where('shop_subscription_id', $sub->id)->first();

        $this->assertNotNull($invoice);
        $this->assertSame(
            Carbon::parse($sub->starts_at)->toDateString(),
            Carbon::parse($invoice->billing_period_start)->toDateString()
        );
        $this->assertSame(
            Carbon::parse($sub->ends_at)->toDateString(),
            Carbon::parse($invoice->billing_period_end)->toDateString(),
            'Invoice period must be the exact submitted term.'
        );
        Bus::assertDispatched(SendPlatformInvoiceEmail::class);
    }

    // 13 — a repeated submission leaves exactly one entitling row (no competing active subs).
    public function test_repeated_submission_leaves_single_entitling_row(): void
    {
        $admin = $this->verifiedAdmin();
        $shop  = $this->createShop('retailer');
        $plan  = $this->retailPlan();

        $this->submit($admin, $shop, $this->basePayload($plan))->assertRedirect();
        // Second submit continues from the now-live term, so today→+year diverges → reason.
        $this->submit($admin, $shop, $this->basePayload($plan, ['reason' => 're-issue']))->assertRedirect();

        $entitling = ShopSubscription::where('shop_id', $shop->id)
            ->whereIn('status', ['active', 'trial', 'grace'])
            ->count();
        $this->assertSame(1, $entitling, 'Only the latest term may remain entitling.');

        $latest = ShopSubscription::where('shop_id', $shop->id)->latest('id')->first();
        $this->assertSame('active', $latest->status);
        $this->assertTrue(Carbon::parse($latest->ends_at)->isFuture());
        $this->assertSame('active', $shop->fresh()->access_mode);
    }

    // 14 — admin and Razorpay paths share the same central term rules.
    // Inclusive To: a one-cycle term runs From THROUGH the day before the next
    // anniversary, so To = From + 1 cycle (no overflow) − 1 day.
    public function test_central_term_helper_matches_yearly_and_monthly_math(): void
    {
        $plan = $this->retailPlan();
        $start = Carbon::parse('2026-06-16');

        $this->assertSame('2027-06-15', SubscriptionTerm::endsAtFor('yearly', $start)->toDateString());
        $this->assertSame('2026-07-15', SubscriptionTerm::endsAtFor('monthly', $start)->toDateString());
        $this->assertSame(
            '2027-06-22',
            SubscriptionTerm::graceEndsAtFor(SubscriptionTerm::endsAtFor('yearly', $start), $plan)->toDateString()
        );
    }

    // 15 — the suggestion policy: continue from a live term, else start today.
    public function test_suggest_policy_continues_live_term_else_today(): void
    {
        $now = Carbon::parse('2026-06-16');

        $fresh = SubscriptionTerm::suggest(null, 'yearly', $now);
        $this->assertSame('2026-06-16', $fresh['from']->toDateString());
        $this->assertSame('2027-06-15', $fresh['to']->toDateString());

        // Inclusive To: prior term covers THROUGH 2026-12-31, so continuation starts
        // the next day (2027-01-01) and runs a full inclusive month THROUGH 2027-01-31
        // — no gap, no overlap.
        $live = new ShopSubscription(['status' => 'active', 'ends_at' => '2026-12-31', 'grace_ends_at' => '2027-01-07']);
        $cont = SubscriptionTerm::suggest($live, 'monthly', $now);
        $this->assertSame('2027-01-01', $cont['from']->toDateString());
        $this->assertSame('2027-01-31', $cont['to']->toDateString());

        $lapsed = new ShopSubscription(['status' => 'expired', 'ends_at' => '2026-01-01', 'grace_ends_at' => '2026-01-08']);
        $today = SubscriptionTerm::suggest($lapsed, 'yearly', $now);
        $this->assertSame('2026-06-16', $today['from']->toDateString());
    }

    // ── Calendar-boundary tests (Asia/Kolkata inclusive business dates) ──────────
    // Term: ends_at 2026-07-10 (To), grace_days 7 → grace_ends_at 2026-07-17.

    // 16 — midday on the From day: active.
    public function test_from_day_midday_is_active(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 7, 10, 12, 0, 0, 'Asia/Kolkata'));
        $shop = $this->createShop('retailer');
        $this->makeSub($shop, $this->retailPlan(), 'active', '2027-07-10', '2027-07-17', '2026-07-10');

        $this->artisan('subscription:check-expiry')->assertExitCode(0);

        $this->assertSame('active', ShopSubscription::where('shop_id', $shop->id)->latest('id')->first()->status);
        $this->assertSame('active', $shop->fresh()->access_mode);
    }

    // 17 — midday on the To day: STILL active (inclusive To). This is the core JF bug.
    public function test_to_day_midday_remains_active(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 7, 10, 12, 0, 0, 'Asia/Kolkata'));
        $shop = $this->createShop('retailer');
        $this->makeSub($shop, $this->retailPlan(), 'active', '2026-07-10', '2026-07-17');

        $this->artisan('subscription:check-expiry')->assertExitCode(0);

        $this->assertSame('active', ShopSubscription::where('shop_id', $shop->id)->latest('id')->first()->status,
            'A term whose To is today must not expire at midday.');
        $this->assertSame('active', $shop->fresh()->access_mode);
    }

    // 18 — the day after To enters grace (grace status keeps access).
    public function test_day_after_to_enters_grace(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 7, 11, 12, 0, 0, 'Asia/Kolkata'));
        $shop = $this->createShop('retailer');
        $this->makeSub($shop, $this->retailPlan(), 'active', '2026-07-10', '2026-07-17');

        $this->artisan('subscription:check-expiry')->assertExitCode(0);

        $this->assertSame('grace', ShopSubscription::where('shop_id', $shop->id)->latest('id')->first()->status);
    }

    // 19 — the final grace day (businessDate == grace_ends_at) is STILL grace.
    public function test_final_grace_day_remains_in_grace(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 7, 17, 12, 0, 0, 'Asia/Kolkata'));
        $shop = $this->createShop('retailer');
        $this->makeSub($shop, $this->retailPlan(), 'active', '2026-07-10', '2026-07-17');

        $this->artisan('subscription:check-expiry')->assertExitCode(0);

        $this->assertSame('grace', ShopSubscription::where('shop_id', $shop->id)->latest('id')->first()->status,
            'The last grace day must remain in grace, not lapse early at midday.');
    }

    // 20 — the day after grace: a suspend-on-due plan is suspended; a downgrade plan is read-only.
    public function test_day_after_grace_lapses_per_plan_policy(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 7, 18, 12, 0, 0, 'Asia/Kolkata'));

        $suspendShop = $this->createShop('retailer');
        $this->makeSub($suspendShop, $this->suspendPlan(), 'active', '2026-07-10', '2026-07-17');

        $readOnlyShop = $this->createShop('retailer');
        $this->makeSub($readOnlyShop, $this->retailPlan(), 'active', '2026-07-10', '2026-07-17');

        $this->artisan('subscription:check-expiry')->assertExitCode(0);

        $this->assertSame('expired', ShopSubscription::where('shop_id', $suspendShop->id)->latest('id')->first()->status);
        $this->assertSame('suspended', $suspendShop->fresh()->access_mode);

        $this->assertSame('read_only', ShopSubscription::where('shop_id', $readOnlyShop->id)->latest('id')->first()->status);
        $this->assertSame('read_only', $readOnlyShop->fresh()->access_mode);
    }

    // 21 — a grace-status row survives its final grace day, then lapses the next day (block 2).
    public function test_grace_status_row_is_inclusive_of_final_grace_day(): void
    {
        $shop = $this->createShop('retailer');
        $this->makeSub($shop, $this->retailPlan(), 'grace', '2026-07-10', '2026-07-17');

        Carbon::setTestNow(Carbon::create(2026, 7, 17, 12, 0, 0, 'Asia/Kolkata'));
        $this->artisan('subscription:check-expiry')->assertExitCode(0);
        $this->assertSame('grace', ShopSubscription::where('shop_id', $shop->id)->latest('id')->first()->status,
            'Grace row must remain grace on its final grace day.');

        Carbon::setTestNow(Carbon::create(2026, 7, 18, 12, 0, 0, 'Asia/Kolkata'));
        $this->artisan('subscription:check-expiry')->assertExitCode(0);
        $this->assertSame('read_only', ShopSubscription::where('shop_id', $shop->id)->latest('id')->first()->status,
            'Grace row lapses the day after grace ends.');
    }

    // 22 — scheduler and enforcement middleware AGREE on the final grace day: both keep access.
    public function test_scheduler_and_middleware_agree_on_final_grace_day(): void
    {
        config(['platform.enforce_subscriptions' => true]);
        Carbon::setTestNow(Carbon::create(2026, 7, 17, 12, 0, 0, 'Asia/Kolkata'));

        $shop = $this->createShop('retailer');
        $role = $this->createOwnerRole($shop->id);
        $user = $this->createOwnerUser($shop, $role);
        $this->makeSub($shop, $this->retailPlan(), 'active', '2026-07-10', '2026-07-17');

        // Scheduler: active term whose To has passed but grace is current → grace.
        $this->artisan('subscription:check-expiry')->assertExitCode(0);
        $this->assertSame('grace', ShopSubscription::where('shop_id', $shop->id)->latest('id')->first()->status);

        // Middleware: a grace subscription keeps the shop accessible — it must NOT
        // flip the access mode the scheduler just set.
        $this->actingAs($user);
        $response = app(EnsureSubscriptionIsActive::class)
            ->handle(Request::create('/dashboard', 'GET'), fn ($r) => response('ok'));

        $this->assertSame('ok', $response->getContent());
        $this->assertSame('active', $shop->fresh()->access_mode);
    }

    // 23 — business date is Asia/Kolkata: an instant that is the PREVIOUS day in UTC
    // but the CURRENT day in IST must be treated as the IST date.
    public function test_business_date_uses_ist_not_utc(): void
    {
        // 2026-07-11 01:30 IST == 2026-07-10 20:00 UTC.
        Carbon::setTestNow(Carbon::create(2026, 7, 11, 1, 30, 0, 'Asia/Kolkata'));
        $this->assertSame('2026-07-11', Carbon::now()->toDateString(), 'IST business date');
        $this->assertSame('2026-07-10', Carbon::now()->copy()->utc()->toDateString(), 'same instant is previous day in UTC');

        $shop = $this->createShop('retailer');
        $this->makeSub($shop, $this->retailPlan(), 'active', '2026-07-10', '2026-07-17');

        $this->artisan('subscription:check-expiry')->assertExitCode(0);

        // If UTC (2026-07-10) were used, the term would NOT be expired and stay active.
        // Correct IST handling (2026-07-11 > 2026-07-10) moves it into grace.
        $this->assertSame('grace', ShopSubscription::where('shop_id', $shop->id)->latest('id')->first()->status);
    }

    // 24 — month-end and leap-year term arithmetic: inclusive To = From + 1 cycle
    // (NO overflow) − 1 day. Never rolls into the following month (no "03 March"
    // Jan-31 overflow). Documented examples in SubscriptionTerm.
    public function test_month_end_and_leap_year_term_math(): void
    {
        // Jan 31 monthly, NON-leap year: clamps to Feb 28 then −1 → Feb 27.
        $this->assertSame('2026-02-27', SubscriptionTerm::endsAtFor('monthly', Carbon::parse('2026-01-31'))->toDateString());
        // Jan 31 monthly, LEAP year: clamps to Feb 29 then −1 → Feb 28.
        $this->assertSame('2028-02-28', SubscriptionTerm::endsAtFor('monthly', Carbon::parse('2028-01-31'))->toDateString());
        // Mar 31 monthly: clamps to Apr 30 then −1 → Apr 29.
        $this->assertSame('2026-04-29', SubscriptionTerm::endsAtFor('monthly', Carbon::parse('2026-03-31'))->toDateString());
        // Feb 29 (leap) yearly: next year has no Feb 29 → clamps to Feb 28 then −1 → Feb 27.
        $this->assertSame('2029-02-27', SubscriptionTerm::endsAtFor('yearly', Carbon::parse('2028-02-29'))->toDateString());
    }

    // 24b — a full inclusive MONTHLY term: From 19 Jul 2026 runs THROUGH 18 Aug 2026,
    // and the next term begins 19 Aug 2026 (To + 1 day) — no gap, no overlap.
    public function test_full_inclusive_monthly_term_has_no_gap_or_overlap(): void
    {
        $from = Carbon::parse('2026-07-19');
        $to   = SubscriptionTerm::endsAtFor('monthly', $from);
        $this->assertSame('2026-08-18', $to->toDateString());

        $nextFrom = $to->copy()->addDay();
        $this->assertSame('2026-08-19', $nextFrom->toDateString(), 'Next term starts the day after To — no gap, no overlap.');
        $this->assertSame('2026-09-18', SubscriptionTerm::endsAtFor('monthly', $nextFrom)->toDateString());
    }

    // 24c — a full inclusive YEARLY term: From 19 Jul 2026 runs THROUGH 18 Jul 2027,
    // next term begins 19 Jul 2027.
    public function test_full_inclusive_yearly_term_has_no_gap_or_overlap(): void
    {
        $from = Carbon::parse('2026-07-19');
        $to   = SubscriptionTerm::endsAtFor('yearly', $from);
        $this->assertSame('2027-07-18', $to->toDateString());
        $this->assertSame('2027-07-19', $to->copy()->addDay()->toDateString());
    }

    // 24d — automatic Razorpay renewal (before expiry) starts the day AFTER the
    // previous inclusive To — never ON the previous To (which would double-bill a day).
    public function test_razorpay_renewal_starts_day_after_previous_to(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 7, 15, 12, 0, 0, 'Asia/Kolkata'));
        $shop = $this->createShop('retailer');
        $role = $this->createOwnerRole($shop->id);
        $user = $this->createOwnerUser($shop, $role);
        $plan = $this->retailPlan();

        // Current live yearly term: From 2025-07-19 THROUGH 2026-07-18 (inclusive To).
        $current = ShopSubscription::create([
            'shop_id' => $shop->id,
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'starts_at' => '2025-07-19',
            'ends_at' => '2026-07-18',
            'grace_ends_at' => '2026-07-25',
            'billing_cycle' => 'yearly',
            'price_paid' => 50000,
            'updated_by_admin_id' => null,
        ]);

        $this->actingAs($user);
        $renewed = app(\App\Services\SubscriptionPaymentService::class)
            ->renewSubscription($current, 'yearly', 50000, 'pay_renew_1', 'order_renew_1');

        // Renewal begins previous To + 1 day, and runs a full inclusive year.
        $this->assertSame('2026-07-19', Carbon::parse($renewed->starts_at)->toDateString(),
            'Renewal must start the day AFTER the previous inclusive To, never on it.');
        $this->assertSame('2027-07-18', Carbon::parse($renewed->ends_at)->toDateString());

        // No overlap: prior To (2026-07-18) is strictly before the new From (2026-07-19).
        $this->assertTrue(
            Carbon::parse($current->ends_at)->lt(Carbon::parse($renewed->starts_at)),
            'Consecutive terms must not overlap.'
        );
    }

    // 24e — a manual override (operator's off-suggestion dates) is persisted EXACTLY,
    // never recomputed by the inclusive arithmetic.
    public function test_manual_override_dates_persist_unchanged(): void
    {
        $admin = $this->verifiedAdmin();
        $shop  = $this->createShop('retailer');
        $plan  = $this->retailPlan();

        // Deliberately odd, off-suggestion To — must survive verbatim.
        $customFrom = now()->toDateString();
        $customTo   = now()->addDays(400)->toDateString();

        $this->submit($admin, $shop, $this->basePayload($plan, [
            'starts_at' => $customFrom,
            'ends_at'   => $customTo,
            'reason'    => 'Bespoke onboarding term',
        ]))->assertRedirect();

        $sub = ShopSubscription::where('shop_id', $shop->id)->latest('id')->first();
        $this->assertSame($customFrom, Carbon::parse($sub->starts_at)->toDateString());
        $this->assertSame($customTo, Carbon::parse($sub->ends_at)->toDateString(),
            'Operator dates are authoritative — never recomputed to the suggested term.');
    }

    // 25 — real idempotency: a double IDENTICAL submit yields one sub, one invoice,
    // one email, no cancel-and-recreate loop, shop stays active.
    public function test_double_identical_submission_is_idempotent(): void
    {
        Bus::fake();
        $admin = $this->verifiedAdmin();
        $shop  = $this->createShop('retailer');
        $plan  = $this->retailPlan();

        $payload = $this->basePayload($plan);
        $this->submit($admin, $shop, $payload)->assertRedirect();
        $this->submit($admin, $shop, $payload)->assertRedirect();

        $this->assertDatabaseCount('shop_subscriptions', 1);
        $this->assertDatabaseCount('platform_invoices', 1);
        Bus::assertDispatchedTimes(SendPlatformInvoiceEmail::class, 1);
        $this->assertSame(0, ShopSubscription::where('shop_id', $shop->id)->where('status', 'cancelled')->count(),
            'No cancel-and-recreate loop on identical resubmission.');
        $this->assertSame('active', $shop->fresh()->access_mode);
    }

    // 26 — Fix 2 durability: a failure inside the write transaction rolls everything
    // back and dispatches NO invoice email. Email is dispatched only AFTER commit, so
    // a rolled-back term can never leak a receipt.
    public function test_transaction_failure_writes_nothing_and_sends_no_email(): void
    {
        Bus::fake();
        $admin = $this->verifiedAdmin();
        $shop  = $this->createShop('retailer');
        $plan  = $this->retailPlan();

        // Force the in-transaction invoice step to blow up (price_paid > 0 reaches it).
        $this->mock(\App\Services\PlatformInvoiceService::class, function ($mock) {
            $mock->shouldReceive('issueForSubscription')
                ->andThrow(new \RuntimeException('invoice failed mid-transaction'));
        });

        try {
            $this->submit($admin, $shop, $this->basePayload($plan));
        } catch (\Throwable $e) {
            // The controller does not swallow the failure — that's fine; we assert on state.
        }

        $this->assertDatabaseCount('shop_subscriptions', 0); // rolled-back transaction persists nothing
        $this->assertDatabaseCount('platform_invoices', 0);
        Bus::assertNotDispatched(SendPlatformInvoiceEmail::class);
        $this->assertSame('active', $shop->fresh()->access_mode, 'Shop must be untouched when the transaction rolls back.');
    }
}
