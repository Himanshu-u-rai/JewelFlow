<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Widen dhiran_loans.kyc_aadhaar for the masked format (Phase E1).
 *
 * The column was varchar(12), sized for a raw 12-digit Aadhaar. We now store the
 * MASKED form "XXXX-XXXX-1234" (14 chars), so the column must hold ≥14. Widen to
 * 20 for headroom. Additive + safe — no data is changed (live has 0 rows).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE dhiran_loans ALTER COLUMN kyc_aadhaar TYPE varchar(20)');
    }

    public function down(): void
    {
        // Reverting to 12 would truncate the masked value; keep the wider type.
        DB::statement('ALTER TABLE dhiran_loans ALTER COLUMN kyc_aadhaar TYPE varchar(20)');
    }
};
