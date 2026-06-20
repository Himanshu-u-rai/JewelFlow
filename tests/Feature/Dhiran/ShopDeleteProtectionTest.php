<?php

namespace Tests\Feature\Dhiran;

use App\Models\Customer;
use App\Models\Dhiran\DhiranLoan;
use App\Models\Dhiran\DhiranPayment;
use App\Models\Dhiran\DhiranSettings;
use App\Models\Shop;
use App\Models\User;
use App\Services\DhiranService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Shop-delete protection for Dhiran financial history (Phase 5, Part A).
 *
 * Two layers: an app-level Shop::deleting guard (friendly error) and DB-level
 * ON DELETE RESTRICT FKs on the five financial tables. A shop holding pawn/loan
 * records must not be hard-deletable; deactivation stays available; deleting a
 * user must not touch financial history (actor FKs are SET NULL).
 */
class ShopDeleteProtectionTest extends TestCase
{
    use RefreshDatabase;

    private function makeDhiranShopWithLoan(): array
    {
        $shop = Shop::create([
            'name' => 'Pawn Co', 'shop_type' => 'dhiran', 'phone' => '9990000000',
            'owner_first_name' => 'O', 'owner_last_name' => 'W', 'owner_mobile' => '9990000000',
            'country' => 'India', 'gst_rate' => 3.0, 'wastage_recovery_percent' => 0,
        ]);
        DhiranSettings::getForShop($shop->id)->update(['is_enabled' => true]);

        $loan = TenantContext::runFor($shop->id, function () use ($shop) {
            $c = Customer::create(['shop_id' => $shop->id, 'first_name' => 'C', 'last_name' => 'O', 'mobile' => '9811111120']);
            return app(DhiranService::class)->createLoan($shop, $c, [[
                'description' => 'x', 'metal_type' => 'gold', 'purity' => 24,
                'gross_weight' => 100, 'rate_per_gram_at_pledge' => 6000,
            ]], ['principal_amount' => 100000, 'gold_rate_on_date' => 6000, 'created_by' => null]);
        });

        return [$shop, $loan];
    }

    public function test_shop_with_dhiran_loan_cannot_be_hard_deleted(): void
    {
        [$shop] = $this->makeDhiranShopWithLoan();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('cannot be deleted');
        $shop->delete();
    }

    public function test_shop_with_dhiran_loan_survives_delete_attempt(): void
    {
        [$shop, $loan] = $this->makeDhiranShopWithLoan();

        try {
            $shop->delete();
        } catch (\RuntimeException $e) {
            // expected
        }

        // Shop + loan still present (app guard fired before any DB write).
        $this->assertNotNull(Shop::find($shop->id));
        $this->assertNotNull(DhiranLoan::withoutGlobalScope('shop')->find($loan->id));
    }

    public function test_shop_with_payments_cannot_be_hard_deleted(): void
    {
        [$shop, $loan] = $this->makeDhiranShopWithLoan();
        TenantContext::runFor($shop->id, fn () => app(DhiranService::class)
            ->recordPayment(DhiranLoan::findOrFail($loan->id), 5000));

        $this->assertTrue(DhiranPayment::withoutGlobalScope('shop')->where('shop_id', $shop->id)->exists());

        $this->expectException(\RuntimeException::class);
        $shop->delete();
    }

    public function test_db_restrict_blocks_raw_shop_delete(): void
    {
        // Last line of defence: even a raw DELETE (bypassing the model hook) is
        // blocked by the ON DELETE RESTRICT FK on dhiran_loans.shop_id.
        [$shop] = $this->makeDhiranShopWithLoan();

        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('shops')->where('id', $shop->id)->delete();
    }

    public function test_user_deletion_preserves_dhiran_financial_history(): void
    {
        $shop = Shop::create([
            'name' => 'Pawn Co', 'shop_type' => 'dhiran', 'phone' => '9990000001',
            'owner_first_name' => 'O', 'owner_last_name' => 'W', 'owner_mobile' => '9990000001',
            'country' => 'India', 'gst_rate' => 3.0, 'wastage_recovery_percent' => 0,
        ]);
        DhiranSettings::getForShop($shop->id)->update(['is_enabled' => true]);
        $actor = User::create(['mobile_number' => '9393100001', 'password' => bcrypt('x'),
            'realm' => 'dhiran', 'is_active' => true, 'shop_id' => $shop->id]);

        $loan = TenantContext::runFor($shop->id, function () use ($shop, $actor) {
            $c = Customer::create(['shop_id' => $shop->id, 'first_name' => 'C', 'last_name' => 'O', 'mobile' => '9811111121']);
            return app(DhiranService::class)->createLoan($shop, $c, [[
                'description' => 'x', 'metal_type' => 'gold', 'purity' => 24,
                'gross_weight' => 100, 'rate_per_gram_at_pledge' => 6000,
            ]], ['principal_amount' => 100000, 'gold_rate_on_date' => 6000, 'created_by' => $actor->id]);
        });

        $actor->delete(); // delete the actor user

        // Financial history survives; actor FK is SET NULL, not cascaded.
        $survivor = DhiranLoan::withoutGlobalScope('shop')->find($loan->id);
        $this->assertNotNull($survivor);
        $this->assertNull($survivor->created_by, 'Actor FK should be SET NULL on user delete.');
    }

    public function test_shop_deactivation_still_works(): void
    {
        [$shop] = $this->makeDhiranShopWithLoan();

        // Deactivation is the supported "retire" path — must still work with data.
        $shop->forceFill(['access_mode' => 'suspended', 'is_active' => false])->save();

        $shop->refresh();
        $this->assertSame('suspended', $shop->access_mode);
        $this->assertFalse((bool) $shop->is_active);
        // And the financial history is untouched.
        $this->assertTrue(DhiranLoan::withoutGlobalScope('shop')->where('shop_id', $shop->id)->exists());
    }

    public function test_shop_without_dhiran_data_can_be_deleted(): void
    {
        // A plain shop with no Dhiran financial rows is not blocked by the guard.
        $shop = Shop::create([
            'name' => 'Plain', 'shop_type' => 'retailer', 'phone' => '9990000009',
            'owner_first_name' => 'O', 'owner_last_name' => 'W', 'owner_mobile' => '9990000009',
            'country' => 'India', 'gst_rate' => 3.0, 'wastage_recovery_percent' => 0,
        ]);
        $id = $shop->id;

        $shop->delete();
        $this->assertNull(Shop::find($id));
    }
}
