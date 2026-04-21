<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        // Generate share tokens in batches for items that don't have one yet.
        // This removes the lazy DB-write-on-page-load anti-pattern.
        DB::table('items')
            ->whereNull('share_token')
            ->orderBy('id')
            ->chunkById(500, function ($items) {
                foreach ($items as $item) {
                    do {
                        $token = (string) Str::ulid();
                    } while (DB::table('items')->where('share_token', $token)->exists());

                    DB::table('items')
                        ->where('id', $item->id)
                        ->update(['share_token' => $token]);
                }
            });
    }

    public function down(): void
    {
        // Non-destructive rollback: leave tokens in place
    }
};
