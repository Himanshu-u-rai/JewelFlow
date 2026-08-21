<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\ExchangeOrder;
use App\Models\Invoice;
use App\Models\MetalLot;
use App\Models\ReturnOrder;
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

        // Each shop counts in its own sequence, so two shops issuing their first
        // invoice both land on the same opening number. Asserted on the sequence
        // and on that collision rather than a literal format: the number is now
        // prefix + sequence + suffix, all owner-configurable per shop, so pinning
        // the rendered string would test the default settings, not the isolation.
        $this->assertSame($invoiceA->invoice_sequence, $invoiceB->invoice_sequence);
        $this->assertSame($invoiceA->invoice_number, $invoiceB->invoice_number);

        // And the sequence advances within a shop without touching the other.
        $this->actingAs($userA);
        $invoiceA2 = TenantContext::runFor($shopA->id, fn () => Invoice::issue([
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

        $this->assertSame($invoiceA->invoice_sequence + 1, $invoiceA2->invoice_sequence);
        $this->assertSame($invoiceB->invoice_sequence, $invoiceB->fresh()->invoice_sequence);
    }

    public function test_return_and_exchange_numbers_restart_per_shop(): void
    {
        [$shopA, $userA] = $this->createTenant('Shop A');
        [$shopB, $userB] = $this->createTenant('Shop B');

        $returnA  = $this->createReturn($shopA, $userA);
        $returnB  = $this->createReturn($shopB, $userB);
        $returnA2 = $this->createReturn($shopA, $userA);

        $this->assertSame(1, (int) $returnA->return_number);
        $this->assertSame(1, (int) $returnB->return_number, 'shop B counts on its own, unaffected by shop A');
        $this->assertSame(2, (int) $returnA2->return_number);
        $this->assertSame('RET-001', $returnA->display_number);

        // A draft exchange has neither a return nor a new invoice attached yet
        // — which is precisely why it cannot borrow either one's number.
        $exchangeA = $this->createDraftExchange($shopA, $userA);
        $exchangeB = $this->createDraftExchange($shopB, $userB);

        $this->assertSame(1, (int) $exchangeA->exchange_number);
        $this->assertSame(1, (int) $exchangeB->exchange_number);
        $this->assertSame('EXC-001', $exchangeA->display_number);
    }

    /**
     * The model hook covers Eloquent; the Postgres trigger covers everything
     * else. Seeders, imports and raw DB::table() writes must still land on a
     * number, or the NOT NULL column would reject them outright.
     */
    public function test_raw_insert_bypassing_eloquent_still_receives_a_return_number(): void
    {
        [$shop, $user] = $this->createTenant('Shop X');
        $existing = $this->createReturn($shop, $user);

        $id = DB::table('return_orders')->insertGetId([
            'shop_id'            => $shop->id,
            'invoice_id'         => $existing->invoice_id,
            'customer_id'        => $existing->customer_id,
            'return_type'        => ReturnOrder::TYPE_CUSTOMER_RETURN,
            'status'             => ReturnOrder::STATUS_DRAFT,
            'created_by_user_id' => $user->id,
            'created_at'         => now(),
            'updated_at'         => now(),
        ]);

        $this->assertSame(2, (int) DB::table('return_orders')->where('id', $id)->value('return_number'));
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

    /**
     * A row id is not a document number. It leaks how many of a thing exist
     * across every shop, and it renumbers if rows are ever migrated — so a
     * customer's "RO#412" would not survive a restore. Every user-facing
     * document renders its per-shop business identifier instead.
     *
     * Collects EVERY offender before asserting: the previous version asserted
     * inside the loop, so it stopped at the first file and dumped that whole
     * template as the diff, hiding both the line number and the other files.
     */
    public function test_blade_templates_do_not_render_hash_id_pattern(): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(resource_path('views'))
        );

        $violations = [];

        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            foreach (file($file->getPathname()) ?: [] as $index => $line) {
                if (preg_match('/#\s*\{\{\s*\$[^\}]*->id\s*\}\}/', $line, $matches)) {
                    $violations[] = sprintf(
                        '%s:%d  %s',
                        str_replace(resource_path('views') . '/', '', $file->getPathname()),
                        $index + 1,
                        trim($matches[0])
                    );
                }
            }
        }

        $this->assertSame([], $violations, sprintf(
            "%d blade template(s) render a raw row id as a document number:\n%s",
            count($violations),
            implode("\n", $violations)
        ));
    }

    private function createReturn(Shop $shop, User $user): ReturnOrder
    {
        $this->actingAs($user);

        return TenantContext::runFor($shop->id, function () use ($shop, $user) {
            $customer = Customer::create([
                'first_name' => 'Ret',
                'last_name'  => 'Customer',
                'mobile'     => fake()->unique()->numerify('9#########'),
                'address'    => 'Addr',
            ]);

            $invoice = Invoice::issue([
                'shop_id'        => $shop->id,
                'customer_id'    => $customer->id,
                'gold_rate'      => 0,
                'subtotal'       => 0,
                'gst'            => 0,
                'gst_rate'       => 3,
                'wastage_charge' => 0,
                'total'          => 0,
                'status'         => Invoice::STATUS_DRAFT,
            ]);

            return ReturnOrder::create([
                'shop_id'            => $shop->id,
                'invoice_id'         => $invoice->id,
                'customer_id'        => $customer->id,
                'return_type'        => ReturnOrder::TYPE_CUSTOMER_RETURN,
                'status'             => ReturnOrder::STATUS_DRAFT,
                'created_by_user_id' => $user->id,
            ]);
        });
    }

    private function createDraftExchange(Shop $shop, User $user): ExchangeOrder
    {
        return TenantContext::runFor($shop->id, fn () => ExchangeOrder::create([
            'shop_id'                => $shop->id,
            'valuation_basis_source' => ExchangeOrder::BASIS_TODAY_RATE,
            'status'                 => ExchangeOrder::STATUS_DRAFT,
            'created_by_user_id'     => $user->id,
        ]));
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
