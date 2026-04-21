<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

class PlanSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $now = Carbon::now();

        $plans = [
            [
                'code' => 'retailer_monthly',
                'name' => 'Retailer Monthly',
                'price_monthly' => 1999.00,
                'price_yearly' => null,
                'trial_days' => 0,
                'grace_days' => 7,
                'downgrade_to_read_only_on_due' => \DB::raw('true'),
                'is_active' => \DB::raw('true'),
                'features' => json_encode([
                    "pos" => true,
                    "inventory" => true,
                    "customers" => true,
                    "repairs" => true,
                    "invoices" => true,
                    "reports" => true,
                    "vendors" => true,
                    "schemes" => true,
                    "loyalty" => true,
                    "installments" => true,
                    "reorder_alerts" => true,
                    "tag_printing" => true,
                    "whatsapp_catalog" => true,
                    "bulk_imports" => true,
                    "staff_limit" => 5,
                    "max_items" => 2000
                ]),
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'code' => 'retailer_yearly',
                'name' => 'Retailer Yearly',
                'price_monthly' => 1666.00,
                'price_yearly' => 19999.00,
                'trial_days' => 7,
                'grace_days' => 14,
                'downgrade_to_read_only_on_due' => \DB::raw('true'),
                'is_active' => \DB::raw('true'),
                'features' => json_encode([
                    "pos" => true,
                    "inventory" => true,
                    "customers" => true,
                    "repairs" => true,
                    "invoices" => true,
                    "reports" => true,
                    "vendors" => true,
                    "schemes" => true,
                    "loyalty" => true,
                    "installments" => true,
                    "reorder_alerts" => true,
                    "tag_printing" => true,
                    "whatsapp_catalog" => true,
                    "bulk_imports" => true,
                    "staff_limit" => 10,
                    "max_items" => 5000
                ]),
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'code' => 'manufacturer_monthly',
                'name' => 'Manufacturer Monthly',
                'price_monthly' => 2999.00,
                'price_yearly' => null,
                'trial_days' => 0,
                'grace_days' => 7,
                'downgrade_to_read_only_on_due' => \DB::raw('true'),
                'is_active' => \DB::raw('true'),
                'features' => json_encode([
                    "pos" => true,
                    "inventory" => true,
                    "gold_inventory" => true,
                    "manufacturing" => true,
                    "customers" => true,
                    "customer_gold" => true,
                    "repairs" => true,
                    "invoices" => true,
                    "reports" => true,
                    "exchange" => true,
                    "bulk_imports" => true,
                    "public_catalog" => true,
                    "staff_limit" => 5,
                    "max_items" => 3000
                ]),
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'code' => 'manufacturer_yearly',
                'name' => 'Manufacturer Yearly',
                'price_monthly' => 2499.00,
                'price_yearly' => 29999.00,
                'trial_days' => 7,
                'grace_days' => 14,
                'downgrade_to_read_only_on_due' => \DB::raw('true'),
                'is_active' => \DB::raw('true'),
                'features' => json_encode([
                    "pos" => true,
                    "inventory" => true,
                    "gold_inventory" => true,
                    "manufacturing" => true,
                    "customers" => true,
                    "customer_gold" => true,
                    "repairs" => true,
                    "invoices" => true,
                    "reports" => true,
                    "exchange" => true,
                    "bulk_imports" => true,
                    "public_catalog" => true,
                    "staff_limit" => 15,
                    "max_items" => -1
                ]),
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ];

        foreach ($plans as $plan) {
            // Clean out the raw DB expressions for the matching part of updateOrInsert
            $matchData = ['code' => $plan['code']];
            DB::table('plans')->updateOrInsert(
                $matchData,
                $plan
            );
        }
    }
}
