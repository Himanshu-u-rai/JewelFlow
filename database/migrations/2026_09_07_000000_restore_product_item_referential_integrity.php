<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Restore the referential integrity between items.product_id and products.
 *
 * BACKGROUND: an earlier migration (2026_01_16_233220_add_product_id_to_items_table)
 * was supposed to add items.product_id + an FK, but an earlier migration
 * (2026_01_15_000001) had already added the bare product_id column, so its
 * `if (Schema::hasColumn('items','product_id')) return;` guard early-returned and
 * the FK was NEVER created. Live DB confirms: items.product_id has no FK, no index.
 *
 * This installs the FK that should have existed, as a COMPOSITE key so the Item
 * can never point at a Product belonging to a different shop:
 *   items(product_id, shop_id) -> products(id, shop_id) ON DELETE NO ACTION
 *
 * NO ACTION (deferrable, checked at statement end) not RESTRICT (immediate): a
 * whole-shop delete cascades products via products.shop_id and items via a
 * separate statement/path; NO ACTION lets those settle within the statement
 * instead of tripping on intermediate state, while still blocking a *direct*
 * product delete that would orphan an item.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Fail closed: never force the constraint on by silently rewriting data.
        // Report counts only (no IDs / customer data).
        $orphans = DB::table('items')
            ->whereNotNull('product_id')
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('products')
                    ->whereColumn('products.id', 'items.product_id');
            })
            ->count();

        $mismatched = DB::table('items')
            ->join('products', 'products.id', '=', 'items.product_id')
            ->whereNotNull('items.product_id')
            ->whereColumn('items.shop_id', '!=', 'products.shop_id')
            ->count();

        if ($orphans > 0 || $mismatched > 0) {
            throw new RuntimeException(
                "Refusing to add items->products FK: found {$orphans} item(s) with a "
                . "product_id referencing no product, and {$mismatched} item(s) whose "
                . "shop_id disagrees with their product's shop_id. Repair these rows "
                . "before re-running this migration. No data was modified."
            );
        }

        // Parent side: composite FK requires a unique key on the referenced columns.
        // products.id is already the PK so (id, shop_id) is trivially unique; this
        // just makes it referenceable.
        if (! $this->indexExists('products_id_shop_id_unique')) {
            DB::statement('ALTER TABLE products ADD CONSTRAINT products_id_shop_id_unique UNIQUE (id, shop_id)');
        }

        // Child index for dependency lookup (WHERE product_id = ?) — Postgres does
        // not auto-index FK child columns. Leftmost prefix also serves the FK.
        if (! $this->indexExists('items_product_id_shop_id_index')) {
            DB::statement('CREATE INDEX items_product_id_shop_id_index ON items (product_id, shop_id)');
        }

        if (! $this->constraintExists('items_product_id_shop_id_foreign')) {
            DB::statement(
                'ALTER TABLE items ADD CONSTRAINT items_product_id_shop_id_foreign '
                . 'FOREIGN KEY (product_id, shop_id) REFERENCES products (id, shop_id) ON DELETE NO ACTION'
            );
        }
    }

    public function down(): void
    {
        // Remove only this migration's own objects. Never touch Item data.
        if ($this->constraintExists('items_product_id_shop_id_foreign')) {
            DB::statement('ALTER TABLE items DROP CONSTRAINT items_product_id_shop_id_foreign');
        }
        if ($this->indexExists('items_product_id_shop_id_index')) {
            DB::statement('DROP INDEX items_product_id_shop_id_index');
        }
        if ($this->indexExists('products_id_shop_id_unique')) {
            DB::statement('ALTER TABLE products DROP CONSTRAINT products_id_shop_id_unique');
        }
    }

    private function constraintExists(string $name): bool
    {
        return DB::table('pg_constraint')->where('conname', $name)->exists();
    }

    private function indexExists(string $name): bool
    {
        return DB::table('pg_class')->where('relname', $name)->where('relkind', 'i')->exists();
    }
};
