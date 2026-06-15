<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Seeds ONLY the retailer subscription plans.
 *
 * Deliberately excludes manufacturer_* and dhiran_* plans — this seeder
 * exists so a retailer-only SaaS deployment can be provisioned without
 * exposing the other editions. The full catalogue (all three editions)
 * still lives in PlanSeeder for deployments that need it.
 *
 * Idempotent: updateOrInsert keyed by `code`, so re-running refreshes the
 * two retailer plans in place and never touches other rows.
 */
class RetailerPlanSeeder extends Seeder
{
    public function run(): void
    {
        $now = Carbon::now();

        $plans = [
            [
                'code'                          => 'retailer_monthly',
                'name'                          => 'Retailer Monthly',
                'description'                   => 'Monthly plan for retail jewellery shops.',
                'price_monthly'                 => 1999.00,
                'price_yearly'                  => null,
                'trial_days'                    => 0,
                'grace_days'                    => 7,
                'downgrade_to_read_only_on_due' => DB::raw('true'),
                'is_active'                     => DB::raw('true'),
                'features'                      => json_encode([
                    'pos'             => true,
                    'inventory'       => true,
                    'customers'       => true,
                    'repairs'         => true,
                    'invoices'        => true,
                    'reports'         => true,
                    'vendors'         => true,
                    'schemes'         => true,
                    'loyalty'         => true,
                    'installments'    => true,
                    'reorder_alerts'  => true,
                    'tag_printing'    => true,
                    'whatsapp_catalog' => true,
                    'bulk_imports'    => true,
                    'staff_limit'     => 5,
                    'max_items'       => 2000,
                ]),
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'code'                          => 'retailer_yearly',
                'name'                          => 'Retailer Yearly',
                'description'                   => 'Yearly plan for retail jewellery shops — best value.',
                'price_monthly'                 => 1666.00,
                'price_yearly'                  => 19999.00,
                'trial_days'                    => 0,
                'grace_days'                    => 14,
                'downgrade_to_read_only_on_due' => DB::raw('true'),
                'is_active'                     => DB::raw('true'),
                'features'                      => json_encode([
                    'pos'             => true,
                    'inventory'       => true,
                    'customers'       => true,
                    'repairs'         => true,
                    'invoices'        => true,
                    'reports'         => true,
                    'vendors'         => true,
                    'schemes'         => true,
                    'loyalty'         => true,
                    'installments'    => true,
                    'reorder_alerts'  => true,
                    'tag_printing'    => true,
                    'whatsapp_catalog' => true,
                    'bulk_imports'    => true,
                    'staff_limit'     => 10,
                    'max_items'       => 5000,
                ]),
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ];

        // Link both retailer plans to the 'retail' platform product (null-safe
        // if products aren't seeded yet; the migration backfills in that case).
        $retailProductId = DB::table('platform_products')->where('code', 'retail')->value('id');

        foreach ($plans as $plan) {
            $plan['platform_product_id'] = $retailProductId;

            DB::table('plans')->updateOrInsert(
                ['code' => $plan['code']],
                $plan
            );
        }

        $this->command?->info('Seeded ' . count($plans) . ' retailer plan(s): retailer_monthly, retailer_yearly.');
    }
}
