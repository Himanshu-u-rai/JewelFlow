<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Edit-tracking for issued quick bills. A quick bill stays a "draft"
     * informal document until issued; once issued it may still be edited (it is
     * not the GST ledger), but we now preserve the as-issued original and flag
     * the bill as edited so the list, the print and downloads can reflect it.
     *
     * - original_snapshot: full JSON of the bill at first issue (header + items
     *   + payments + totals). Frozen; never overwritten after the first issue.
     * - edited_at: set the first time an already-issued bill is changed. NULL =
     *   never edited after issue. Status stays 'issued'; "edited" is a flag.
     */
    public function up(): void
    {
        Schema::table('quick_bills', function (Blueprint $table): void {
            $table->json('original_snapshot')->nullable()->after('shop_snapshot');
            $table->timestamp('edited_at')->nullable()->after('issued_at');
        });
    }

    public function down(): void
    {
        Schema::table('quick_bills', function (Blueprint $table): void {
            $table->dropColumn(['original_snapshot', 'edited_at']);
        });
    }
};
