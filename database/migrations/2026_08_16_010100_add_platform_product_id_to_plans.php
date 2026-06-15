<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Link each plan to a platform product so plan selection can be product-scoped.
 *
 * The plan row shape is otherwise preserved — `code` stays, no billing_cycle
 * column is added (cycle is still chosen at purchase via price_monthly /
 * price_yearly on a single row).
 *
 * Backfill from the existing plan codes:
 *   retailer_*     → retail
 *   dhiran_*       → dhiran
 *   manufacturer_* → manufacturing
 *
 * Plans whose code matches nothing (test fixtures, ad-hoc codes) keep a NULL
 * platform_product_id — product-scoped selection simply won't surface them,
 * which is the safe default.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table): void {
            if (! Schema::hasColumn('plans', 'platform_product_id')) {
                $table->foreignId('platform_product_id')
                    ->nullable()
                    ->after('code')
                    ->constrained('platform_products')
                    ->nullOnDelete();
                $table->index('platform_product_id');
            }
        });

        if (! Schema::hasTable('platform_products')) {
            return;
        }

        $map = [
            'retail'        => 'retailer_',
            'dhiran'        => 'dhiran_',
            'manufacturing' => 'manufacturer_',
        ];

        foreach ($map as $productCode => $planPrefix) {
            $productId = DB::table('platform_products')->where('code', $productCode)->value('id');
            if (! $productId) {
                continue;
            }

            DB::table('plans')
                ->where('code', 'like', $planPrefix . '%')
                ->whereNull('platform_product_id')
                ->update(['platform_product_id' => $productId]);
        }
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table): void {
            if (Schema::hasColumn('plans', 'platform_product_id')) {
                $table->dropConstrainedForeignId('platform_product_id');
            }
        });
    }
};
