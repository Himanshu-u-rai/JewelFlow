<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 1 of the Returns & Exchanges domain.
     *
     * Adds the inventory-side cache column for returned-finished-jewellery. The
     * 'returned' value is already permitted by the existing items_status_check
     * constraint, so no CHECK change is needed.
     *
     * `return_disposition` is a denormalised pointer to the latest row in the
     * `returned_item_dispositions` table (introduced later in this phase). It
     * exists to speed up the Returns Inbox filter; the source of truth is the
     * disposition events table.
     */
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->string('return_disposition', 32)->nullable()->after('reversed_by');
            $table->index(['shop_id', 'status', 'return_disposition'], 'items_returns_inbox_idx');
        });
    }

    public function down(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->dropIndex('items_returns_inbox_idx');
            $table->dropColumn('return_disposition');
        });
    }
};
