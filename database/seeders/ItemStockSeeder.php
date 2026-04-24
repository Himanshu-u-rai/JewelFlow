<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Shop;
use App\Models\Product;
use App\Models\MetalLot;
use App\Models\Item;
use App\Models\User;

class ItemStockSeeder extends Seeder
{
    public function run(): void
    {
        $shop = Shop::first();
        if (!$shop) {
            $this->command->error('No shop found. Please create a shop first.');
            return;
        }

        $user = User::where('shop_id', $shop->id)->first();
        if (!$user) {
            $this->command->error('No user found for this shop.');
            return;
        }

        // Step 1: Create Gold Lots (raw material)
        $this->command->info('Creating gold lots...');
        
        $lots = [
            [
                'source' => 'Initial Stock - 24K Pure Gold',
                'purity' => 24,
                'fine_weight_total' => 500.000,
                'fine_weight_remaining' => 500.000,
                'cost_per_fine_gram' => 6500.00,
            ],
            [
                'source' => 'Purchase from Supplier ABC',
                'purity' => 24,
                'fine_weight_total' => 300.000,
                'fine_weight_remaining' => 300.000,
                'cost_per_fine_gram' => 6550.00,
            ],
            [
                'source' => 'Customer Exchange Gold',
                'purity' => 22,
                'fine_weight_total' => 150.000,
                'fine_weight_remaining' => 150.000,
                'cost_per_fine_gram' => 6000.00,
            ],
        ];

        $createdLots = [];
        foreach ($lots as $lotData) {
            $lot = MetalLot::create(array_merge($lotData, ['shop_id' => $shop->id]));
            $createdLots[] = $lot;
        }

        $this->command->info('Created ' . count($createdLots) . ' gold lots with 950g total fine gold');

        // Step 2: Get products to create items from
        $products = Product::where('shop_id', $shop->id)->with(['category', 'subCategory'])->get();
        
        if ($products->isEmpty()) {
            $this->command->error('No products found. Run ProductCatalogSeeder first.');
            return;
        }

        // Step 3: Create Items (physical stock)
        $this->command->info('Creating items/stock...');
        
        $itemCount = 0;
        $barcodeCounter = 1;
        
        // Create 2-3 items for first 20 products
        $productsToStock = $products->take(20);
        
        foreach ($productsToStock as $product) {
            // Random number of pieces (1-3)
            $pieces = rand(1, 3);
            
            for ($i = 0; $i < $pieces; $i++) {
                // Select a lot with enough gold
                $lot = $this->selectLotWithGold($createdLots, $product->approx_weight ?? 10);
                if (!$lot) {
                    continue; // Skip if no lot has enough gold
                }
                
                // Generate barcode
                $barcode = 'JF-' . date('Y') . '-' . str_pad($barcodeCounter++, 4, '0', STR_PAD_LEFT);
                
                // Calculate weights with slight variation
                $grossWeight = ($product->approx_weight ?? 10) * (0.9 + (rand(0, 20) / 100)); // ±10% variation
                $stoneWeight = ($product->default_stone > 0) ? rand(1, 10) / 10 : 0; // 0.1-1g stone if has stone
                $netMetal = $grossWeight - $stoneWeight;
                $purity = $product->default_purity ?? 22;
                $wastagePercent = rand(2, 5) / 10; // 0.2% - 0.5% wastage
                
                // Calculate fine gold needed
                $fineRequired = $netMetal * ($purity / 24);
                $wastage = $fineRequired * ($wastagePercent / 100);
                $totalFineNeeded = $fineRequired + $wastage;
                
                // Check if lot has enough
                if ($lot->fine_weight_remaining < $totalFineNeeded) {
                    continue;
                }
                
                // Calculate costs
                $goldCost = $totalFineNeeded * $lot->cost_per_fine_gram;
                $makingCharges = $product->default_making ?? rand(300, 800);
                $stoneCharges = $product->default_stone ?? 0;
                $totalCost = $goldCost + $makingCharges + $stoneCharges;
                
                // Create the item
                $item = Item::create([
                    'shop_id' => $shop->id,
                    'barcode' => $barcode,
                    'design' => $product->name,
                    'category' => $product->category->name ?? 'Uncategorized',
                    'sub_category' => $product->subCategory->name ?? null,
                    'gross_weight' => round($grossWeight, 3),
                    'stone_weight' => round($stoneWeight, 3),
                    'net_metal_weight' => round($netMetal, 3),
                    'purity' => $purity,
                    'metal_lot_id' => $lot->id,
                    'wastage' => round($wastage, 6),
                    'making_charges' => $makingCharges,
                    'stone_charges' => $stoneCharges,
                    'cost_price' => round($totalCost, 2),
                    'status' => 'in_stock',
                    'product_id' => $product->id,
                ]);
                
                // Deduct gold from lot
                $lot->fine_weight_remaining -= $totalFineNeeded;
                $lot->save();
                
                $itemCount++;
            }
        }
        
        $this->command->info("Created {$itemCount} items in stock!");
        
        // Show summary
        $remainingGold = 0;
        foreach ($createdLots as $lot) {
            $lot->refresh();
            $remainingGold += $lot->fine_weight_remaining;
        }
        
        $totalGoldUsed = 950 - $remainingGold;
        $this->command->info("Total fine gold used: " . number_format($totalGoldUsed, 3) . "g");
        $this->command->info("Remaining gold in lots: " . number_format($remainingGold, 3) . "g");
    }
    
    private function selectLotWithGold(array &$lots, float $approxWeight): ?MetalLot
    {
        // Estimate fine gold needed (assuming 22K purity)
        $estimatedFine = $approxWeight * (22 / 24) * 1.01; // +1% for wastage
        
        foreach ($lots as $lot) {
            $lot->refresh(); // Get latest remaining value
            if ($lot->fine_weight_remaining >= $estimatedFine) {
                return $lot;
            }
        }
        
        return null;
    }
}
