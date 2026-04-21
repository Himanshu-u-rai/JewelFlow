<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Import;
use App\Models\Item;
use App\Models\MetalLot;
use App\Models\MetalMovement;
use App\Models\Role;
use App\Models\Shop;
use App\Models\SubCategory;
use App\Models\User;
use App\Services\BulkImportService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

class BulkImportSafetyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Bulk import safety tests require PostgreSQL.');
        }
    }

    public function test_cannot_import_when_shop_is_read_only(): void
    {
        [$shop, $user] = $this->createTenant('read_only', false);
        $service = app(BulkImportService::class);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Imports are blocked');

        TenantContext::runFor($shop->id, function () use ($service, $shop, $user): void {
            $service->createPreview(
                $shop->id,
                $user->id,
                Import::TYPE_CATALOG,
                $this->catalogCsv()
            );
        });
    }

    public function test_cannot_import_when_shop_is_suspended(): void
    {
        [$shop, $user] = $this->createTenant('suspended', false);
        $service = app(BulkImportService::class);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Imports are blocked');

        TenantContext::runFor($shop->id, function () use ($service, $shop, $user): void {
            $service->createPreview(
                $shop->id,
                $user->id,
                Import::TYPE_CATALOG,
                $this->catalogCsv()
            );
        });
    }

    public function test_dry_run_preview_does_not_write_ledger_entries(): void
    {
        [$shop, $user] = $this->createTenant();
        $lot = $this->createLot($shop->id, 22.00, 50.000000);
        $service = app(BulkImportService::class);

        $import = TenantContext::runFor($shop->id, function () use ($service, $shop, $user, $lot) {
            return $service->createPreview(
                $shop->id,
                $user->id,
                Import::TYPE_MANUFACTURE,
                $this->manufactureCsv([
                    ['BULK-001', '', $lot->lot_number, 10, 0, 22, 0, 500, 0],
                ])
            );
        });

        $this->assertSame(1, $import->valid_rows);
        $this->assertSame(0, Item::withoutTenant()->where('shop_id', $shop->id)->count());
        $this->assertSame(0, MetalMovement::withoutTenant()->where('shop_id', $shop->id)->count());
        $this->assertSame(50.000000, (float) $lot->fresh()->fine_weight_remaining);
    }

    public function test_catalog_preview_flags_metadata_creation_without_writing_any_metadata(): void
    {
        [$shop, $user] = $this->createTenant();
        $service = app(BulkImportService::class);

        $import = TenantContext::runFor($shop->id, function () use ($service, $shop, $user) {
            return $service->createPreview(
                $shop->id,
                $user->id,
                Import::TYPE_CATALOG,
                UploadedFile::fake()->createWithContent(
                    'catalog-preview-create.csv',
                    implode("\n", [
                        'design_code,name,category,sub_category,default_purity,approx_weight,default_making,stone_type,notes',
                        'AUTO-CAT-001,Auto Cat Ring,Wedding,Bridal Rings,22,9,500,None,Preview only',
                    ])
                )
            );
        });

        $row = $import->rows()->firstOrFail();
        $this->assertTrue((bool) data_get($row->computed, 'will_create_category'));
        $this->assertTrue((bool) data_get($row->computed, 'will_create_sub_category'));
        $this->assertSame(0, Category::withoutTenant()->where('shop_id', $shop->id)->count());
        $this->assertSame(0, SubCategory::withoutTenant()->where('shop_id', $shop->id)->count());
    }

    public function test_catalog_execute_auto_creates_category_and_sub_category(): void
    {
        [$shop, $user] = $this->createTenant();
        $service = app(BulkImportService::class);

        $import = TenantContext::runFor($shop->id, function () use ($service, $shop, $user) {
            return $service->createPreview(
                $shop->id,
                $user->id,
                Import::TYPE_CATALOG,
                UploadedFile::fake()->createWithContent(
                    'catalog-auto-create.csv',
                    implode("\n", [
                        'design_code,name,category,sub_category,default_purity,approx_weight,default_making,stone_type,notes',
                        'AUTO-CAT-002,Auto Cat Pendant,Daily Wear,Pendants,22,7,450,None,Create metadata',
                    ])
                )
            );
        });

        TenantContext::runFor($shop->id, fn () => $service->execute($import->fresh(), Import::MODE_STRICT));

        $category = Category::withoutTenant()
            ->where('shop_id', $shop->id)
            ->where('normalized_name', 'daily wear')
            ->first();
        $this->assertNotNull($category);

        $sub = SubCategory::withoutTenant()
            ->where('shop_id', $shop->id)
            ->where('category_id', $category->id)
            ->where('normalized_name', 'pendants')
            ->first();
        $this->assertNotNull($sub);
    }

    public function test_manufacture_execute_auto_creates_missing_default_metadata(): void
    {
        [$shop, $user] = $this->createTenant();
        $lot = $this->createLot($shop->id, 22.00, 20.000000);
        $service = app(BulkImportService::class);

        $import = TenantContext::runFor($shop->id, function () use ($service, $shop, $user, $lot) {
            return $service->createPreview(
                $shop->id,
                $user->id,
                Import::TYPE_MANUFACTURE,
                $this->manufactureCsv([
                    ['AUTO-MFG-001', '', $lot->lot_number, 2, 0, 22, 0, 0, 0],
                ])
            );
        });

        $row = $import->rows()->firstOrFail();
        $this->assertTrue((bool) data_get($row->computed, 'will_create_category'));
        $this->assertTrue((bool) data_get($row->computed, 'will_create_sub_category'));

        TenantContext::runFor($shop->id, fn () => $service->execute($import->fresh(), Import::MODE_STRICT));

        $category = Category::withoutTenant()
            ->where('shop_id', $shop->id)
            ->where('normalized_name', 'gold jewellery')
            ->first();
        $this->assertNotNull($category);

        $sub = SubCategory::withoutTenant()
            ->where('shop_id', $shop->id)
            ->where('category_id', $category->id)
            ->where('normalized_name', 'general')
            ->first();
        $this->assertNotNull($sub);
    }

    public function test_catalog_preview_rejects_duplicate_design_codes_inside_file(): void
    {
        [$shop, $user] = $this->createTenant();
        $service = app(BulkImportService::class);

        $import = TenantContext::runFor($shop->id, function () use ($service, $shop, $user) {
            return $service->createPreview(
                $shop->id,
                $user->id,
                Import::TYPE_CATALOG,
                UploadedFile::fake()->createWithContent(
                    'catalog-duplicate-design.csv',
                    implode("\n", [
                        'design_code,name,category,sub_category,default_purity,approx_weight,default_making,stone_type,notes',
                        'DUP-100,Item One,Gold Jewellery,Rings,22,5,200,None,One',
                        'DUP-100,Item Two,Gold Jewellery,Rings,22,6,250,None,Two',
                    ])
                )
            );
        });

        $this->assertSame(1, $import->valid_rows);
        $this->assertSame(1, $import->invalid_rows);
        $this->assertStringContainsString(
            'Same design code appears more than once in this file',
            (string) $import->rows()->where('status', 'invalid')->first()?->error_message
        );
    }

    public function test_catalog_execute_reuses_existing_metadata_with_case_insensitive_match(): void
    {
        [$shop, $user] = $this->createTenant();
        $service = app(BulkImportService::class);

        TenantContext::runFor($shop->id, function (): void {
            $cat = Category::create(['name' => 'Gold Jewellery']);
            SubCategory::create(['category_id' => $cat->id, 'name' => 'Rings']);
        });

        $import = TenantContext::runFor($shop->id, function () use ($service, $shop, $user) {
            return $service->createPreview(
                $shop->id,
                $user->id,
                Import::TYPE_CATALOG,
                UploadedFile::fake()->createWithContent(
                    'catalog-case.csv',
                    implode("\n", [
                        'design_code,name,category,sub_category,default_purity,approx_weight,default_making,stone_type,notes',
                        'CASE-100,Case Ring,   GOLD JEWELLERY  , rings ,22,8,450,None,Case insensitive',
                    ])
                )
            );
        });

        TenantContext::runFor($shop->id, fn () => $service->execute($import->fresh(), Import::MODE_STRICT));

        $this->assertSame(1, Category::withoutTenant()->where('shop_id', $shop->id)->where('normalized_name', 'gold jewellery')->count());
        $this->assertSame(1, SubCategory::withoutTenant()->where('shop_id', $shop->id)->where('normalized_name', 'rings')->count());
    }

    public function test_strict_mode_rolls_back_fully_on_row_failure(): void
    {
        [$shop, $user] = $this->createTenant();
        $lot = $this->createLot($shop->id, 22.00, 30.000000);
        $service = app(BulkImportService::class);

        $import = TenantContext::runFor($shop->id, function () use ($service, $shop, $user, $lot) {
            return $service->createPreview(
                $shop->id,
                $user->id,
                Import::TYPE_MANUFACTURE,
                $this->manufactureCsv([
                    ['BULK-STR-001', '', $lot->lot_number, 10, 0, 22, 0, 500, 0],
                    ['BULK-STR-002', '', $lot->lot_number, 5, 0, 22, 0, 300, 0],
                ])
            );
        });

        TenantContext::runFor($shop->id, function () use ($lot): void {
            Item::create([
                'barcode' => 'BULK-STR-002',
                'design' => 'Conflict',
                'category' => 'Gold Jewellery',
                'sub_category' => 'General',
                'gross_weight' => 2,
                'stone_weight' => 0,
                'net_metal_weight' => 2,
                'purity' => 22,
                'metal_lot_id' => $lot->id,
                'status' => 'in_stock',
                'wastage' => 0,
                'making_charges' => 0,
                'stone_charges' => 0,
                'cost_price' => 0,
            ]);
        });

        try {
            TenantContext::runFor($shop->id, fn () => $service->execute($import->fresh(), Import::MODE_STRICT));
            $this->fail('Strict mode must fail and rollback all rows.');
        } catch (\Throwable) {
            // expected
        }

        $this->assertSame(1, Item::withoutTenant()->where('shop_id', $shop->id)->count());
        $this->assertSame(0, MetalMovement::withoutTenant()->where('shop_id', $shop->id)->count());
        $this->assertSame(30.000000, (float) $lot->fresh()->fine_weight_remaining);
        $this->assertSame(Import::STATUS_FAILED, $import->fresh()->status);
    }

    public function test_row_mode_imports_valid_rows_and_keeps_failed_rows_isolated(): void
    {
        [$shop, $user] = $this->createTenant();
        $lot = $this->createLot($shop->id, 22.00, 30.000000);
        $service = app(BulkImportService::class);

        $import = TenantContext::runFor($shop->id, function () use ($service, $shop, $user, $lot) {
            return $service->createPreview(
                $shop->id,
                $user->id,
                Import::TYPE_MANUFACTURE,
                $this->manufactureCsv([
                    ['BULK-ROW-001', '', $lot->lot_number, 10, 0, 22, 0, 500, 0],
                    ['BULK-ROW-002', '', $lot->lot_number, 5, 0, 22, 0, 300, 0],
                ])
            );
        });

        TenantContext::runFor($shop->id, function () use ($lot): void {
            Item::create([
                'barcode' => 'BULK-ROW-002',
                'design' => 'Conflict',
                'category' => 'Gold Jewellery',
                'sub_category' => 'General',
                'gross_weight' => 2,
                'stone_weight' => 0,
                'net_metal_weight' => 2,
                'purity' => 22,
                'metal_lot_id' => $lot->id,
                'status' => 'in_stock',
                'wastage' => 0,
                'making_charges' => 0,
                'stone_charges' => 0,
                'cost_price' => 0,
            ]);
        });

        $result = TenantContext::runFor($shop->id, fn () => $service->execute($import->fresh(), Import::MODE_ROW));

        $this->assertSame(Import::STATUS_COMPLETED, $result->status);
        $this->assertSame(2, Item::withoutTenant()->where('shop_id', $shop->id)->count());
        $this->assertSame(1, MetalMovement::withoutTenant()->where('shop_id', $shop->id)->where('type', 'manufacture')->count());
        $this->assertSame(1, $result->execution_summary['failed_rows']);
        $this->assertTrue((float) $lot->fresh()->fine_weight_remaining >= 0);
    }

    public function test_lot_balance_never_goes_negative_after_import_processing(): void
    {
        [$shop, $user] = $this->createTenant();
        $lot = $this->createLot($shop->id, 24.00, 8.000000);
        $service = app(BulkImportService::class);

        $import = TenantContext::runFor($shop->id, function () use ($service, $shop, $user, $lot) {
            return $service->createPreview(
                $shop->id,
                $user->id,
                Import::TYPE_MANUFACTURE,
                $this->manufactureCsv([
                    ['BAL-001', '', $lot->lot_number, 4, 0, 24, 0, 0, 0],
                    ['BAL-002', '', $lot->lot_number, 4, 0, 24, 0, 0, 0],
                ])
            );
        });

        TenantContext::runFor($shop->id, fn () => $service->execute($import->fresh(), Import::MODE_STRICT));

        $this->assertSame(0.000000, round((float) $lot->fresh()->fine_weight_remaining, 6));
    }

    public function test_import_respects_financial_lock_date(): void
    {
        [$shop, $user] = $this->createTenant();
        $lot = $this->createLot($shop->id, 22.00, 50.000000);
        $service = app(BulkImportService::class);

        $import = TenantContext::runFor($shop->id, function () use ($service, $shop, $user, $lot) {
            return $service->createPreview(
                $shop->id,
                $user->id,
                Import::TYPE_MANUFACTURE,
                $this->manufactureCsv([
                    ['LOCK-001', '', $lot->lot_number, 10, 0, 22, 0, 0, 0],
                ])
            );
        });

        DB::table('shop_rules')->updateOrInsert(
            ['shop_id' => $shop->id],
            [
                'default_purity' => '22K',
                'default_making_type' => 'per_gram',
                'default_making_value' => 0,
                'test_loss_percent' => 0,
                'buyback_percent' => 100,
                'rounding_precision' => 2,
                'financial_lock_date' => now()->toDateString(),
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Financial lock is active');

        TenantContext::runFor($shop->id, fn () => $service->execute($import->fresh(), Import::MODE_STRICT));
    }

    public function test_ledger_immutability_is_not_bypassed_by_imports(): void
    {
        [$shop, $user] = $this->createTenant();
        $lot = $this->createLot($shop->id, 24.00, 20.000000);
        $service = app(BulkImportService::class);

        $import = TenantContext::runFor($shop->id, function () use ($service, $shop, $user, $lot) {
            return $service->createPreview(
                $shop->id,
                $user->id,
                Import::TYPE_MANUFACTURE,
                $this->manufactureCsv([
                    ['IMM-001', '', $lot->lot_number, 2, 0, 24, 0, 0, 0],
                ])
            );
        });

        TenantContext::runFor($shop->id, fn () => $service->execute($import->fresh(), Import::MODE_STRICT));

        $movement = MetalMovement::withoutTenant()->where('shop_id', $shop->id)->firstOrFail();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('append-only');
        $movement->update(['fine_weight' => 999]);
    }

    public function test_import_execution_is_idempotent_after_completion(): void
    {
        [$shop, $user] = $this->createTenant();
        $lot = $this->createLot($shop->id, 24.00, 20.000000);
        $service = app(BulkImportService::class);

        $import = TenantContext::runFor($shop->id, function () use ($service, $shop, $user, $lot) {
            return $service->createPreview(
                $shop->id,
                $user->id,
                Import::TYPE_MANUFACTURE,
                $this->manufactureCsv([
                    ['IDEMP-001', '', $lot->lot_number, 2, 0, 24, 0, 0, 0],
                ])
            );
        });

        TenantContext::runFor($shop->id, fn () => $service->execute($import->fresh(), Import::MODE_STRICT));
        TenantContext::runFor($shop->id, fn () => $service->execute($import->fresh(), Import::MODE_STRICT));

        $this->assertSame(1, Item::withoutTenant()->where('shop_id', $shop->id)->where('barcode', 'IDEMP-001')->count());
        $this->assertSame(18.000000, round((float) $lot->fresh()->fine_weight_remaining, 6));
    }

    private function createTenant(string $accessMode = 'active', bool $isActive = true): array
    {
        $shop = Shop::create([
            'name' => 'Bulk Test Shop',
            'phone' => fake()->numerify('9#########'),
            'owner_first_name' => 'Bulk',
            'owner_last_name' => 'Owner',
            'owner_mobile' => fake()->unique()->numerify('9#########'),
            'owner_email' => fake()->safeEmail(),
            'is_active' => $isActive,
            'access_mode' => $accessMode,
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

        DB::table('shop_rules')->updateOrInsert(
            ['shop_id' => $shop->id],
            [
                'default_purity' => '22K',
                'default_making_type' => 'per_gram',
                'default_making_value' => 0,
                'test_loss_percent' => 0,
                'buyback_percent' => 100,
                'rounding_precision' => 2,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        return [$shop, $user];
    }

    private function createLot(int $shopId, float $purity, float $fine): MetalLot
    {
        return TenantContext::runFor($shopId, fn () => MetalLot::create([
            'source' => 'purchase',
            'purity' => $purity,
            'fine_weight_total' => $fine,
            'fine_weight_remaining' => $fine,
            'cost_per_fine_gram' => 7000,
        ]));
    }

    private function catalogCsv(): UploadedFile
    {
        $content = implode("\n", [
            'design_code,name,category,sub_category,default_purity,approx_weight,default_making,stone_type,notes',
            'D-100,Sample Ring,Gold Jewellery,Rings,22,10,500,None,Seed',
        ]);

        return UploadedFile::fake()->createWithContent('catalog.csv', $content);
    }

    private function manufactureCsv(array $rows): UploadedFile
    {
        $lines = ['barcode,design_code,lot_number,gross_weight,stone_weight,purity,wastage_percent,making_charge,stone_charge'];
        foreach ($rows as $row) {
            $lines[] = implode(',', $row);
        }

        return UploadedFile::fake()->createWithContent('manufacture.csv', implode("\n", $lines));
    }
}
