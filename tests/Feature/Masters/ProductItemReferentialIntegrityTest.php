<?php

namespace Tests\Feature\Masters;

use App\Models\Category;
use App\Models\Item;
use App\Models\Product;
use App\Models\SubCategory;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * MASTERS PART 0.5 — Product/Item referential integrity restoration.
 *
 * Migration 2026_09_07_000000 installs the FK that a migration-ordering bug had
 * silently skipped years ago:
 *   items(product_id, shop_id) -> products(id, shop_id) ON DELETE NO ACTION
 * plus the unique key products(id, shop_id) it references and a child index
 * items(product_id, shop_id) for dependency lookups.
 *
 * This locks the schema shape and the four behaviour classes it changes:
 * valid data, invalid data (DB-rejected), deletion, and the HTTP write path.
 * (Two-connection concurrency and the migration's fail-closed precondition are
 * proven out-of-suite — they need real transactions / clean-DB control that
 * RefreshDatabase's wrapping transaction cannot provide.)
 *
 * Postgres note: an FK violation aborts the whole transaction, so every
 * "expect rejection" case wraps the failing statement in a nested
 * DB::transaction() — that opens a SAVEPOINT, and rolling back to it on the
 * QueryException un-poisons the outer RefreshDatabase transaction so the
 * follow-up assertions can still query.
 */
class ProductItemReferentialIntegrityTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    private function makeProduct(int $shopId): Product
    {
        $category = Category::forceCreate(['shop_id' => $shopId, 'name' => 'Rings']);
        $subCategory = SubCategory::forceCreate(['shop_id' => $shopId, 'category_id' => $category->id, 'name' => 'Plain']);

        return Product::forceCreate([
            'shop_id' => $shopId,
            'name' => 'Test Product',
            'design_code' => 'PRD-' . uniqid(),
            'category_id' => $category->id,
            'sub_category_id' => $subCategory->id,
        ]);
    }

    // ---- Schema ---------------------------------------------------------

    public function test_composite_fk_exists_with_no_action_delete(): void
    {
        $row = DB::table('pg_constraint')
            ->where('conname', 'items_product_id_shop_id_foreign')
            ->first();

        $this->assertNotNull($row, 'composite FK missing');
        $this->assertSame('f', $row->contype);
        // confdeltype 'a' = NO ACTION (deferrable); 'r' would be RESTRICT.
        $this->assertSame('a', $row->confdeltype);
    }

    public function test_referenced_unique_key_and_child_index_exist(): void
    {
        $this->assertTrue(
            DB::table('pg_constraint')->where('conname', 'products_id_shop_id_unique')->where('contype', 'u')->exists(),
            'products(id, shop_id) unique key missing'
        );
        $this->assertTrue(
            DB::table('pg_class')->where('relname', 'items_product_id_shop_id_index')->where('relkind', 'i')->exists(),
            'items(product_id, shop_id) index missing'
        );
    }

    // ---- Valid data -----------------------------------------------------

    public function test_null_product_id_is_allowed(): void
    {
        [, $shop] = $this->createManufacturerTenant();
        $item = $this->createItem($shop->id, null, ['product_id' => null]);

        $this->assertNull($item->fresh()->product_id);
    }

    public function test_same_shop_product_link_is_allowed_and_resolves(): void
    {
        [, $shop] = $this->createManufacturerTenant();
        $product = $this->makeProduct($shop->id);
        $item = $this->createItem($shop->id, null, ['product_id' => $product->id]);

        $this->assertSame($product->id, $item->fresh()->product_id);
        $this->assertTrue($item->product()->is($product));
    }

    // ---- Invalid data (rejected by DB) ---------------------------------

    public function test_nonexistent_product_id_is_rejected_by_database(): void
    {
        [, $shop] = $this->createManufacturerTenant();

        $this->assertDbRejects(fn () => $this->createItem($shop->id, null, ['product_id' => 999999]));
    }

    public function test_cross_shop_product_link_is_rejected_by_database(): void
    {
        [, $shopA] = $this->createManufacturerTenant();
        [, $shopB] = $this->createManufacturerTenant();
        $productA = $this->makeProduct($shopA->id);

        $this->assertDbRejects(fn () => $this->createItem($shopB->id, null, ['product_id' => $productA->id]));
    }

    // ---- Deletion -------------------------------------------------------

    public function test_raw_product_delete_with_linked_item_is_rejected_and_link_survives(): void
    {
        [, $shop] = $this->createManufacturerTenant();
        $product = $this->makeProduct($shop->id);
        $item = $this->createItem($shop->id, null, ['product_id' => $product->id]);

        $this->assertDbRejects(fn () => $product->delete());

        $this->assertDatabaseHas('products', ['id' => $product->id]);
        $this->assertDatabaseHas('items', ['id' => $item->id, 'product_id' => $product->id]);
    }

    public function test_unlinking_item_then_deleting_product_succeeds(): void
    {
        [, $shop] = $this->createManufacturerTenant();
        $product = $this->makeProduct($shop->id);
        $item = $this->createItem($shop->id, null, ['product_id' => $product->id]);

        $item->forceFill(['product_id' => null])->save();
        $product->delete();

        $this->assertDatabaseMissing('products', ['id' => $product->id]);
        $this->assertNull($item->fresh()->product_id);
    }

    public function test_unused_product_deletes(): void
    {
        [, $shop] = $this->createManufacturerTenant();
        $product = $this->makeProduct($shop->id);

        $product->delete();

        $this->assertDatabaseMissing('products', ['id' => $product->id]);
    }

    // ---- HTTP write path ------------------------------------------------

    public function test_web_item_store_rejects_cross_shop_product_id(): void
    {
        [$userA, $shopA] = $this->createManufacturerTenant();
        [, $shopB] = $this->createManufacturerTenant();
        $productB = $this->makeProduct($shopB->id);
        $this->actingAs($userA);

        // Minimal payload: other fields may fail validation too, but the point is
        // the scoped Rule::exists rejects another shop's product_id specifically.
        $response = $this->from(route('inventory.items.create'))
            ->post(route('inventory.items.store'), ['product_id' => $productB->id]);

        $response->assertSessionHasErrors('product_id');
        $this->assertDatabaseMissing('items', ['product_id' => $productB->id]);
    }

    // ---- helper ---------------------------------------------------------

    private function assertDbRejects(callable $op): void
    {
        try {
            DB::transaction($op); // SAVEPOINT: rollback on error un-poisons outer tx
            $this->fail('Expected the database to reject this operation with a FK violation.');
        } catch (QueryException $e) {
            $this->assertSame('23503', $e->getCode());
        }
    }
}
