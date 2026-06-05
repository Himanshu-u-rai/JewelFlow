<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reporting spine — Phase 0 (REPORT_EXPORT_IMPLEMENTATION_PLAN.md §0.2, frozen §8/§21).
 *
 * Shop-wide named presets ({filter scope + profile + column selection + format}).
 * `scope`/`owner_id` are shipped now but only `shop` is written/exposed —
 * the schema is user-scope-ready for later without a migration (frozen §21).
 * A preset never elevates privileges: the sensitive gate is re-checked at
 * export time for the running user (frozen §8, §21 guardrail).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reporting_presets', function (Blueprint $table) {
            $table->id();

            $table->foreignId('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->string('name');
            $table->string('report_key', 64);

            $table->string('profile', 64)->nullable();
            $table->json('columns')->nullable();   // selected optional/sensitive column keys
            $table->json('filters')->nullable();   // saved filter scope
            $table->string('format', 16)->nullable();

            // User-scope-ready (frozen §21) — only 'shop' used today.
            $table->string('scope', 16)->default('shop'); // shop|user
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['shop_id', 'report_key', 'name']);
            $table->index(['shop_id', 'report_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reporting_presets');
    }
};
