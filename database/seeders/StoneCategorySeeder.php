<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Shop;
use App\Models\SubCategory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class StoneCategorySeeder extends Seeder
{
    /**
     * Seed a "Stones" category with 6 common stone sub-categories
     * for every existing shop.
     */
    public function run(): void
    {
        $categoryName = 'Stones';
        $stoneTypes = [
            'Diamond',
            'Ruby',
            'Emerald',
            'Sapphire',
            'Pearl',
            'Opal',
        ];

        $shopIds = Shop::query()->pluck('id');
        if ($shopIds->isEmpty()) {
            $this->command?->warn('StoneCategorySeeder: no shops found, nothing seeded.');
            return;
        }

        $categoryNormalized = $this->normalizeName($categoryName);
        $createdCategories = 0;
        $createdSubCategories = 0;

        foreach ($shopIds as $shopId) {
            $existingCategory = Category::query()
                ->where('shop_id', $shopId)
                ->where('normalized_name', $categoryNormalized)
                ->first();

            $category = $existingCategory ?? Category::query()->create([
                'shop_id' => $shopId,
                'name' => $categoryName,
                'slug' => Str::slug($categoryName),
                'normalized_name' => $categoryNormalized,
            ]);

            if (!$existingCategory) {
                $createdCategories++;
            }

            foreach ($stoneTypes as $stoneName) {
                $stoneNormalized = $this->normalizeName($stoneName);

                $existingSubCategory = SubCategory::query()
                    ->where('shop_id', $shopId)
                    ->where('category_id', $category->id)
                    ->where('normalized_name', $stoneNormalized)
                    ->first();

                if ($existingSubCategory) {
                    continue;
                }

                SubCategory::query()->create([
                    'shop_id' => $shopId,
                    'category_id' => $category->id,
                    'name' => $stoneName,
                    'slug' => Str::slug($stoneName),
                    'normalized_name' => $stoneNormalized,
                ]);

                $createdSubCategories++;
            }
        }

        $this->command?->info(
            "StoneCategorySeeder: created {$createdCategories} categories and {$createdSubCategories} sub-categories."
        );
    }

    private function normalizeName(string $value): string
    {
        return (string) Str::of($value)->squish()->lower();
    }
}

