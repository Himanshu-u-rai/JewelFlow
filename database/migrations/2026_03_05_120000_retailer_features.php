<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Retailer-edition features: Vendors, HUID/Hallmark, Schemes, Loyalty,
 * EMI/Installments, Reorder Alerts, Customer Occasions.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ──────────────────────────────────────────────
        // 1. Vendors / Suppliers
        // ──────────────────────────────────────────────
        Schema::create('vendors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('contact_person')->nullable();
            $table->string('mobile', 15)->nullable();
            $table->string('email')->nullable();
            $table->text('address')->nullable();
            $table->string('city', 100)->nullable();
            $table->string('state', 100)->nullable();
            $table->string('gst_number', 20)->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['shop_id', 'is_active']);
        });

        // Add vendor_id + HUID to items
        Schema::table('items', function (Blueprint $table) {
            $table->foreignId('vendor_id')->nullable()->after('source')->constrained('vendors')->nullOnDelete();
            $table->string('huid', 30)->nullable()->after('vendor_id');
            $table->date('hallmark_date')->nullable()->after('huid');

            $table->unique(['shop_id', 'huid'], 'items_shop_huid_unique');
        });

        // ──────────────────────────────────────────────
        // 2. Customer Occasions & Loyalty
        // ──────────────────────────────────────────────
        Schema::table('customers', function (Blueprint $table) {
            $table->date('date_of_birth')->nullable()->after('address');
            $table->date('anniversary_date')->nullable()->after('date_of_birth');
            $table->date('wedding_date')->nullable()->after('anniversary_date');
            $table->text('notes')->nullable()->after('wedding_date');
            $table->integer('loyalty_points')->default(0)->after('notes');
        });

        Schema::create('loyalty_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('type', ['earn', 'redeem']);
            $table->integer('points');
            $table->string('description')->nullable();
            $table->integer('balance_after')->default(0);
            $table->timestamps();

            $table->index(['shop_id', 'customer_id']);
        });

        // ──────────────────────────────────────────────
        // 3. Schemes & Offers (Gold Savings / Festival)
        // ──────────────────────────────────────────────
        Schema::create('schemes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->enum('type', ['gold_savings', 'festival_sale', 'discount_offer']);
            $table->text('description')->nullable();
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->enum('discount_type', ['percentage', 'flat'])->nullable();
            $table->decimal('discount_value', 10, 2)->nullable();
            $table->decimal('min_purchase_amount', 12, 2)->nullable();
            $table->integer('total_installments')->nullable(); // For gold savings
            $table->decimal('bonus_month_value', 12, 2)->nullable(); // E.g. 12th month free
            $table->boolean('is_active')->default(true);
            $table->text('terms')->nullable();
            $table->timestamps();

            $table->index(['shop_id', 'is_active']);
            $table->index(['shop_id', 'type']);
        });

        Schema::create('scheme_enrollments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('scheme_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->date('start_date');
            $table->decimal('monthly_amount', 12, 2);
            $table->decimal('total_paid', 12, 2)->default(0);
            $table->integer('installments_paid')->default(0);
            $table->integer('total_installments');
            $table->date('maturity_date')->nullable();
            $table->enum('status', ['active', 'matured', 'cancelled', 'redeemed'])->default('active');
            $table->decimal('bonus_amount', 12, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['shop_id', 'status']);
            $table->index(['shop_id', 'customer_id']);
        });

        Schema::create('scheme_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('enrollment_id')->constrained('scheme_enrollments')->cascadeOnDelete();
            $table->decimal('amount', 12, 2);
            $table->date('payment_date');
            $table->string('payment_method', 30)->default('cash');
            $table->string('receipt_number')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['shop_id', 'enrollment_id']);
        });

        // ──────────────────────────────────────────────
        // 4. EMI / Installment Sales
        // ──────────────────────────────────────────────
        Schema::create('installment_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->decimal('total_amount', 12, 2);
            $table->decimal('down_payment', 12, 2)->default(0);
            $table->decimal('remaining_amount', 12, 2);
            $table->decimal('emi_amount', 12, 2);
            $table->integer('total_emis');
            $table->integer('emis_paid')->default(0);
            $table->date('next_due_date')->nullable();
            $table->enum('status', ['active', 'completed', 'defaulted'])->default('active');
            $table->timestamps();

            $table->index(['shop_id', 'status']);
            $table->index(['shop_id', 'customer_id']);
            $table->index(['shop_id', 'next_due_date']);
        });

        Schema::create('installment_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained('installment_plans')->cascadeOnDelete();
            $table->decimal('amount', 12, 2);
            $table->date('payment_date');
            $table->string('payment_method', 30)->default('cash');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['shop_id', 'plan_id']);
        });

        // ──────────────────────────────────────────────
        // 5. Reorder Alerts
        // ──────────────────────────────────────────────
        Schema::create('reorder_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->string('category')->nullable();
            $table->string('sub_category')->nullable();
            $table->integer('min_stock_threshold')->default(5);
            $table->foreignId('vendor_id')->nullable()->constrained('vendors')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['shop_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reorder_rules');
        Schema::dropIfExists('installment_payments');
        Schema::dropIfExists('installment_plans');
        Schema::dropIfExists('scheme_payments');
        Schema::dropIfExists('scheme_enrollments');
        Schema::dropIfExists('schemes');
        Schema::dropIfExists('loyalty_transactions');

        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['date_of_birth', 'anniversary_date', 'wedding_date', 'notes', 'loyalty_points']);
        });

        Schema::table('items', function (Blueprint $table) {
            $table->dropUnique('items_shop_huid_unique');
            $table->dropForeign(['vendor_id']);
            $table->dropColumn(['vendor_id', 'huid', 'hallmark_date']);
        });

        Schema::dropIfExists('vendors');
    }
};
