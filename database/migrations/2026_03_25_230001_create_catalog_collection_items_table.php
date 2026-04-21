<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog_collection_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('collection_id')
                ->constrained('public_catalog_collections')
                ->cascadeOnDelete();
            $table->foreignId('item_id')
                ->constrained('items')
                ->restrictOnDelete();
            $table->unique(['collection_id', 'item_id']);
            $table->timestamps();
        });

        // Migrate existing JSON item_ids into the pivot table
        $collections = DB::table('public_catalog_collections')
            ->whereNotNull('item_ids')
            ->get(['id', 'item_ids']);

        $existingItemIds = DB::table('items')->pluck('id')->flip();

        foreach ($collections as $collection) {
            $ids = json_decode($collection->item_ids, true);
            if (!is_array($ids)) {
                continue;
            }

            $rows = [];
            $now  = now()->toDateTimeString();

            foreach ($ids as $itemId) {
                $itemId = (int) $itemId;
                if ($itemId > 0 && $existingItemIds->has($itemId)) {
                    $rows[] = [
                        'collection_id' => $collection->id,
                        'item_id'       => $itemId,
                        'created_at'    => $now,
                        'updated_at'    => $now,
                    ];
                }
            }

            if (!empty($rows)) {
                // insertOrIgnore handles the unique constraint gracefully
                DB::table('catalog_collection_items')->insertOrIgnore($rows);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_collection_items');
    }
};
