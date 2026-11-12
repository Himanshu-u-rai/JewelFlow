<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Owner 2026-11-12, Dhiran root-cause fixes:
 *  - interest_capitalised: for compound loans, the interest already unpaid at the last month end (monthly rest). It is
 *    the only state compound interest needs to compound at month ends, so the result no longer depends on how often
 *    interest is brought up to date.
 *  - forfeiture_deadline: the date printed on the forfeiture notice. Forfeiture waits for it even if the shop's notice
 *    period setting changes later. Existing notices get sent date + the shop's current notice days (what they printed).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dhiran_loans', function (Blueprint $table) {
            $table->decimal('interest_capitalised', 14, 2)->default(0);
            $table->date('forfeiture_deadline')->nullable();
        });

        DB::statement(<<<'SQL'
            UPDATE dhiran_loans l
            SET forfeiture_deadline = (l.forfeiture_notice_sent_at::date + COALESCE(s.forfeiture_notice_days, 0))
            FROM dhiran_settings s
            WHERE s.shop_id = l.shop_id AND l.forfeiture_notice_sent_at IS NOT NULL AND l.forfeiture_deadline IS NULL
        SQL);
    }

    public function down(): void
    {
        Schema::table('dhiran_loans', function (Blueprint $table) {
            $table->dropColumn(['interest_capitalised', 'forfeiture_deadline']);
        });
    }
};
