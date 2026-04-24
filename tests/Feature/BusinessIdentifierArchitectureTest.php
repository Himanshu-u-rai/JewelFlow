<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\MetalLot;
use App\Models\Role;
use App\Models\Shop;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

class BusinessIdentifierArchitectureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Business identifier architecture tests require PostgreSQL.');
        }
    }

    public function test_lot_number_starts_from_one_per_shop(): void
    {
        [$shopA] = $this->createTenant('Shop A');
        [$shopB] = $this->createTenant('Shop B');

        $lotA = TenantContext::runFor($shopA->id, fn () => MetalLot::create([
            'source' => 'purchase',
            'purity' => 22,
            'fine_weight_total' => 10,
            'fine_weight_remaining' => 10,
            'cost_per_fine_gram' => 7000,
        ]));

        $lotB = TenantContext::runFor($shopB->id, fn () => MetalLot::create([
            'source' => 'purchase',
            'purity' => 24,
            'fine_weight_total' => 10,
            'fine_weight_remaining' => 10,
            'cost_per_fine_gram' => 7500,
        ]));

        $this->assertSame(1, (int) $lotA->lot_number);
        $this->assertSame(1, (int) $lotB->lot_number);
    }

    public function test_invoice_number_sequence_resets_per_shop(): void
    {
        [$shopA, $userA] = $this->createTenant('Shop A');
        [$shopB, $userB] = $this->createTenant('Shop B');

        $customerA = TenantContext::runFor($shopA->id, fn () => Customer::create([
            'first_name' => 'A',
            'last_name' => 'User',
            'mobile' => '9000000001',
            'email' => 'a@example.com',
            'address' => 'Addr A',
        ]));

        $customerB = TenantContext::runFor($shopB->id, fn () => Customer::create([
            'first_name' => 'B',
            'last_name' => 'User',
            'mobile' => '9000000002',
            'email' => 'b@example.com',
            'address' => 'Addr B',
        ]));

        $this->actingAs($userA);
        $invoiceA = TenantContext::runFor($shopA->id, fn () => Invoice::issue([
            'shop_id' => $shopA->id,
            'customer_id' => $customerA->id,
            'gold_rate' => 0,
            'subtotal' => 0,
            'gst' => 0,
            'gst_rate' => 3,
            'wastage_charge' => 0,
            'total' => 0,
            'status' => Invoice::STATUS_DRAFT,
        ]));

        $this->actingAs($userB);
        $invoiceB = TenantContext::runFor($shopB->id, fn () => Invoice::issue([
            'shop_id' => $shopB->id,
            'customer_id' => $customerB->id,
            'gold_rate' => 0,
            'subtotal' => 0,
            'gst' => 0,
            'gst_rate' => 3,
            'wastage_charge' => 0,
            'total' => 0,
            'status' => Invoice::STATUS_DRAFT,
        ]));

        $this->assertStringEndsWith('-0000000001', $invoiceA->invoice_number);
        $this->assertStringEndsWith('-0000000001', $invoiceB->invoice_number);
    }

    public function test_duplicate_shop_lot_number_is_rejected_by_db_constraint(): void
    {
        [$shop] = $this->createTenant('Shop X');

        DB::table('metal_lots')->insert([
            'shop_id' => $shop->id,
            'source' => 'purchase',
            'lot_number' => 99,
            'purity' => 22,
            'fine_weight_total' => 5,
            'fine_weight_remaining' => 5,
            'cost_per_fine_gram' => 7100,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(QueryException::class);

        DB::table('metal_lots')->insert([
            'shop_id' => $shop->id,
            'source' => 'purchase',
            'lot_number' => 99,
            'purity' => 22,
            'fine_weight_total' => 6,
            'fine_weight_remaining' => 6,
            'cost_per_fine_gram' => 7100,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_blade_templates_do_not_render_hash_id_pattern(): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(resource_path('views'))
        );

        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $content = file_get_contents($file->getPathname()) ?: '';
            $this->assertDoesNotMatchRegularExpression('/#\s*\{\{\s*\$[^\}]*->id\s*\}\}/', $content, $file->getPathname());
        }
    }

    private function createTenant(string $name): array
    {
        $shop = Shop::create([
            'name' => $name,
            'phone' => fake()->numerify('9#########'),
            'owner_first_name' => 'Owner',
            'owner_last_name' => $name,
            'owner_mobile' => fake()->unique()->numerify('9#########'),
            'owner_email' => fake()->unique()->safeEmail(),
            'is_active' => true,
            'access_mode' => 'active',
        ]);

        $role = TenantContext::runFor($shop->id, fn () => Role::create([
            'name' => 'owner',
            'display_name' => 'Owner',
            'description' => 'Shop Owner',
        ]));

        $user = User::factory()->create([
            'shop_id' => $shop->id,
            'role_id' => $role->id,
            'is_active' => true,
        ]);

        return [$shop, $user];
    }
}
