<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('schemes')) {
            Schema::table('schemes', function (Blueprint $table): void {
                if (!Schema::hasColumn('schemes', 'auto_apply')) {
                    $table->boolean('auto_apply')->default(false)->after('is_active');
                }

                if (!Schema::hasColumn('schemes', 'priority')) {
                    $table->integer('priority')->default(100)->after('auto_apply');
                }

                if (!Schema::hasColumn('schemes', 'stackable')) {
                    $table->boolean('stackable')->default(false)->after('priority');
                }

                if (!Schema::hasColumn('schemes', 'applies_to')) {
                    $table->enum('applies_to', ['all_items', 'category', 'sub_category'])->default('all_items')->after('stackable');
                }

                if (!Schema::hasColumn('schemes', 'applies_to_value')) {
                    $table->string('applies_to_value')->nullable()->after('applies_to');
                }

                if (!Schema::hasColumn('schemes', 'max_discount_amount')) {
                    $table->decimal('max_discount_amount', 12, 2)->nullable()->after('discount_value');
                }

                if (!Schema::hasColumn('schemes', 'max_uses_per_customer')) {
                    $table->unsignedInteger('max_uses_per_customer')->nullable()->after('max_discount_amount');
                }

                if (!Schema::hasColumn('schemes', 'usage_count')) {
                    $table->unsignedBigInteger('usage_count')->default(0)->after('max_uses_per_customer');
                }
            });

            Schema::table('schemes', function (Blueprint $table): void {
                $table->index(['shop_id', 'type', 'is_active', 'auto_apply', 'priority'], 'schemes_offer_lookup_idx');
            });
        }

        if (Schema::hasTable('scheme_enrollments')) {
            Schema::table('scheme_enrollments', function (Blueprint $table): void {
                if (!Schema::hasColumn('scheme_enrollments', 'terms_accepted_at')) {
                    $table->timestamp('terms_accepted_at')->nullable()->after('start_date');
                }

                if (!Schema::hasColumn('scheme_enrollments', 'terms_version')) {
                    $table->string('terms_version', 64)->nullable()->after('terms_accepted_at');
                }

                if (!Schema::hasColumn('scheme_enrollments', 'redeemed_amount')) {
                    $table->decimal('redeemed_amount', 12, 2)->default(0)->after('total_paid');
                }

                if (!Schema::hasColumn('scheme_enrollments', 'redemption_count')) {
                    $table->unsignedInteger('redemption_count')->default(0)->after('redeemed_amount');
                }

                if (!Schema::hasColumn('scheme_enrollments', 'redeemed_at')) {
                    $table->timestamp('redeemed_at')->nullable()->after('maturity_date');
                }

                if (!Schema::hasColumn('scheme_enrollments', 'last_payment_at')) {
                    $table->date('last_payment_at')->nullable()->after('redeemed_at');
                }

                if (!Schema::hasColumn('scheme_enrollments', 'is_bonus_accrued')) {
                    $table->boolean('is_bonus_accrued')->default(false)->after('bonus_amount');
                }

                if (!Schema::hasColumn('scheme_enrollments', 'maturity_bonus_accrued_at')) {
                    $table->timestamp('maturity_bonus_accrued_at')->nullable()->after('is_bonus_accrued');
                }
            });
        }

        if (Schema::hasTable('scheme_payments')) {
            Schema::table('scheme_payments', function (Blueprint $table): void {
                if (!Schema::hasColumn('scheme_payments', 'installment_number')) {
                    $table->unsignedInteger('installment_number')->nullable()->after('payment_date');
                }

                if (!Schema::hasColumn('scheme_payments', 'cash_transaction_id')) {
                    $table->foreignId('cash_transaction_id')
                        ->nullable()
                        ->after('receipt_number')
                        ->constrained('cash_transactions')
                        ->nullOnDelete();
                }
            });

            Schema::table('scheme_payments', function (Blueprint $table): void {
                $table->index(['shop_id', 'payment_date'], 'scheme_payments_shop_date_idx');
            });
        }

        if (!Schema::hasTable('invoice_offer_applications')) {
            Schema::create('invoice_offer_applications', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('shop_id')->constrained('shops')->restrictOnDelete();
                $table->foreignId('invoice_id')->constrained('invoices')->restrictOnDelete();
                $table->foreignId('scheme_id')->constrained('schemes')->restrictOnDelete();
                $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
                $table->string('scheme_name_snapshot');
                $table->string('discount_type', 20);
                $table->decimal('discount_value', 12, 2);
                $table->decimal('discount_amount', 12, 2);
                $table->boolean('auto_applied')->default(false);
                $table->json('rule_snapshot')->nullable();
                $table->timestamp('applied_at');
                $table->timestamps();

                $table->unique('invoice_id', 'invoice_offer_applications_invoice_unique');
                $table->index(['shop_id', 'scheme_id'], 'invoice_offer_applications_shop_scheme_idx');
                $table->index(['shop_id', 'applied_at'], 'invoice_offer_applications_shop_applied_idx');
            });
        }

        if (!Schema::hasTable('scheme_redemptions')) {
            Schema::create('scheme_redemptions', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('shop_id')->constrained('shops')->restrictOnDelete();
                $table->foreignId('scheme_enrollment_id')->constrained('scheme_enrollments')->restrictOnDelete();
                $table->foreignId('invoice_id')->constrained('invoices')->restrictOnDelete();
                $table->foreignId('invoice_payment_id')->nullable()->constrained('invoice_payments')->nullOnDelete();
                $table->decimal('amount', 12, 2);
                $table->decimal('principal_component', 12, 2)->default(0);
                $table->decimal('bonus_component', 12, 2)->default(0);
                $table->timestamp('redeemed_at');
                $table->text('note')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index(['shop_id', 'invoice_id'], 'scheme_redemptions_shop_invoice_idx');
                $table->index(['shop_id', 'scheme_enrollment_id'], 'scheme_redemptions_shop_enrollment_idx');
            });
        }

        if (!Schema::hasTable('scheme_ledger_entries')) {
            Schema::create('scheme_ledger_entries', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('shop_id')->constrained('shops')->restrictOnDelete();
                $table->foreignId('scheme_enrollment_id')->constrained('scheme_enrollments')->restrictOnDelete();
                $table->foreignId('scheme_payment_id')->nullable()->constrained('scheme_payments')->nullOnDelete();
                $table->foreignId('scheme_redemption_id')->nullable()->constrained('scheme_redemptions')->nullOnDelete();
                $table->string('entry_type', 40);
                $table->enum('direction', ['credit', 'debit']);
                $table->decimal('amount', 12, 2);
                $table->decimal('balance_after', 12, 2);
                $table->string('note')->nullable();
                $table->json('meta')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index(['shop_id', 'scheme_enrollment_id', 'id'], 'scheme_ledger_entries_enrollment_idx');
                $table->index(['shop_id', 'entry_type', 'created_at'], 'scheme_ledger_entries_type_idx');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('scheme_ledger_entries')) {
            Schema::dropIfExists('scheme_ledger_entries');
        }

        if (Schema::hasTable('scheme_redemptions')) {
            Schema::dropIfExists('scheme_redemptions');
        }

        if (Schema::hasTable('invoice_offer_applications')) {
            Schema::dropIfExists('invoice_offer_applications');
        }

        if (Schema::hasTable('scheme_payments')) {
            Schema::table('scheme_payments', function (Blueprint $table): void {
                if (Schema::hasColumn('scheme_payments', 'cash_transaction_id')) {
                    $table->dropConstrainedForeignId('cash_transaction_id');
                }

                if (Schema::hasColumn('scheme_payments', 'installment_number')) {
                    $table->dropColumn('installment_number');
                }
            });

            Schema::table('scheme_payments', function (Blueprint $table): void {
                $table->dropIndex('scheme_payments_shop_date_idx');
            });
        }

        if (Schema::hasTable('scheme_enrollments')) {
            Schema::table('scheme_enrollments', function (Blueprint $table): void {
                $dropColumns = [
                    'terms_accepted_at',
                    'terms_version',
                    'redeemed_amount',
                    'redemption_count',
                    'redeemed_at',
                    'last_payment_at',
                    'is_bonus_accrued',
                    'maturity_bonus_accrued_at',
                ];

                foreach ($dropColumns as $column) {
                    if (Schema::hasColumn('scheme_enrollments', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        if (Schema::hasTable('schemes')) {
            Schema::table('schemes', function (Blueprint $table): void {
                $table->dropIndex('schemes_offer_lookup_idx');
            });

            Schema::table('schemes', function (Blueprint $table): void {
                $dropColumns = [
                    'auto_apply',
                    'priority',
                    'stackable',
                    'applies_to',
                    'applies_to_value',
                    'max_discount_amount',
                    'max_uses_per_customer',
                    'usage_count',
                ];

                foreach ($dropColumns as $column) {
                    if (Schema::hasColumn('schemes', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};
