<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dhiran_loans', function (Blueprint $table): void {
            // Actor who executed the forfeiture. Nullable because historical
            // rows predate this column and forfeiture may run in a job/CLI
            // context where auth()->id() falls back to the loan creator.
            $table->foreignId('forfeited_by')
                ->nullable()
                ->after('forfeited_at')
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('dhiran_loans', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('forfeited_by');
        });
    }
};
