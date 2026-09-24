<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * S3-17. Whether a finished export's ready-notification was delivered, kept
 * apart from whether the export itself succeeded: `status` says the file was
 * generated; these say whether the requester was told. Nullable and unread by
 * the baseline, so safe while it serves.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('report_exports', function (Blueprint $table) {
            if (! Schema::hasColumn('report_exports', 'notified_at')) {
                $table->timestamp('notified_at')->nullable();
            }
            if (! Schema::hasColumn('report_exports', 'notification_error')) {
                $table->string('notification_error', 500)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('report_exports', function (Blueprint $table) {
            $table->dropColumn(['notified_at', 'notification_error']);
        });
    }
};
