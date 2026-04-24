<?php

namespace Database\Seeders;

use App\Models\Customer;
use App\Models\Repair;
use App\Services\BusinessIdentifierService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class RepairSeeder extends Seeder
{
    public function run(): void
    {
        $shopId = (int) (DB::selectOne('SELECT shop_id FROM customers LIMIT 1')?->shop_id ?? 1);

        $customerIds = DB::table('customers')->where('shop_id', $shopId)->pluck('id')->all();
        if (empty($customerIds)) {
            $this->command?->warn("No customers for shop {$shopId} — skipping repair seed.");
            return;
        }

        $items = [
            ['Gold Chain', 'Broken link near clasp, needs soldering'],
            ['Gold Ring', 'Resize from size 6 to 7'],
            ['Bangle', 'Dent on outer edge, needs polishing'],
            ['Earrings', 'Hook replacement'],
            ['Pendant', 'Re-stringing and clasp fix'],
            ['Bracelet', 'Clasp replacement'],
            ['Necklace', 'Chain polishing and rhodium plating'],
            ['Nose Pin', 'Screw-back replacement'],
            ['Toe Ring', 'Resize and polish'],
            ['Mangalsutra', 'Bead re-threading'],
            ['Kada', 'Engraving restoration'],
            ['Anklet', 'Broken chain repair'],
            ['Locket', 'Hinge repair and clean'],
            ['Waist Chain', 'Extension link addition'],
            ['Nath', 'Stone resetting'],
        ];

        $purities = [22, 22, 22, 18, 24, 14, 21, null];
        $statuses = ['pending', 'pending', 'pending', 'in_progress', 'in_progress', 'ready'];

        DB::transaction(function () use ($shopId, $customerIds, $items, $purities, $statuses) {
            for ($i = 0; $i < 25; $i++) {
                $item = $items[array_rand($items)];
                $customerId = $customerIds[array_rand($customerIds)];
                $grossWeight = round(mt_rand(500, 15000) / 1000, 3);
                $estimated = mt_rand(0, 1) ? mt_rand(200, 5000) : null;
                $purity = $purities[array_rand($purities)];
                $status = $statuses[array_rand($statuses)];

                Repair::create([
                    'shop_id'          => $shopId,
                    'customer_id'      => $customerId,
                    'item_description' => $item[0],
                    'description'      => $item[1],
                    'gross_weight'     => $grossWeight,
                    'purity'           => $purity,
                    'estimated_cost'   => $estimated,
                    'status'           => $status,
                    'created_at'       => now()->subDays(mt_rand(0, 30))->subHours(mt_rand(0, 23)),
                ]);
            }
        });

        $this->command?->info("Seeded 25 repairs for shop {$shopId}.");
    }
}
