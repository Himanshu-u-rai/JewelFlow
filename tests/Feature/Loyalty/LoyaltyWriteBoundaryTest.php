<?php

namespace Tests\Feature\Loyalty;

use App\Models\Customer;
use App\Models\LoyaltyTransaction;
use App\Services\LoyaltyService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * S3-16, the other loyalty writers. Expiry, reversal, earning and redemption
 * all change customers.loyalty_points and append to loyalty_transactions. Each
 * must validate the balance as it is now — under the customer's row lock, the
 * one expiry and reversal take — and commit the balance with its ledger row.
 *
 * Real-process evidence: tests/Concurrency/loyalty_redeem_expiry_race.php.
 */
class LoyaltyWriteBoundaryTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        Carbon::setTestNow('2026-10-15 09:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** A customer holding 110: a lot of 100 that fell due after activation, and 10 not yet due. */
    private function holder(): array
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $customer = $this->createCustomer((int) $shop->id);
        TenantContext::runFor((int) $shop->id, function () use ($customer) {
            $customer->fresh()->addLoyaltyPoints(100, null, 'due lot', Carbon::parse('2026-10-10'));
            $customer->fresh()->addLoyaltyPoints(10, null, 'future lot', Carbon::parse('2027-01-01'));
        });

        return [$owner, $shop, $customer];
    }

    private function balance(Customer $customer): int
    {
        return (int) DB::table('customers')->where('id', $customer->id)->value('loyalty_points');
    }

    private function rows(Customer $customer): int
    {
        return DB::table('loyalty_transactions')->where('customer_id', $customer->id)->count();
    }

    public function test_a_redemption_validates_the_balance_as_it_is_now_not_as_its_model_was_loaded(): void
    {
        [, $shop, $customer] = $this->holder();
        $stale = TenantContext::runFor((int) $shop->id, fn () => Customer::findOrFail($customer->id));
        $this->assertSame(110, (int) $stale->loyalty_points);

        // Expiry commits between the load and the redemption.
        config(['loyalty.expiry_active_from' => '2026-10-01']);
        $this->artisan('loyalty:expire')->assertExitCode(0);
        $this->assertSame(10, $this->balance($customer));

        $refused = null;
        try {
            TenantContext::runFor((int) $shop->id, fn () => app(LoyaltyService::class)->adjustPoints($stale, 80, 'redeem', 'redeem on a stale model'));
        } catch (\LogicException $e) {
            $refused = $e->getMessage();
        }

        $this->assertSame('Insufficient loyalty points', $refused);
        $this->assertSame(10, $this->balance($customer), 'nothing taken');
        $this->assertSame(0, DB::table('loyalty_transactions')->where('description', 'redeem on a stale model')->count());
    }

    public function test_a_failure_between_the_balance_and_its_ledger_row_changes_neither(): void
    {
        [, $shop, $customer] = $this->holder();
        $rows = $this->rows($customer);
        Event::listen('eloquent.creating: '.LoyaltyTransaction::class, fn () => throw new \RuntimeException('ledger write refused (injected)'));

        foreach ([['redeem', 30], ['earn', 25]] as [$type, $points]) {
            try {
                TenantContext::runFor((int) $shop->id, fn () => app(LoyaltyService::class)->adjustPoints($customer->fresh(), $points, $type, 'injected'));
                $this->fail("{$type}: the injected failure did not surface");
            } catch (\RuntimeException $e) {
                $this->assertSame('ledger write refused (injected)', $e->getMessage());
            }
            $this->assertSame(110, $this->balance($customer), "{$type}: the balance did not move without its ledger row");
        }
        $this->assertSame($rows, $this->rows($customer));
    }

    public function test_every_balance_after_is_the_balance_the_write_left(): void
    {
        [, $shop, $customer] = $this->holder();
        $stale = TenantContext::runFor((int) $shop->id, fn () => Customer::findOrFail($customer->id));
        TenantContext::runFor((int) $shop->id, fn () => app(LoyaltyService::class)->adjustPoints($customer->fresh(), 50, 'redeem', 'first'));
        // A second writer holding a model loaded before the first redemption.
        TenantContext::runFor((int) $shop->id, fn () => app(LoyaltyService::class)->adjustPoints($stale, 5, 'earn', 'second'));

        $this->assertSame(65, $this->balance($customer));
        $this->assertSame([100, 110, 60, 65], DB::table('loyalty_transactions')->where('customer_id', $customer->id)
            ->orderBy('id')->pluck('balance_after')->map(fn ($b) => (int) $b)->all());
    }

    public function test_redeeming_more_than_the_balance_through_the_route_is_a_validation_error_not_a_500(): void
    {
        [$owner, $shop, $customer] = $this->holder();
        $response = TenantContext::runFor((int) $shop->id, fn () => $this->actingAs($owner)
            ->from("/loyalty/{$customer->id}/adjust")
            ->post("/loyalty/{$customer->id}/adjust", ['type' => 'redeem', 'points' => 500, 'description' => 'too much']));

        $response->assertRedirect("/loyalty/{$customer->id}/adjust");
        $response->assertSessionHasErrors('points');
        $this->assertSame(110, $this->balance($customer));
    }
}
