<?php

namespace Tests\Feature\Mobile;

use App\Models\Repair;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Editing a repair on the app = the web's edit form (web leads): besides the
 * details it already took, the app may change the status (received / in repair /
 * ready, either way), move the repair to another customer (not an archived one),
 * and replace or remove the photo — with the same rules and the same
 * repairs.edit permission as the web.
 */
class RepairEditParityTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    // A 1×1 PNG.
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        Storage::fake('local');
        Storage::fake('public');
    }

    private function repair(): array
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $customer = $this->createCustomer($shop->id);
        $repair = TenantContext::runFor($shop->id, fn () => Repair::create([
            'shop_id' => $shop->id, 'customer_id' => $customer->id, 'item_description' => 'Gold chain',
            'description' => 'Clasp', 'gross_weight' => 8.5, 'purity' => 22, 'estimated_cost' => 450, 'status' => 'ready',
        ]));
        Sanctum::actingAs($owner);

        return [$owner, $shop, $customer, $repair];
    }

    private function edit(Repair $repair, array $body)
    {
        TenantContext::set((int) $repair->shop_id);

        return $this->putJson("/api/mobile/repairs/{$repair->id}", $body);
    }

    public function test_the_app_can_set_the_status_either_way_as_the_web_edit(): void
    {
        [, , , $repair] = $this->repair();

        $this->edit($repair, ['status' => 'in_repair'])->assertOk()->assertJsonPath('repair.status', 'in_repair'); // back from ready
        $this->edit($repair, ['status' => 'delivered'])->assertStatus(422); // delivery has its own flow
    }

    public function test_the_app_can_move_a_repair_to_another_customer_but_not_an_archived_one(): void
    {
        [, $shop, , $repair] = $this->repair();
        $other = $this->createCustomer($shop->id, ['first_name' => 'Other']);
        $archived = $this->createCustomer($shop->id, ['first_name' => 'Gone']);
        TenantContext::runFor($shop->id, fn () => $archived->forceFill(['is_active' => false])->save());
        [, $otherShop] = $this->createRetailerTenant();
        $foreign = $this->createCustomer($otherShop->id);

        $this->edit($repair, ['customer_id' => $archived->id])->assertStatus(422);
        $this->edit($repair, ['customer_id' => $foreign->id])->assertStatus(422);
        $this->edit($repair, ['customer_id' => $other->id])->assertOk();
        $this->assertSame($other->id, (int) $repair->fresh()->customer_id);
    }

    public function test_the_app_can_replace_and_remove_the_photo(): void
    {
        [, , , $repair] = $this->repair();

        $this->edit($repair, ['image_base64' => self::PNG])->assertOk();
        $this->assertNotNull($repair->fresh()->resolveImagePath());

        $this->edit($repair, ['remove_image' => true])->assertOk();
        $this->assertNull($repair->fresh()->resolveImagePath());
    }

    public function test_editing_needs_the_edit_repairs_permission(): void
    {
        [, $shop, , $repair] = $this->repair();
        $viewer = TenantContext::runFor($shop->id, function () use ($shop) {
            $role = (new \App\Models\Role())->forceFill(['name' => 'viewer', 'display_name' => 'Viewer', 'shop_id' => $shop->id]);
            $role->save();
            $role->permissions()->sync(\App\Models\Permission::whereIn('name', ['repairs.view'])->pluck('id'));

            return \App\Models\User::factory()->create(['shop_id' => $shop->id, 'role_id' => $role->id, 'is_active' => true]);
        });
        Sanctum::actingAs($viewer);

        $this->edit($repair, ['status' => 'in_repair'])->assertForbidden();
        $this->assertSame('ready', $repair->fresh()->status);
    }
}
