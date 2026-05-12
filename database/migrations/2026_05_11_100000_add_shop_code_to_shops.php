<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shops', function (Blueprint $table): void {
            $table->string('shop_code', 20)->nullable()->unique()->after('id');
        });

        // Back-fill existing shops in ID order
        $shops = DB::table('shops')->orderBy('id')->get(['id']);
        foreach ($shops as $shop) {
            $next = DB::table('platform_counters')
                ->where('counter_key', 'shop_code')
                ->lockForUpdate()
                ->value('current_value');

            if ($next === null) {
                DB::table('platform_counters')->insert([
                    'counter_key'   => 'shop_code',
                    'current_value' => 0,
                    'created_at'    => now(),
                    'updated_at'    => now(),
                ]);
                $next = 0;
            }

            $next++;

            DB::table('platform_counters')
                ->where('counter_key', 'shop_code')
                ->update(['current_value' => $next, 'updated_at' => now()]);

            DB::table('shops')->where('id', $shop->id)->update([
                'shop_code' => 'JF-' . str_pad((string) $next, 4, '0', STR_PAD_LEFT),
            ]);
        }

        // Now make it non-nullable
        Schema::table('shops', function (Blueprint $table): void {
            $table->string('shop_code', 20)->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table): void {
            $table->dropColumn('shop_code');
        });

        DB::table('platform_counters')->where('counter_key', 'shop_code')->delete();
    }
};
