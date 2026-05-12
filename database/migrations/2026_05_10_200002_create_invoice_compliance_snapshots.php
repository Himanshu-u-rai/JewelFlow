<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_compliance_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->foreignId('invoice_id')->unique()->constrained('invoices')->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();

            // Frozen at sale time — never updated even if customer profile changes later
            $table->string('snapshot_customer_name', 511);
            $table->string('snapshot_pan', 20)->nullable();
            $table->string('snapshot_id_number', 100)->nullable();
            $table->string('snapshot_mobile', 15)->nullable();
            $table->text('snapshot_address')->nullable();

            // Compliance metadata
            $table->decimal('invoice_total', 14, 2);
            $table->decimal('threshold_at_sale', 14, 2);
            $table->boolean('compliance_required')->default(false);
            $table->boolean('compliance_met')->default(false);
            $table->boolean('consent_given')->default(false);

            // Who completed compliance at POS
            $table->foreignId('completed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();

            // Split transaction detection context
            $table->integer('same_day_invoice_count')->default(1);
            $table->decimal('same_day_total', 14, 2)->default(0);
            $table->boolean('split_alert_raised')->default(false);

            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['shop_id', 'customer_id', 'completed_at'], 'ics_shop_customer_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_compliance_snapshots');
    }
};
