<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── dhiran_loans ────────────────────────────────────────────────
        Schema::create('dhiran_loans', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->string('loan_number', 30);
            $table->foreignId('customer_id')->constrained('customers');
            $table->date('loan_date');
            $table->decimal('gold_rate_on_date', 12, 4);
            $table->decimal('silver_rate_on_date', 12, 4)->nullable();
            $table->decimal('principal_amount', 14, 2);
            $table->decimal('processing_fee', 14, 2)->default(0);
            $table->string('processing_fee_type', 10)->default('flat');
            $table->decimal('interest_rate_monthly', 5, 2);
            $table->string('interest_type', 10)->default('flat');
            $table->decimal('penalty_rate_monthly', 5, 2)->default(0);
            $table->decimal('ltv_ratio_applied', 5, 2);
            $table->decimal('total_collateral_value', 14, 2);
            $table->decimal('total_fine_weight', 10, 6);
            $table->decimal('outstanding_principal', 14, 2);
            $table->decimal('outstanding_interest', 14, 2)->default(0);
            $table->decimal('outstanding_penalty', 14, 2)->default(0);
            $table->date('interest_accrued_through')->nullable();
            $table->decimal('total_interest_collected', 14, 2)->default(0);
            $table->decimal('total_penalty_collected', 14, 2)->default(0);
            $table->decimal('total_principal_collected', 14, 2)->default(0);
            $table->integer('tenure_months');
            $table->date('maturity_date');
            $table->integer('min_lock_months')->default(0);
            $table->integer('grace_period_days')->default(30);
            $table->integer('min_interest_months')->default(1);
            $table->string('status', 20)->default('active');
            $table->integer('renewed_count')->default(0);
            $table->foreignId('renewed_from_id')->nullable()->constrained('dhiran_loans')->nullOnDelete();
            $table->string('kyc_aadhaar', 12)->nullable();
            $table->string('kyc_pan', 10)->nullable();
            $table->string('kyc_photo_path', 500)->nullable();
            $table->text('terms_text')->nullable();
            $table->text('notes')->nullable();
            $table->dateTime('closed_at')->nullable();
            $table->text('closure_notes')->nullable();
            $table->dateTime('forfeited_at')->nullable();
            $table->dateTime('forfeiture_notice_sent_at')->nullable();
            $table->text('forfeiture_notice_text')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['shop_id', 'loan_number']);
            $table->index(['shop_id', 'status']);
            $table->index(['shop_id', 'customer_id']);
            $table->index(['shop_id', 'status', 'maturity_date']);
        });

        DB::statement('ALTER TABLE dhiran_loans ADD CONSTRAINT chk_dhiran_loans_outstanding_principal CHECK (outstanding_principal >= 0)');
        DB::statement('ALTER TABLE dhiran_loans ADD CONSTRAINT chk_dhiran_loans_outstanding_interest CHECK (outstanding_interest >= 0)');
        DB::statement('ALTER TABLE dhiran_loans ADD CONSTRAINT chk_dhiran_loans_outstanding_penalty CHECK (outstanding_penalty >= 0)');
        DB::statement('ALTER TABLE dhiran_loans ADD CONSTRAINT chk_dhiran_loans_principal_amount CHECK (principal_amount > 0)');

        // ── dhiran_loan_items ───────────────────────────────────────────
        Schema::create('dhiran_loan_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->foreignId('dhiran_loan_id')->constrained('dhiran_loans')->cascadeOnDelete();
            $table->string('description', 500);
            $table->string('category', 100)->nullable();
            $table->string('metal_type', 20)->default('gold');
            $table->integer('quantity')->default(1);
            $table->decimal('gross_weight', 10, 6);
            $table->decimal('stone_weight', 10, 6)->default(0);
            $table->decimal('net_metal_weight', 10, 6);
            $table->decimal('purity', 5, 2);
            $table->decimal('fine_weight', 10, 6);
            $table->decimal('rate_per_gram_at_pledge', 12, 4);
            $table->decimal('market_value', 14, 2);
            $table->decimal('loan_value', 14, 2);
            $table->string('photo_path', 500)->nullable();
            $table->string('huid', 30)->nullable();
            $table->string('status', 20)->default('pledged');
            $table->dateTime('released_at')->nullable();
            $table->text('release_condition_note')->nullable();
            $table->foreignId('released_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('forfeited_at')->nullable();
            $table->timestamps();

            $table->index(['shop_id', 'dhiran_loan_id']);
        });

        // ── dhiran_payments (immutable) ─────────────────────────────────
        Schema::create('dhiran_payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->foreignId('dhiran_loan_id')->constrained('dhiran_loans')->cascadeOnDelete();
            $table->date('payment_date');
            $table->string('type', 30);
            $table->decimal('amount', 14, 2);
            $table->string('direction', 3);
            $table->string('payment_method', 30)->default('cash');
            $table->decimal('interest_component', 14, 2)->default(0);
            $table->decimal('penalty_component', 14, 2)->default(0);
            $table->decimal('principal_component', 14, 2)->default(0);
            $table->decimal('processing_fee_component', 14, 2)->default(0);
            $table->decimal('outstanding_principal_after', 14, 2);
            $table->decimal('outstanding_interest_after', 14, 2);
            $table->decimal('outstanding_penalty_after', 14, 2)->default(0);
            $table->string('receipt_number', 50)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['shop_id', 'dhiran_loan_id', 'payment_date']);
        });

        DB::statement('ALTER TABLE dhiran_payments ADD CONSTRAINT chk_dhiran_payments_amount CHECK (amount > 0)');

        // ── dhiran_cash_entries (immutable — Dhiran's own cash ledger) ──
        Schema::create('dhiran_cash_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->foreignId('dhiran_loan_id')->constrained('dhiran_loans')->cascadeOnDelete();
            $table->foreignId('dhiran_payment_id')->nullable()->constrained('dhiran_payments')->nullOnDelete();
            $table->date('entry_date');
            $table->string('type', 3);
            $table->decimal('amount', 14, 2);
            $table->string('source_type', 30);
            $table->string('payment_method', 30)->default('cash');
            $table->text('description')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['shop_id', 'entry_date']);
            $table->index(['shop_id', 'type']);
        });

        DB::statement('ALTER TABLE dhiran_cash_entries ADD CONSTRAINT chk_dhiran_cash_entries_amount CHECK (amount > 0)');

        // ── dhiran_ledger_entries (immutable) ───────────────────────────
        Schema::create('dhiran_ledger_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->foreignId('dhiran_loan_id')->constrained('dhiran_loans')->cascadeOnDelete();
            $table->foreignId('dhiran_payment_id')->nullable()->constrained('dhiran_payments')->nullOnDelete();
            $table->string('entry_type', 30);
            $table->string('direction', 6);
            $table->decimal('amount', 14, 2);
            $table->decimal('balance_after', 14, 2);
            $table->decimal('interest_balance_after', 14, 2)->default(0);
            $table->decimal('penalty_balance_after', 14, 2)->default(0);
            $table->text('note')->nullable();
            $table->jsonb('meta')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['shop_id', 'dhiran_loan_id']);
        });

        DB::statement('ALTER TABLE dhiran_ledger_entries ADD CONSTRAINT chk_dhiran_ledger_entries_amount CHECK (amount >= 0)');

        // ── dhiran_settings (one per shop) ──────────────────────────────
        Schema::create('dhiran_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('shop_id')->unique()->constrained('shops')->cascadeOnDelete();
            $table->boolean('is_enabled')->default(false);
            $table->decimal('default_interest_rate_monthly', 5, 2)->default(2.00);
            $table->string('default_interest_type', 10)->default('flat');
            $table->decimal('default_penalty_rate_monthly', 5, 2)->default(0.50);
            $table->decimal('default_ltv_ratio', 5, 2)->default(75.00);
            $table->decimal('high_value_ltv_ratio', 5, 2)->default(75.00);
            $table->decimal('high_value_threshold', 14, 2)->default(250000.00);
            $table->integer('default_tenure_months')->default(12);
            $table->integer('default_min_lock_months')->default(0);
            $table->integer('default_min_interest_months')->default(1);
            $table->decimal('min_loan_amount', 14, 2)->default(1000.00);
            $table->decimal('max_loan_amount', 14, 2)->default(5000000.00);
            $table->string('processing_fee_type', 10)->default('flat');
            $table->decimal('processing_fee_value', 10, 2)->default(0);
            $table->integer('grace_period_days')->default(30);
            $table->integer('forfeiture_notice_days')->default(30);
            $table->string('loan_number_prefix', 10)->default('DH-');
            $table->boolean('kyc_mandatory')->default(true);
            $table->text('receipt_header_text')->nullable();
            $table->text('receipt_footer_text')->nullable();
            $table->text('receipt_terms_text')->nullable();
            $table->text('closure_certificate_text')->nullable();
            $table->boolean('sms_reminders_enabled')->default(false);
            $table->integer('reminder_days_before_due')->default(7);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dhiran_settings');
        Schema::dropIfExists('dhiran_ledger_entries');
        Schema::dropIfExists('dhiran_cash_entries');
        Schema::dropIfExists('dhiran_payments');
        Schema::dropIfExists('dhiran_loan_items');
        Schema::dropIfExists('dhiran_loans');
    }
};
