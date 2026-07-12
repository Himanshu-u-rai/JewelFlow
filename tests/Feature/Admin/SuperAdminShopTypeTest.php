<?php

namespace Tests\Feature\Admin;

use App\Http\Middleware\EnsurePlatformAdminMfa;
use App\Models\Platform\PlatformAdmin;
use App\Models\Shop;
use App\Support\ShopEdition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Guards the Super Admin shop-type display + Type filter.
 *
 * The shops list used to render the label with a two-way ternary
 * ($shop->shop_type === 'retailer' ? 'Retail' : 'Manufacturer'), which
 * mislabeled a stored 'dhiran' shop as "Manufacturer", and the Type filter
 * only offered Retail/Manufacturer. The label now comes from the central,
 * exhaustive ShopEdition::label() so an unknown type can NEVER silently show
 * as "Manufacturer", and Dhiran is filterable.
 */
class SuperAdminShopTypeTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        $this->withoutMiddleware([
            \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
            \Illuminate\Routing\Middleware\ThrottleRequests::class,
        ]);
    }

    private function actingAsSuperAdmin(): self
    {
        $admin = PlatformAdmin::create([
            'first_name' => 'ShopType', 'last_name' => 'Test', 'name' => 'ShopType Test',
            'email' => 'shoptype' . random_int(1000, 999999) . '@example.com',
            'mobile_number' => '9' . random_int(100000000, 999999999),
            'password' => Hash::make('password'),
            'role' => 'super_admin', 'is_active' => true,
            'email_verified_at' => now(),
        ]);

        return $this->actingAs($admin, 'platform_admin')
            ->withSession([EnsurePlatformAdminMfa::SESSION_PASSED => true]);
    }

    private function makeShop(string $type, string $name): Shop
    {
        return Shop::create([
            'name' => $name,
            'shop_type' => $type,
            'phone' => '9' . random_int(100000000, 999999999),
            'owner_first_name' => 'Owner', 'owner_last_name' => 'Test',
            'owner_mobile' => '9' . random_int(100000000, 999999999),
            'gst_rate' => 3.00, 'wastage_recovery_percent' => 100.00,
            'access_mode' => 'active', 'is_active' => true,
        ]);
    }

    public function test_dhiran_shop_renders_dhiran_label(): void
    {
        $this->makeShop('dhiran', 'Dhiran Shop A');

        $res = $this->actingAsSuperAdmin()->get(route('admin.shops.index'));

        $res->assertOk();
        $res->assertSee('<td class="px-4 py-3">Dhiran</td>', false);
    }

    public function test_retailer_shop_renders_retail_label(): void
    {
        $this->makeShop('retailer', 'Retail Shop A');

        $res = $this->actingAsSuperAdmin()->get(route('admin.shops.index'));

        $res->assertOk();
        $res->assertSee('<td class="px-4 py-3">Retail</td>', false);
    }

    public function test_manufacturer_shop_renders_manufacturer_label(): void
    {
        $this->makeShop('manufacturer', 'Manufacturer Shop A');

        $res = $this->actingAsSuperAdmin()->get(route('admin.shops.index'));

        $res->assertOk();
        $res->assertSee('<td class="px-4 py-3">Manufacturer</td>', false);
    }

    public function test_dhiran_filter_returns_dhiran_shops(): void
    {
        $this->makeShop('dhiran', 'Dhiran Filtered Shop');
        $this->makeShop('manufacturer', 'Manufacturer Other Shop');

        $res = $this->actingAsSuperAdmin()->get(route('admin.shops.index', ['type' => 'dhiran']));

        $res->assertOk();
        $res->assertSee('Dhiran Filtered Shop');
        $res->assertDontSee('Manufacturer Other Shop');
    }

    public function test_manufacturer_filter_excludes_dhiran_shops(): void
    {
        $this->makeShop('dhiran', 'Dhiran Excluded Shop');
        $this->makeShop('manufacturer', 'Manufacturer Included Shop');

        $res = $this->actingAsSuperAdmin()->get(route('admin.shops.index', ['type' => 'manufacturer']));

        $res->assertOk();
        $res->assertSee('Manufacturer Included Shop');
        $res->assertDontSee('Dhiran Excluded Shop');
    }

    /**
     * The exhaustive safe fallback: an unrecognised shop_type must render as
     * itself, never collapse to "Manufacturer" (the old ternary's default).
     */
    public function test_unknown_shop_type_is_not_labelled_manufacturer(): void
    {
        $this->assertSame('Wholesaler', ShopEdition::label('wholesaler'));
        $this->assertSame('Unknown', ShopEdition::label(''));
        $this->assertSame('Unknown', ShopEdition::label(null));

        foreach (['wholesaler', '', null, 'some_future_type'] as $unknown) {
            $this->assertNotSame('Manufacturer', ShopEdition::label($unknown));
        }

        // Known types still map exactly.
        $this->assertSame('Retail', ShopEdition::label('retailer'));
        $this->assertSame('Manufacturer', ShopEdition::label('manufacturer'));
        $this->assertSame('Dhiran', ShopEdition::label('dhiran'));
    }

    /**
     * The presentation fix must not touch entitlements: a Dhiran shop's edition
     * assignment (its capability source of truth) is unchanged by rendering the
     * admin list.
     */
    public function test_tenant_editions_unchanged_by_shop_listing(): void
    {
        $shop = $this->makeShop('dhiran', 'Dhiran Editions Shop');
        $before = $shop->fresh()->editionList();

        $res = $this->actingAsSuperAdmin()->get(route('admin.shops.index'));
        $res->assertOk();

        $this->assertSame(['dhiran'], $before);
        $this->assertSame($before, $shop->fresh()->editionList());
        $this->assertTrue($shop->fresh()->hasDhiran());
    }
}
