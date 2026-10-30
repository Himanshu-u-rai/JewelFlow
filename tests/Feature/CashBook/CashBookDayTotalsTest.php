<?php

namespace Tests\Feature\CashBook;

use App\Models\CashTransaction;
use App\Support\TenantContext;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * "What came in today, what went out yesterday": the cash book gives today's
 * money in / out (all payment kinds, as the web's Today strip) and a total for
 * every day shown in the list, worked out by the server so a day split across
 * pages still adds up. Day totals follow the list's filters.
 */
class CashBookDayTotalsTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    private const ERP = 'https://jewelflows.com';

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        $this->withoutMiddleware([
            \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
            \Illuminate\Routing\Middleware\ThrottleRequests::class,
        ]);
    }

    private function row(int $shopId, string $type, float $amount, string $mode, string $at): void
    {
        TenantContext::runFor($shopId, function () use ($shopId, $type, $amount, $mode, $at) {
            $row = new CashTransaction();
            $row->forceFill([
                'shop_id' => $shopId, 'type' => $type, 'amount' => $amount, 'source_type' => 'manual',
                'payment_mode' => $mode, 'description' => "{$type} {$amount}", 'created_at' => $at, 'updated_at' => $at,
            ])->save();
        });
    }

    private function seedDays(int $shopId): void
    {
        $today = Carbon::today();
        $yesterday = Carbon::yesterday();
        $this->row($shopId, 'in', 12000, 'cash', $today->copy()->setTime(11, 0)->toDateTimeString());
        $this->row($shopId, 'in', 700, 'upi', $today->copy()->setTime(12, 0)->toDateTimeString());
        $this->row($shopId, 'out', 500, 'cash', $today->copy()->setTime(13, 0)->toDateTimeString());
        $this->row($shopId, 'in', 4000, 'cash', $yesterday->copy()->setTime(10, 0)->toDateTimeString());
        $this->row($shopId, 'out', 3000, 'cash', $yesterday->copy()->setTime(18, 0)->toDateTimeString());
    }

    private function owner(): array
    {
        [$user, $shop] = $this->createRetailerTenant();
        $role = \App\Models\Role::withoutTenant()->findOrFail($user->role_id);
        $role->givePermission('cash.view');
        $role->givePermission('cash.create');

        return [$user->fresh(), $shop];
    }

    public function test_the_app_gets_todays_money_and_a_total_for_each_day(): void
    {
        [$user, $shop] = $this->owner();
        $this->seedDays($shop->id);
        Sanctum::actingAs($user);

        // Two rows a page: today spills onto page two, its total does not change.
        $data = $this->getJson('/api/mobile/v1/cashbook?per_page=2')->assertOk()->json('data');

        $this->assertEqualsWithDelta(12700, $data['today']['money_in'], 0.01);
        $this->assertEqualsWithDelta(500, $data['today']['money_out'], 0.01);
        // The web strip's fourth figure, "This month net" (same query as the web).
        $monthNet = Carbon::yesterday()->isSameMonth(Carbon::today()) ? 12700 - 500 + 4000 - 3000 : 12700 - 500;
        $this->assertEqualsWithDelta($monthNet, $data['month']['money_in'] - $data['month']['money_out'], 0.01);
        $day = collect($data['day_totals'])->keyBy('date');
        $this->assertEqualsWithDelta(12700, $day[Carbon::today()->toDateString()]['money_in'], 0.01);
        $this->assertEqualsWithDelta(500, $day[Carbon::today()->toDateString()]['money_out'], 0.01);

        $page2 = $this->getJson('/api/mobile/v1/cashbook?per_page=2&cursor=' . $data['pagination']['next_cursor'])->json('data');
        $day = collect($page2['day_totals'])->keyBy('date');
        $this->assertEqualsWithDelta(12700, $day[Carbon::today()->toDateString()]['money_in'], 0.01);
        $this->assertEqualsWithDelta(4000, $day[Carbon::yesterday()->toDateString()]['money_in'], 0.01);
        $this->assertEqualsWithDelta(3000, $day[Carbon::yesterday()->toDateString()]['money_out'], 0.01);
    }

    public function test_day_totals_follow_the_filters(): void
    {
        [$user, $shop] = $this->owner();
        $this->seedDays($shop->id);
        Sanctum::actingAs($user);

        $day = collect($this->getJson('/api/mobile/v1/cashbook?payment_mode=upi')->json('data.day_totals'))->keyBy('date');

        $this->assertEqualsWithDelta(700, $day[Carbon::today()->toDateString()]['money_in'], 0.01);
        $this->assertEqualsWithDelta(0, $day[Carbon::today()->toDateString()]['money_out'], 0.01);
        $this->assertArrayNotHasKey(Carbon::yesterday()->toDateString(), $day->all());
    }

    public function test_the_web_list_names_reasons_as_the_entry_form_and_the_app_do(): void
    {
        [$user, $shop] = $this->owner();
        TenantContext::runFor($shop->id, function () use ($shop) {
            $row = new CashTransaction();
            $row->forceFill(['shop_id' => $shop->id, 'type' => 'out', 'amount' => 500, 'source_type' => 'petty_expense', 'payment_mode' => 'cash'])->save();
            $row = new CashTransaction();
            $row->forceFill(['shop_id' => $shop->id, 'type' => 'in', 'amount' => 900, 'source_type' => 'repair_delivery', 'payment_mode' => 'cash'])->save();
        });

        TenantContext::runFor($shop->id, fn () => $this->actingAs($user)->get(self::ERP . '/cashbook'))
            ->assertOk()
            ->assertSee('Petty / daily expense')   // a form reason: its form name
            ->assertSee('Repair delivery')         // anything else: the code in words
            ->assertDontSee('Petty expense');
    }

    public function test_the_web_list_shows_each_days_totals(): void
    {
        [$user, $shop] = $this->owner();
        $this->seedDays($shop->id);

        TenantContext::runFor($shop->id, fn () => $this->actingAs($user)->get(self::ERP . '/cashbook'))
            ->assertOk()
            ->assertSee('In ₹12,700.00', false)->assertSee('Out ₹500.00', false)
            ->assertSee('In ₹4,000.00', false)->assertSee('Out ₹3,000.00', false);
    }
}
