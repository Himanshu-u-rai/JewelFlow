<?php

namespace Tests\Feature\CashBook;

use App\Models\CashTransaction;
use App\Reporting\LedgerService;
use App\Reporting\ReportPeriod;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Phase 2: per-mode "Money on Hand" computed from immutable cash_transactions
 * via LedgerService::cashFlowByMode. Verifies opening + in − out = closing per
 * mode, split-tender visibility, old-gold exclusion, date windowing, and the
 * NULL→cash defensive fallback.
 */
class PerModeBalanceTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
    }

    private function ledger(): LedgerService
    {
        return app(LedgerService::class);
    }

    /** A direct cash_transactions record — bypasses sale flow for deterministic math. */
    private function record(int $shopId, int $userId, string $type, float $amount, ?string $mode, string $when): void
    {
        CashTransaction::record([
            'shop_id'      => $shopId,
            'user_id'      => $userId,
            'type'         => $type,
            'amount'       => $amount,
            'source_type'  => 'manual',
            'source_id'    => null,
            'payment_mode' => $mode,
            'description'  => 'test',
            'created_at'   => $when,
            'updated_at'   => $when,
        ]);
    }

    /** Per mode: opening + in − out = closing, and totals sum the modes. */
    public function test_per_mode_opening_in_out_closing_identity(): void
    {
        [$user, $shop] = $this->createManufacturerTenant();
        TenantContext::set($shop->id);

        $lastMonth = now()->subMonthNoOverflow()->day(15)->toDateTimeString();
        $thisMonth = now()->startOfMonth()->addDays(3)->toDateTimeString();

        // Opening (before this month): cash +1000, upi +500
        $this->record($shop->id, $user->id, 'in', 1000, 'cash', $lastMonth);
        $this->record($shop->id, $user->id, 'in', 500, 'upi', $lastMonth);
        // Within this month: cash +300 / -100, upi +200, bank +700
        $this->record($shop->id, $user->id, 'in', 300, 'cash', $thisMonth);
        $this->record($shop->id, $user->id, 'out', 100, 'cash', $thisMonth);
        $this->record($shop->id, $user->id, 'in', 200, 'upi', $thisMonth);
        $this->record($shop->id, $user->id, 'in', 700, 'bank', $thisMonth);

        $data = $this->ledger()->cashFlowByMode($shop->id, ReportPeriod::range(null, null)); // current month

        $byMode = $data->modes->keyBy('mode');

        // Cash: opening 1000, in 300, out 100, closing 1200
        $this->assertEqualsWithDelta(1000, $byMode['cash']->opening, 0.01);
        $this->assertEqualsWithDelta(300, $byMode['cash']->moneyIn, 0.01);
        $this->assertEqualsWithDelta(100, $byMode['cash']->moneyOut, 0.01);
        $this->assertEqualsWithDelta(1200, $byMode['cash']->closing, 0.01);

        // UPI: opening 500, in 200, closing 700
        $this->assertEqualsWithDelta(700, $byMode['upi']->closing, 0.01);
        // Bank: opening 0, in 700, closing 700
        $this->assertEqualsWithDelta(700, $byMode['bank']->closing, 0.01);

        // Each mode satisfies the identity.
        foreach ($data->modes as $m) {
            $this->assertEqualsWithDelta($m->opening + $m->moneyIn - $m->moneyOut, $m->closing, 0.01);
        }
        // Totals sum the modes.
        $this->assertEqualsWithDelta(1200 + 700 + 700, $data->totalClosing, 0.01);
    }

    /** NULL payment_mode is treated as cash defensively (no crash, folds into cash). */
    public function test_null_mode_folds_into_cash(): void
    {
        [$user, $shop] = $this->createManufacturerTenant();
        TenantContext::set($shop->id);

        $when = now()->startOfMonth()->addDay()->toDateTimeString();
        $this->record($shop->id, $user->id, 'in', 400, 'cash', $when);
        $this->record($shop->id, $user->id, 'in', 600, null, $when); // legacy NULL

        $data = $this->ledger()->cashFlowByMode($shop->id, ReportPeriod::range(null, null));
        $byMode = $data->modes->keyBy('mode');

        $this->assertArrayHasKey('cash', $byMode->all());
        $this->assertArrayNotHasKey('', $byMode->all(), 'NULL/empty mode must not appear as its own bucket');
        $this->assertEqualsWithDelta(1000, $byMode['cash']->closing, 0.01, 'NULL folds into cash');
    }

    /** Date window: a transaction outside the window does not affect in/out (only opening). */
    public function test_date_window_affects_balances(): void
    {
        [$user, $shop] = $this->createManufacturerTenant();
        TenantContext::set($shop->id);

        $this->record($shop->id, $user->id, 'in', 1000, 'cash', now()->subMonthNoOverflow()->day(10)->toDateTimeString());
        $this->record($shop->id, $user->id, 'in', 250, 'cash', now()->startOfMonth()->addDays(2)->toDateTimeString());

        $data = $this->ledger()->cashFlowByMode($shop->id, ReportPeriod::range(null, null));
        $cash = $data->modes->firstWhere('mode', 'cash');

        $this->assertEqualsWithDelta(1000, $cash->opening, 0.01, 'prior month is opening, not in');
        $this->assertEqualsWithDelta(250, $cash->moneyIn, 0.01, 'only this-month in counts');
        $this->assertEqualsWithDelta(1250, $cash->closing, 0.01);
    }

    /** A split-tender sale shows up split across cash + upi; old gold never appears. */
    public function test_split_tender_and_old_gold_in_per_mode(): void
    {
        [$user, $shop] = $this->createManufacturerTenant();
        $lot = $this->createMetalLot($shop->id);
        $customer = $this->createCustomer($shop->id);
        $item = $this->createItem($shop->id, $lot->id);

        $preview = $this->actingAs($user)->postJson('/api/price-preview', [
            'item_id' => $item->id, 'customer_id' => $customer->id,
            'gold_rate' => 6000, 'making' => 500, 'stone' => 200, 'discount' => 0, 'round_off' => 0,
        ]);
        $total = (float) $preview->json('total');
        $cash = round($total * 0.5, 2);
        $upi  = round($total - $cash, 2);

        $this->actingAs($user)->postJson('/pos/sell', [
            'customer_id' => $customer->id, 'item_id' => $item->id,
            'gold_rate' => 6000, 'making' => 500, 'stone' => 200, 'discount' => 0, 'round_off' => 0,
            'payments' => [
                ['mode' => 'cash', 'amount' => $cash],
                ['mode' => 'upi',  'amount' => $upi],
            ],
        ])->assertOk();

        TenantContext::set($shop->id);
        $data = $this->ledger()->cashFlowByMode($shop->id, ReportPeriod::range(null, null));
        $byMode = $data->modes->keyBy('mode');

        $this->assertEqualsWithDelta($cash, $byMode['cash']->moneyIn, 0.01);
        $this->assertEqualsWithDelta($upi, $byMode['upi']->moneyIn, 0.01);
        // Old metal modes must never appear as money.
        $this->assertArrayNotHasKey('old_gold', $byMode->all());
        $this->assertArrayNotHasKey('old_silver', $byMode->all());
    }
}
