<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 of the subscription redesign — the PLATFORM billing-product catalog.
 *
 * This is NOT the tenant inventory `products` table (which holds shop jewellery
 * item templates). This catalog lives in the platform plane alongside `plans`,
 * `platform_invoices`, and `shop_subscriptions`. Each platform product is a
 * billable thing a shop can subscribe to (Retail ERP, Dhiran, Manufacturing,
 * and inactive placeholders for CRM / Analytics / Mobile Premium).
 *
 * A product subscription GRANTS an edition (controls access). The mapping from
 * product code to the existing edition strings lives in
 * App\Models\Platform\PlatformProduct::editionString().
 *
 * Rollback is safe: dropping the table loses no shop access (editions live in
 * shop_editions, which has its own source-tracking columns).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_products', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        $this->seedProducts();
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_products');
    }

    /**
     * Seed the 6 platform products. Only retail / dhiran / manufacturing are
     * active for purchase; crm / analytics / mobile_premium are inactive
     * placeholders kept so plans can reference them when they launch.
     *
     * Idempotent via insertOrIgnore on the UNIQUE (code) so migrate:refresh is safe.
     */
    private function seedProducts(): void
    {
        $now = Carbon::now();

        $products = [
            ['code' => 'retail',         'name' => 'Retail Billing',  'description' => 'Point of sale, inventory, customers, invoices and reports for a retail jewellery shop.', 'is_active' => true,  'sort_order' => 10],
            ['code' => 'dhiran',         'name' => 'Dhiran',          'description' => 'Gold loan / pledge management. A standalone product — works with or without a retail shop.',  'is_active' => true,  'sort_order' => 20],
            ['code' => 'manufacturing',  'name' => 'Manufacturing',   'description' => 'Karigar workflow, gold-lot inventory and manufacturing for a making unit.',               'is_active' => true,  'sort_order' => 30],
            ['code' => 'crm',            'name' => 'CRM',             'description' => 'Customer relationship and outreach tools. Coming soon.',                                   'is_active' => false, 'sort_order' => 40],
            ['code' => 'analytics',      'name' => 'Analytics',       'description' => 'Advanced business analytics and dashboards. Coming soon.',                                 'is_active' => false, 'sort_order' => 50],
            ['code' => 'mobile_premium', 'name' => 'Mobile Premium',  'description' => 'Premium mobile-app features. Coming soon.',                                                'is_active' => false, 'sort_order' => 60],
        ];

        foreach ($products as $product) {
            $product['is_active'] = DB::raw($product['is_active'] ? 'true' : 'false');
            $product['created_at'] = $now;
            $product['updated_at'] = $now;

            DB::table('platform_products')->insertOrIgnore($product);
        }
    }
};
