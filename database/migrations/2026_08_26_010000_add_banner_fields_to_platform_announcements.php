<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Admin-editable banners + cross-promo override (announcements).
 *
 * platform_announcements already carries title/body/type/target/expiry/dismiss.
 * This adds the few fields a richer "offers/deals" banner and an editable
 * cross-promo toast need:
 *  - cta_label / cta_url — an optional call-to-action button.
 *  - realm — optional 'erp' | 'dhiran' targeting so a message can be scoped to one
 *    product surface (null = both). Sits alongside the existing target/edition.
 *
 * The `type` column stays a plain string; new values 'banner' (big offers/deals
 * banner) and 'cross_promo' (overrides the product cross-promo toast) are now
 * allowed by the controller. Additive + reversible; existing rows untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_announcements', function (Blueprint $table) {
            $table->string('cta_label', 80)->nullable()->after('body');
            $table->string('cta_url', 2048)->nullable()->after('cta_label');
            $table->string('realm', 20)->nullable()->after('target_value');
        });
    }

    public function down(): void
    {
        Schema::table('platform_announcements', function (Blueprint $table) {
            $table->dropColumn(['cta_label', 'cta_url', 'realm']);
        });
    }
};
