<?php

namespace Tests\Feature\EditionRequest;

use App\Http\Middleware\EnsurePlatformAdminMfa;
use App\Models\Platform\PlatformAdmin;
use App\Models\Shop;
use App\Models\ShopEditionRequest;
use App\Models\User;
use App\Support\ShopEdition;
use App\Support\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Audit N4 (2026-11-13): approve, deny and the owner's cancel checked "pending" on the request as first read, then
 * saved their own outcome unconditionally. An approve racing a cancel granted the edition while the request ended
 * "cancelled"; a deny after an approve relabelled a granted edition "denied". And "already pending" was a look-then-
 * insert (the remove path did not look at all), so a double submit queued two requests. Each decision now takes the
 * request's row lock and re-checks; one pending request per shop, edition and action is a database rule.
 */
class EditionRequestDecisionRaceTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    private const ERP = 'https://jewelflows.com';

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    private function pendingAdd(Shop $shop, User $owner): ShopEditionRequest
    {
        return TenantContext::runFor($shop->id, fn () => ShopEditionRequest::create([
            'shop_id' => $shop->id, 'user_id' => $owner->id, 'action' => ShopEditionRequest::ACTION_ADD,
            'edition' => ShopEdition::DHIRAN, 'reason' => 'We lend against gold', 'status' => ShopEditionRequest::STATUS_PENDING,
        ]));
    }

    private function admin(): PlatformAdmin
    {
        $admin = $this->createPlatformAdmin();
        $admin->forceFill(['email_verified_at' => now()])->save();

        return $admin;
    }

    private function asAdmin(string $decision, ShopEditionRequest $request, array $data = [])
    {
        return $this->actingAs($this->admin(), 'platform_admin')
            ->withSession([EnsurePlatformAdminMfa::SESSION_PASSED => true])
            ->post(route('admin.edition-requests.' . $decision, $request->id), $data);
    }

    /** The $nth time the request is loaded (after the controller has checked it), someone else decides it. */
    private function decidedAfterLoad(ShopEditionRequest $request, int $nth, string $status): void
    {
        $seen = 0;
        Event::listen('eloquent.retrieved: ' . ShopEditionRequest::class, function (ShopEditionRequest $loaded) use ($request, $nth, $status, &$seen) {
            if ($loaded->id === $request->id && ++$seen === $nth) {
                DB::table('shop_edition_requests')->where('id', $request->id)->update(['status' => $status]);
            }
        });
    }

    private function stateOf(ShopEditionRequest $request): string
    {
        return DB::table('shop_edition_requests')->where('id', $request->id)->value('status');
    }

    public function test_an_approve_racing_the_owners_cancel_grants_nothing(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $request = $this->pendingAdd($shop, $owner);
        $this->decidedAfterLoad($request, 2, ShopEditionRequest::STATUS_CANCELLED); // 1 = binding, 2 = admin reload

        $this->asAdmin('approve', $request)->assertSessionHas('error');

        $this->assertSame(ShopEditionRequest::STATUS_CANCELLED, $this->stateOf($request));
        $this->assertFalse($shop->fresh()->hasEdition(ShopEdition::DHIRAN), 'no edition for a cancelled request');
    }

    public function test_a_deny_after_another_admin_approved_leaves_the_approval(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $request = $this->pendingAdd($shop, $owner);
        $this->decidedAfterLoad($request, 2, ShopEditionRequest::STATUS_APPROVED);

        $this->asAdmin('deny', $request, ['review_notes' => 'Not now'])->assertSessionHas('error');

        $this->assertSame(ShopEditionRequest::STATUS_APPROVED, $this->stateOf($request));
    }

    public function test_the_owners_cancel_after_an_approval_leaves_the_approval(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $request = $this->pendingAdd($shop, $owner);
        $this->decidedAfterLoad($request, 1, ShopEditionRequest::STATUS_APPROVED);

        TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)
            ->post(self::ERP . '/settings/services/requests/' . $request->id . '/cancel'))->assertSessionHas('error');

        $this->assertSame(ShopEditionRequest::STATUS_APPROVED, $this->stateOf($request));
    }

    public function test_a_pending_request_is_still_approved(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $request = $this->pendingAdd($shop, $owner);

        $this->asAdmin('approve', $request)->assertSessionHas('success');

        $this->assertSame(ShopEditionRequest::STATUS_APPROVED, $this->stateOf($request));
        $this->assertTrue($shop->fresh()->hasEdition(ShopEdition::DHIRAN));
    }

    /**
     * Found breaking N4: approving a Dhiran add (above) and the admin's direct "grant Dhiran" both switched the
     * shop's Dhiran settings on with a bulk update of PHP true, which PostgreSQL refuses for a boolean column: both
     * answered 500 and granted nothing.
     */
    public function test_the_admin_can_grant_dhiran_directly(): void
    {
        [, $shop] = $this->createRetailerTenant();

        $this->actingAs($this->admin(), 'platform_admin')
            ->withSession([EnsurePlatformAdminMfa::SESSION_PASSED => true])
            ->post(route('admin.shops.editions.grant', $shop), ['edition' => ShopEdition::DHIRAN, 'reason' => 'Pilot lender'])
            ->assertSessionHas('success');

        $this->assertTrue($shop->fresh()->hasEdition(ShopEdition::DHIRAN));
    }

    public function test_one_pending_request_per_shop_edition_and_action_is_a_database_rule(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $this->pendingAdd($shop, $owner);

        $this->expectException(UniqueConstraintViolationException::class);
        $this->pendingAdd($shop, $owner);
    }

    public function test_the_migration_keeps_the_oldest_pending_duplicate_and_closes_the_rest_with_a_note(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $migration = require base_path('database/migrations/2026_11_13_130000_one_pending_edition_request_per_shop_edition_action.php');
        $migration->down();
        $first = $this->pendingAdd($shop, $owner);
        $second = $this->pendingAdd($shop, $owner);
        $third = $this->pendingAdd($shop, $owner);
        $remove = TenantContext::runFor($shop->id, fn () => ShopEditionRequest::create([
            'shop_id' => $shop->id, 'user_id' => $owner->id, 'action' => ShopEditionRequest::ACTION_REMOVE,
            'edition' => ShopEdition::DHIRAN, 'reason' => 'Other ask', 'status' => ShopEditionRequest::STATUS_PENDING,
        ]));

        $migration->up();

        $this->assertSame(ShopEditionRequest::STATUS_PENDING, $this->stateOf($first));
        $this->assertSame(ShopEditionRequest::STATUS_PENDING, $this->stateOf($remove), 'a different ask is not a duplicate');
        foreach ([$second, $third] as $duplicate) {
            $this->assertSame(ShopEditionRequest::STATUS_CANCELLED, $this->stateOf($duplicate));
            $this->assertStringContainsString('#' . $first->id, DB::table('shop_edition_requests')->where('id', $duplicate->id)->value('review_notes'));
        }
        $this->expectException(UniqueConstraintViolationException::class);
        $this->pendingAdd($shop, $owner);
    }

    public function test_a_double_submitted_add_request_queues_one_request(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $inserted = false;
        DB::listen(function ($query) use ($owner, $shop, &$inserted) {
            if ($inserted || ! str_contains($query->sql, 'shop_edition_requests') || ! str_contains($query->sql, 'exists')) {
                return;
            }
            $inserted = true; // the other submit inserts right after this one looked
            $this->pendingAdd($shop, $owner);
        });

        TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)->post(self::ERP . '/settings/services/request-add', [
            'edition' => ShopEdition::DHIRAN, 'reason' => 'We lend against gold',
        ]))->assertSessionHas('error');

        $this->assertSame(1, DB::table('shop_edition_requests')->where('shop_id', $shop->id)->count());
    }
}
