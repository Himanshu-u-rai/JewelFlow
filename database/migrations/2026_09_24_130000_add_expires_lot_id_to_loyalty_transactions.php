<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * S3-16. loyalty_transactions is append-only (Constitution Art. IX.A #9), so
 * the old expiry — setting `expired` on the earn row — is refused. An expiry
 * is now its own `redeem` row naming the earn row (lot) it expires. Unique:
 * a lot can be expired once, whoever runs and however often.
 *
 * Adding a nullable column rewrites no rows and fires no row trigger. The
 * legacy `expired` flag stays as history.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('loyalty_transactions', 'expires_lot_id')) {
            return;
        }

        Schema::table('loyalty_transactions', function (Blueprint $table) {
            $table->foreignId('expires_lot_id')->nullable()->unique()->constrained('loyalty_transactions');
        });
    }

    public function down(): void
    {
        // Dropping the column would erase which rows are expiries.
        if (DB::table('loyalty_transactions')->whereNotNull('expires_lot_id')->exists()) {
            throw new RuntimeException('loyalty_transactions.expires_lot_id identifies recorded expiries; not dropped.');
        }

        Schema::table('loyalty_transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('expires_lot_id');
        });
    }
};
