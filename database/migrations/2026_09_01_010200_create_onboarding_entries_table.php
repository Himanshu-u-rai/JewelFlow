<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Editable staging for one batch's opening inputs.
 *
 * Every opening value (cash, vault metal, karigar gold/money, customer
 * gold/advance/receivable/payable, supplier balance, opening stock) is staged
 * as a row here — one `kind` + a jsonb `payload`. Rows are freely editable and
 * deletable while the batch is draft/review; on lock they are posted into the
 * canonical ledgers in one transaction and never touched again. No report ever
 * reads this table, so it is staging, not a balance store.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('onboarding_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->foreignId('onboarding_batch_id')->constrained('onboarding_batches')->cascadeOnDelete();
            $table->string('kind');
            $table->jsonb('payload');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['onboarding_batch_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('onboarding_entries');
    }
};
