<?php

namespace Tests\Feature\JobOrders;

use App\Models\Item;
use App\Models\JobOrder;
use App\Models\Karigar;
use App\Models\Shop;
use App\Models\User;
use App\Services\JobOrderService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Audit M1 (2026-11-13): a piece sent to a karigar for repair or rework must leave the shop's stock while it is
 * there. The database, the returns control centre and both reconcile commands expect it to be "with_karigar", but
 * nothing ever put it there: a job could name any item (even a sold one, or one already at another karigar) and the
 * item stayed in stock, sellable at the counter. The job now owns its item: issued -> with the karigar; completed or
 * cancelled -> back where it came from (repair -> in stock, rework -> returned).
 *
 * The control centre's "fix status" for an item left behind by a cancelled job read the item without a lock and
 * took the latest CANCELLED job even when a newer job held the item; it now refuses while any job holds it.
 */
class JobOrderSourceItemTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
    }

    /** @return array{User, Shop, Karigar, Item} */
    private function shop(): array
    {
        [$user, $shop] = $this->createRetailerTenant();
        $karigar = new Karigar();
        $karigar->forceFill(['shop_id' => $shop->id, 'name' => 'Ramesh', 'is_active' => true])->save();
        $item = $this->createItem($shop->id, null, ['selling_price' => 10000, 'source' => 'purchase']);

        return [$user, $shop, $karigar, $item];
    }

    private function issue(User $user, Shop $shop, Karigar $karigar, ?Item $item, string $type = 'repair'): JobOrder
    {
        return TenantContext::runFor($shop->id, function () use ($user, $shop, $karigar, $item, $type) {
            $this->actingAs($user);

            return app(JobOrderService::class)->issue([
                'karigar_id' => $karigar->id, 'job_type' => $type, 'source_item_id' => $item?->id,
                'metal_type' => 'gold', 'purity' => 22, 'allowed_wastage_percent' => 0,
                'issue_date' => now()->toDateString(), 'metal_source' => 'none', 'sources' => [], // labour only
            ], (int) $shop->id, (int) $user->id);
        });
    }

    public function test_an_item_sent_for_repair_leaves_stock_and_cannot_be_sold(): void
    {
        [$user, $shop, $karigar, $item] = $this->shop();
        $this->issue($user, $shop, $karigar, $item);

        $this->assertSame('with_karigar', $item->fresh()->status);

        $this->seedRetailerPricing($shop, $user);
        $customer = $this->createCustomer($shop->id);
        $this->actingAs($user)->postJson('/pos/sell', [
            'customer_id' => $customer->id, 'item_ids' => [$item->id], 'discount' => 0, 'round_off' => 0,
            'payments' => [['mode' => 'cash', 'amount' => 10300]],
        ])->assertStatus(409);
        $this->assertSame('with_karigar', $item->fresh()->status);
    }

    public function test_an_item_cannot_be_at_two_karigars_or_sent_when_sold(): void
    {
        [$user, $shop, $karigar, $item] = $this->shop();
        $this->issue($user, $shop, $karigar, $item);

        try {
            $this->issue($user, $shop, $karigar, $item);
            $this->fail('The same item went out on a second job.');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('cannot be sent', $e->getMessage());
        }

        $sold = $this->createItem($shop->id, null, ['status' => 'sold']);
        $this->expectException(\LogicException::class);
        $this->issue($user, $shop, $karigar, $sold);
    }

    public function test_only_repair_and_rework_jobs_take_an_item(): void
    {
        [$user, $shop, $karigar, $item] = $this->shop();
        $this->expectException(\LogicException::class);
        $this->issue($user, $shop, $karigar, $item, 'manufacture');
    }

    public function test_cancelling_or_completing_the_job_brings_the_item_back(): void
    {
        [$user, $shop, $karigar, $item] = $this->shop();
        $job = $this->issue($user, $shop, $karigar, $item);
        TenantContext::runFor($shop->id, fn () => app(JobOrderService::class)->cancel($job, (int) $user->id));
        $this->assertSame('in_stock', $item->fresh()->status, 'repair job cancelled: back in stock');

        DB::table('items')->where('id', $item->id)->update(['status' => 'returned']);
        $job = $this->issue($user, $shop, $karigar, $item->fresh(), 'rework');
        $this->assertSame('with_karigar', $item->fresh()->status);
        TenantContext::runFor($shop->id, fn () => app(JobOrderService::class)->acknowledgeAndComplete($job));
        $this->assertSame('returned', $item->fresh()->status, 'rework done: back with the returns');
    }

    public function test_fix_status_refuses_an_item_a_newer_job_still_holds(): void
    {
        [$user, $shop, $karigar, $item] = $this->shop();
        // Legacy state: an older cancelled job left the item at the karigar, and a newer job holds it now.
        $old = $this->issue($user, $shop, $karigar, $item);
        TenantContext::runFor($shop->id, fn () => app(JobOrderService::class)->cancel($old, (int) $user->id));
        $this->issue($user, $shop, $karigar, $item->fresh());
        $this->assertSame('with_karigar', $item->fresh()->status);

        TenantContext::runFor($shop->id, fn () => $this->actingAs($user)
            ->post(route('returns.items.fix-orphan-status', $item)))
            ->assertStatus(422);
        $this->assertSame('with_karigar', $item->fresh()->status, 'the piece is still at the karigar');
    }

    public function test_fix_status_still_frees_an_item_a_cancelled_job_left_behind(): void
    {
        [$user, $shop, $karigar, $item] = $this->shop();
        $job = $this->issue($user, $shop, $karigar, $item);
        TenantContext::runFor($shop->id, fn () => app(JobOrderService::class)->cancel($job, (int) $user->id));
        DB::table('items')->where('id', $item->id)->update(['status' => 'with_karigar']); // left behind (legacy)

        TenantContext::runFor($shop->id, fn () => $this->actingAs($user)
            ->post(route('returns.items.fix-orphan-status', $item)))
            ->assertRedirect();
        $this->assertSame('in_stock', $item->fresh()->status);
    }
}
