<?php

namespace Tests\Feature\Mobile;

use App\Models\Vendor;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * The app's suppliers = the web's vendors pages (web leads): the list opens on
 * Active with Active / Archived / All counts and the Total / Active / GST
 * figures; a supplier shows its in-stock items (latest 10); Archive and
 * Reactivate replace Delete, through the web's own archive action, behind
 * vendors.manage.
 */
class SupplierParityTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    private function vendor(int $shopId, string $name, array $extra = []): Vendor
    {
        return TenantContext::runFor($shopId, function () use ($shopId, $name, $extra) {
            $v = new Vendor();
            $v->forceFill($extra + ['shop_id' => $shopId, 'name' => $name, 'is_active' => true])->save();

            return $v;
        });
    }

    private function shop(): array
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $a = $this->vendor($shop->id, 'Kundan Traders', ['gst_number' => '08ABCDE1234F1Z5', 'mobile' => '9811111111']);
        $b = $this->vendor($shop->id, 'Silver House');
        $gone = $this->vendor($shop->id, 'Old Supplier', ['is_active' => false]);
        Sanctum::actingAs($owner);
        TenantContext::set($shop->id);

        return [$owner, $shop, $a, $b, $gone];
    }

    public function test_the_list_opens_on_active_with_the_webs_counts_and_figures(): void
    {
        $this->shop();

        $res = $this->getJson('/api/mobile/vendors')->assertOk();
        $this->assertEqualsCanonicalizing(['Kundan Traders', 'Silver House'], array_column($res->json('data'), 'name'));
        $this->assertSame(['active' => 2, 'archived' => 1, 'all' => 3], $res->json('counts'));
        $this->assertSame(['total' => 3, 'active' => 2, 'gst' => 1], $res->json('stats'));

        $archived = $this->getJson('/api/mobile/vendors?status=archived')->assertOk();
        $this->assertSame(['Old Supplier'], array_column($archived->json('data'), 'name'));
        // The counts follow the search, as the web's status tabs do.
        $this->assertSame(['active' => 1, 'archived' => 0, 'all' => 1], $this->getJson('/api/mobile/vendors?search=kundan')->json('counts'));
    }

    public function test_a_supplier_shows_its_latest_in_stock_items(): void
    {
        [, $shop, $a] = $this->shop();
        foreach (range(1, 12) as $n) {
            $this->createItem($shop->id, null, ['vendor_id' => $a->id, 'barcode' => "VEN-{$n}", 'source' => 'purchase', 'selling_price' => 1000 + $n]);
        }
        $this->createItem($shop->id, null, ['vendor_id' => $a->id, 'barcode' => 'VEN-SOLD', 'status' => 'sold', 'source' => 'purchase']);
        TenantContext::set($shop->id);

        $res = $this->getJson("/api/mobile/vendors/{$a->id}")->assertOk();
        $this->assertSame(12, $res->json('items_count'));
        $this->assertCount(10, $res->json('items'));
        $this->assertNotContains('VEN-SOLD', array_column($res->json('items'), 'barcode'));
        $this->assertArrayHasKey('gross_weight', $res->json('items.0'));
        $this->assertArrayHasKey('huid', $res->json('items.0'));
        $this->assertArrayHasKey('selling_price', $res->json('items.0'));
    }

    public function test_archive_and_reactivate_as_on_the_web(): void
    {
        [, $shop, $a] = $this->shop();

        $this->patchJson("/api/mobile/vendors/{$a->id}/archive")->assertOk()->assertJsonPath('is_active', false);
        TenantContext::set($shop->id);
        $this->patchJson("/api/mobile/vendors/{$a->id}/archive")->assertStatus(409); // already archived
        TenantContext::set($shop->id);
        $this->patchJson("/api/mobile/vendors/{$a->id}/reactivate")->assertOk()->assertJsonPath('is_active', true);
        $this->assertSame(2, \App\Models\AuditLog::withoutGlobalScopes()->whereIn('action', ['vendor_archived', 'vendor_reactivated'])->count());
    }

    public function test_archive_needs_manage_suppliers(): void
    {
        [, $shop, $a] = $this->shop();
        $viewer = TenantContext::runFor($shop->id, function () use ($shop) {
            $role = (new \App\Models\Role())->forceFill(['name' => 'viewer', 'display_name' => 'Viewer', 'shop_id' => $shop->id]);
            $role->save();
            $role->permissions()->sync(\App\Models\Permission::whereIn('name', ['vendors.view'])->pluck('id'));

            return \App\Models\User::factory()->create(['shop_id' => $shop->id, 'role_id' => $role->id, 'is_active' => true]);
        });
        Sanctum::actingAs($viewer);
        TenantContext::set($shop->id);

        $this->patchJson("/api/mobile/vendors/{$a->id}/archive")->assertForbidden();
        $this->assertTrue((bool) $a->fresh()->is_active);
    }
}
