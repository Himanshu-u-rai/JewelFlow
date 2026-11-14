<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Audit N6 (2026-11-13): publishing used to commit a `publishing` claim and release it in a `finally` that a killed
 * process skips. A batch still `publishing` today is stranded: the publish work is one transaction, so it published
 * nothing, and from now on the claim is never committed on its own. It goes back to `review` (a transition the
 * batch guard trigger allows), where it can be published again.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('historical_import_batches')->where('status', 'publishing')
            ->update(['status' => 'review', 'updated_at' => now()]);
    }

    public function down(): void
    {
        // Nothing to restore: a stranded claim carried no work.
    }
};
