<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('installment_plans')) {
            return;
        }

        Schema::table('installment_plans', function (Blueprint $table): void {
            if (!Schema::hasColumn('installment_plans', 'principal_amount')) {
                $table->decimal('principal_amount', 12, 2)->default(0)->after('total_amount');
            }
            if (!Schema::hasColumn('installment_plans', 'interest_rate_annual')) {
                $table->decimal('interest_rate_annual', 5, 2)->default(0)->after('down_payment');
            }
            if (!Schema::hasColumn('installment_plans', 'interest_amount')) {
                $table->decimal('interest_amount', 12, 2)->default(0)->after('interest_rate_annual');
            }
            if (!Schema::hasColumn('installment_plans', 'total_payable')) {
                $table->decimal('total_payable', 12, 2)->default(0)->after('interest_amount');
            }
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("
                UPDATE installment_plans
                SET
                    principal_amount = GREATEST(total_amount - down_payment, 0),
                    interest_rate_annual = COALESCE(interest_rate_annual, 0),
                    interest_amount = COALESCE(interest_amount, 0),
                    total_payable = GREATEST(total_amount - down_payment, 0) + COALESCE(interest_amount, 0),
                    remaining_amount = GREATEST(
                        (GREATEST(total_amount - down_payment, 0) + COALESCE(interest_amount, 0))
                        - (COALESCE(emis_paid, 0) * COALESCE(emi_amount, 0)),
                        0
                    )
            ");

            return;
        }

        DB::table('installment_plans')
            ->select([
                'id',
                'total_amount',
                'down_payment',
                'interest_rate_annual',
                'interest_amount',
                'emi_amount',
                'emis_paid',
            ])
            ->orderBy('id')
            ->chunkById(500, function ($plans): void {
                foreach ($plans as $plan) {
                    $principal = max((float) $plan->total_amount - (float) $plan->down_payment, 0);
                    $interestRate = (float) ($plan->interest_rate_annual ?? 0);
                    $interestAmount = (float) ($plan->interest_amount ?? 0);
                    $totalPayable = $principal + $interestAmount;
                    $remainingAmount = max(
                        $totalPayable - ((float) ($plan->emis_paid ?? 0) * (float) ($plan->emi_amount ?? 0)),
                        0
                    );

                    DB::table('installment_plans')
                        ->where('id', $plan->id)
                        ->update([
                            'principal_amount' => round($principal, 2),
                            'interest_rate_annual' => round($interestRate, 2),
                            'interest_amount' => round($interestAmount, 2),
                            'total_payable' => round($totalPayable, 2),
                            'remaining_amount' => round($remainingAmount, 2),
                        ]);
                }
            }, 'id');
    }

    public function down(): void
    {
        if (!Schema::hasTable('installment_plans')) {
            return;
        }

        Schema::table('installment_plans', function (Blueprint $table): void {
            if (Schema::hasColumn('installment_plans', 'total_payable')) {
                $table->dropColumn('total_payable');
            }
            if (Schema::hasColumn('installment_plans', 'interest_amount')) {
                $table->dropColumn('interest_amount');
            }
            if (Schema::hasColumn('installment_plans', 'interest_rate_annual')) {
                $table->dropColumn('interest_rate_annual');
            }
            if (Schema::hasColumn('installment_plans', 'principal_amount')) {
                $table->dropColumn('principal_amount');
            }
        });
    }
};
