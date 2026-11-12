<?php

namespace Tests\Feature\Dhiran;

use App\Http\Controllers\DhiranController;
use App\Models\Dhiran\DhiranSettings;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Shop;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Owner 2026-11-11: Dhiran Settings ran down the page as one narrow column with wide inputs. The sections are cards
 * two to a row (Interest beside Loan value (LTV), and so on), with their fields two to a row inside each card.
 */
class DhiranSettingsLayoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_settings_sections_sit_two_to_a_row_with_compact_fields(): void
    {
        $shop = Shop::create([
            'name' => 'Pawn Co', 'shop_type' => 'dhiran', 'phone' => '9390012000',
            'owner_first_name' => 'O', 'owner_last_name' => 'W', 'owner_mobile' => '9390012000',
            'country' => 'India', 'gst_rate' => 3.0, 'wastage_recovery_percent' => 0,
        ]);
        DhiranSettings::getForShop($shop->id)->update(['is_enabled' => true]);
        $role = new Role();
        $role->forceFill(['name' => 'owner', 'display_name' => 'Owner', 'shop_id' => $shop->id])->save();
        $role->permissions()->sync(Permission::query()->pluck('id'));
        $owner = User::create([
            'mobile_number' => '9390012000', 'password' => bcrypt('x'), 'realm' => 'dhiran',
            'is_active' => true, 'email_verified_at' => now(), 'shop_id' => $shop->id, 'role_id' => $role->id,
        ]);

        $html = TenantContext::runFor($shop->id, function () use ($owner) {
            $this->actingAs($owner);
            view()->share('errors', new \Illuminate\Support\ViewErrorBag);

            return app(DhiranController::class)->settings()->render();
        });

        $board = substr($html, strpos($html, 'data-settings-board'));
        preg_match_all('/<section class="dset-section"[^>]*>\s*<h2 class="dset-section-title">.*?<\/svg>\s*([^<]+)</s', $board, $m);
        $this->assertSame(
            // 2026-11-12: the Reminders card is gone until something actually sends reminders.
            ['Interest', 'Loan value (LTV)', 'Loan limits and fee', 'Tenure and forfeiture', 'Loan numbers and printouts'],
            array_map('trim', $m[1]),
            'cards in pairs: Interest | LTV, Limits | Tenure, then Printouts'
        );
        $this->assertSame(5, substr_count($board, 'class="dset-fields"'), 'each card lays its fields out two to a row');
        $this->assertStringNotContainsString('max-w-3xl', $html, 'no narrow single column');
        foreach (['default_interest_rate_monthly', 'default_ltv_ratio', 'high_value_threshold', 'processing_fee_value', 'grace_period_days', 'receipt_terms_text'] as $field) {
            $this->assertStringContainsString('name="' . $field . '"', $board, "still saves {$field}");
        }
    }
}
