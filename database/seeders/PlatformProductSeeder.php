<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Seeds the PLATFORM billing-product catalog (platform_products).
 *
 * Mirrors the seed in the create_platform_products_table migration so a plain
 * `db:seed` (without a fresh migrate) still ends up with the six products.
 * Idempotent: updateOrInsert keyed by `code`.
 *
 * NOTE: this is the platform product catalog, NOT the tenant inventory
 * `products` table.
 */
class PlatformProductSeeder extends Seeder
{
    public function run(): void
    {
        $now = Carbon::now();

        $products = [
            ['code' => 'retail',         'name' => 'Retail Billing',  'description' => 'Point of sale, inventory, customers, invoices and reports for a retail jewellery shop.', 'is_active' => DB::raw('true'),  'sort_order' => 10],
            ['code' => 'dhiran',         'name' => 'Dhiran',          'description' => 'Gold loan / pledge management. A standalone product — works with or without a retail shop.',  'is_active' => DB::raw('true'),  'sort_order' => 20],
            ['code' => 'manufacturing',  'name' => 'Manufacturing',   'description' => 'Karigar workflow, gold-lot inventory and manufacturing for a making unit.',               'is_active' => DB::raw('true'),  'sort_order' => 30],
            ['code' => 'crm',            'name' => 'CRM',             'description' => 'Customer relationship and outreach tools. Coming soon.',                                   'is_active' => DB::raw('false'), 'sort_order' => 40],
            ['code' => 'analytics',      'name' => 'Analytics',       'description' => 'Advanced business analytics and dashboards. Coming soon.',                                 'is_active' => DB::raw('false'), 'sort_order' => 50],
            ['code' => 'mobile_premium', 'name' => 'Mobile Premium',  'description' => 'Premium mobile-app features. Coming soon.',                                                'is_active' => DB::raw('false'), 'sort_order' => 60],
        ];

        foreach ($products as $product) {
            $product['created_at'] = $now;
            $product['updated_at'] = $now;

            DB::table('platform_products')->updateOrInsert(
                ['code' => $product['code']],
                $product
            );
        }

        $this->command?->info('Seeded ' . count($products) . ' platform products.');
    }
}
