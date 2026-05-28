<?php

namespace Tests\Feature\Material;

use App\Services\MetalRegistry;
use App\Services\ReferencePriceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * R5 — Reference Prices history report (Class B only timeline).
 *
 * Invariants guarded:
 *   - The screen reads ONLY from shop_metal_reference_prices.
 *   - It never joins to shop_daily_metal_rates or any class-A storage.
 *   - Empty state per metal is a normal display, not an error.
 *   - Multiple updates per metal show in append-only order (newest first).
 *   - Gold/silver/stone rows are structurally impossible to surface here.
 */
class ReferencePriceHistoryReportTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    private function grant(\App\Models\User $user, string ...$perms): void
    {
        $role = \App\Models\Role::withoutTenant()->findOrFail($user->role_id);
        foreach ($perms as $p) {
            $role->givePermission($p);
        }
    }

    public function test_report_renders_empty_state_for_pilot_shop(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $this->grant($user, 'reports.view');

        $response = $this->actingAs($user)->get(route('report.reference-prices'));
        $response->assertOk();
        $response->assertSee('Reference Prices', false);
        $response->assertSee('No reference noted for platinum yet', false);
        $response->assertSee('No reference noted for copper yet', false);
        // Gold and silver are NEVER on this screen as section headers.
        $response->assertDontSee('gold — reference price history', false);
        $response->assertDontSee('silver — reference price history', false);
    }

    public function test_report_lists_recorded_references_newest_first(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $this->grant($user, 'reports.view');

        $service = app(ReferencePriceService::class);
        // Three notes, in order. The latest one (3300) must appear first.
        $service->recordReference((int) $shop->id, 'platinum', 3200.00, (int) $user->id, 'two weeks ago');
        DB::table('shop_metal_reference_prices')
            ->where('shop_id', $shop->id)
            ->where('metal_type', 'platinum')
            ->update(['noted_at' => now()->subDays(14)]);

        $service->recordReference((int) $shop->id, 'platinum', 3250.00, (int) $user->id, 'last week');
        DB::table('shop_metal_reference_prices')
            ->where('shop_id', $shop->id)
            ->where('metal_type', 'platinum')
            ->where('reference_price', 3250.00)
            ->update(['noted_at' => now()->subDays(7)]);

        $service->recordReference((int) $shop->id, 'platinum', 3300.00, (int) $user->id, 'today');

        $response = $this->actingAs($user)->get(route('report.reference-prices'));
        $response->assertOk();
        $response->assertSee('3,300.00', false);
        $response->assertSee('3,250.00', false);
        $response->assertSee('3,200.00', false);
        $response->assertSee('today', false);

        // Newest-first ordering: 3,300.00 must appear before 3,250.00.
        $body = $response->getContent();
        $posLatest   = strpos($body, '3,300.00');
        $posMiddle   = strpos($body, '3,250.00');
        $posEarliest = strpos($body, '3,200.00');
        $this->assertNotFalse($posLatest);
        $this->assertNotFalse($posMiddle);
        $this->assertNotFalse($posEarliest);
        $this->assertLessThan($posMiddle, $posLatest, 'Latest reference should appear before older ones.');
        $this->assertLessThan($posEarliest, $posMiddle, 'Middle reference should appear before earliest.');
    }

    public function test_controller_does_not_touch_class_a_storage(): void
    {
        $source = file_get_contents(app_path('Http/Controllers/ReferencePriceHistoryController.php'));
        // The doc block is allowed to NAME the forbidden tokens as a
        // constitutional reminder. Strip comments before scanning so a future
        // executable mention is still caught.
        $code = preg_replace('!/\*.*?\*/!s', '', $source);
        $code = preg_replace('!//[^\n]*!s', '', (string) $code);

        $forbidden = [
            'ShopPricingService',
            'shop_daily_metal_rates',
            'shop_daily_metal_rate_entries',
            'MetalRate::',
            'resolvedRateForToday',
            'RepriceRetailerInventoryJob',
            'fineWeightMultiplier',
        ];
        foreach ($forbidden as $token) {
            $this->assertStringNotContainsString($token, (string) $code, "Reference history controller must not reference '{$token}' in executable code.");
        }
    }

    public function test_view_does_not_render_class_a_storage_columns(): void
    {
        $view = file_get_contents(resource_path('views/reports/reference-prices.blade.php'));
        // The amber banner is allowed to mention "Daily Rates" by name to point
        // operators at the right screen for gold/silver. What must not appear
        // is any class-A storage column or model reference.
        $this->assertStringNotContainsString('shop_daily_metal_rates', $view);
        $this->assertStringNotContainsString('rate_per_gram', $view);
        $this->assertStringNotContainsString('MetalRate::', $view);
        $this->assertStringNotContainsString('resolvedRateForToday', $view);
    }
}
