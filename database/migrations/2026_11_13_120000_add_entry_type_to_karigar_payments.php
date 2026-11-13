<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bookkeeping rows on karigar_payments (advance used / paid from advance / advance given back / reversal) are marked
 * in system-only columns, never in the free-text `reference` a user types (security review of d734d188).
 *
 * karigar_payments is append-only (CONSTITUTION trigger #11): existing rows are not touched. Both columns are
 * nullable (§4 Rule 1); a NULL entry_type is an ordinary payment or advance.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('karigar_payments', function (Blueprint $table) {
            $table->string('entry_type', 30)->nullable()->after('reference');
            $table->foreignId('source_payment_id')->nullable()->after('entry_type')
                ->constrained('karigar_payments')->restrictOnDelete();
            $table->index(['source_payment_id', 'entry_type'], 'karigar_payments_source_entry_idx');
        });
    }

    public function down(): void
    {
        Schema::table('karigar_payments', function (Blueprint $table) {
            $table->dropIndex('karigar_payments_source_entry_idx');
            $table->dropConstrainedForeignId('source_payment_id');
            $table->dropColumn('entry_type');
        });
    }
};
