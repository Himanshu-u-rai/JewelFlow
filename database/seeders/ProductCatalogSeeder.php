<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Category;
use App\Models\SubCategory;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

class ProductCatalogSeeder extends Seeder
{
    public function run(): void
    {
        // Get the first shop (or you can specify shop_id)
        $shopId = DB::table('shops')->first()->id ?? 1;

        // Define categories with their sub-categories
        $categoriesData = [
            'Necklaces' => [
                'Traditional Necklace',
                'Choker Necklace',
                'Long Necklace',
                'Bridal Necklace',
                'Temple Necklace',
                'Kundan Necklace',
            ],
            'Chains' => [
                'Rope Chain',
                'Box Chain',
                'Cable Chain',
                'Figaro Chain',
                'Snake Chain',
                'Curb Chain',
            ],
            'Bangles' => [
                'Plain Bangle',
                'Kada Bangle',
                'Stone Bangle',
                'Meenakari Bangle',
                'Antique Bangle',
                'Designer Bangle',
            ],
            'Rings' => [
                'Engagement Ring',
                'Wedding Band',
                'Cocktail Ring',
                'Statement Ring',
                'Stackable Ring',
                'Solitaire Ring',
            ],
            'Earrings' => [
                'Stud Earrings',
                'Jhumka Earrings',
                'Hoop Earrings',
                'Drop Earrings',
                'Chandbali Earrings',
                'Ear Cuff',
            ],
            'Pendants' => [
                'Religious Pendant',
                'Heart Pendant',
                'Initial Pendant',
                'Gemstone Pendant',
                'Locket Pendant',
                'Designer Pendant',
            ],
            'Bracelets' => [
                'Chain Bracelet',
                'Charm Bracelet',
                'Cuff Bracelet',
                'Tennis Bracelet',
                'Bangle Bracelet',
                'Link Bracelet',
            ],
            'Mangalsutra' => [
                'Traditional Mangalsutra',
                'Modern Mangalsutra',
                'Diamond Mangalsutra',
                'Short Mangalsutra',
                'Long Mangalsutra',
                'Daily Wear Mangalsutra',
            ],
            'Anklets' => [
                'Traditional Anklet',
                'Modern Anklet',
                'Charm Anklet',
                'Chain Anklet',
                'Beaded Anklet',
                'Designer Anklet',
            ],
            'Nose Pins' => [
                'Stud Nose Pin',
                'Ring Nose Pin',
                'Nath',
                'Diamond Nose Pin',
                'Pearl Nose Pin',
                'Designer Nose Pin',
            ],
        ];

        // Create categories and sub-categories
        $categories = [];
        $subCategories = [];

        foreach ($categoriesData as $categoryName => $subs) {
            $category = Category::create([
                'shop_id' => $shopId,
                'name' => $categoryName,
            ]);
            $categories[$categoryName] = $category;

            foreach ($subs as $subName) {
                $subCategory = SubCategory::create([
                    'shop_id' => $shopId,
                    'category_id' => $category->id,
                    'name' => $subName,
                ]);
                $subCategories[$categoryName][] = $subCategory;
            }
        }

        // Product templates with realistic data
        $products = [
            // NECKLACES (10 products)
            ['name' => 'Royal Temple Necklace', 'category' => 'Necklaces', 'sub_index' => 4, 'purity' => 22, 'weight' => 45.500, 'making' => 8500, 'stone' => 2500, 'notes' => 'Traditional South Indian temple design with Lakshmi motifs. Handcrafted with intricate detailing.'],
            ['name' => 'Kundan Bridal Set', 'category' => 'Necklaces', 'sub_index' => 5, 'purity' => 22, 'weight' => 65.000, 'making' => 15000, 'stone' => 12000, 'notes' => 'Exquisite bridal necklace with kundan work and precious stones. Complete wedding set.'],
            ['name' => 'Diamond Choker', 'category' => 'Necklaces', 'sub_index' => 1, 'purity' => 18, 'weight' => 28.750, 'making' => 12000, 'stone' => 35000, 'notes' => 'Elegant diamond studded choker with VS clarity diamonds. Perfect for parties.'],
            ['name' => 'Antique Rani Haar', 'category' => 'Necklaces', 'sub_index' => 2, 'purity' => 22, 'weight' => 85.000, 'making' => 18000, 'stone' => 5000, 'notes' => 'Long traditional rani haar with antique finish. Three-layer design.'],
            ['name' => 'Pearl Cluster Necklace', 'category' => 'Necklaces', 'sub_index' => 0, 'purity' => 22, 'weight' => 32.500, 'making' => 6500, 'stone' => 8000, 'notes' => 'Beautiful pearl cluster design with gold links. South sea pearls.'],
            ['name' => 'Meenakari Bridal Necklace', 'category' => 'Necklaces', 'sub_index' => 3, 'purity' => 22, 'weight' => 55.000, 'making' => 14000, 'stone' => 3500, 'notes' => 'Rajasthani meenakari work with vibrant colors. Traditional bridal piece.'],
            ['name' => 'Contemporary Gold Necklace', 'category' => 'Necklaces', 'sub_index' => 0, 'purity' => 18, 'weight' => 18.250, 'making' => 4500, 'stone' => 0, 'notes' => 'Modern minimalist design for daily wear. Lightweight and elegant.'],
            ['name' => 'Polki Diamond Necklace', 'category' => 'Necklaces', 'sub_index' => 5, 'purity' => 22, 'weight' => 48.000, 'making' => 16000, 'stone' => 45000, 'notes' => 'Uncut polki diamonds in traditional setting. Heritage piece.'],
            ['name' => 'Layered Chain Necklace', 'category' => 'Necklaces', 'sub_index' => 2, 'purity' => 22, 'weight' => 22.500, 'making' => 5000, 'stone' => 0, 'notes' => 'Triple layer chain necklace with varying lengths. Trendy design.'],
            ['name' => 'Emerald Statement Necklace', 'category' => 'Necklaces', 'sub_index' => 0, 'purity' => 22, 'weight' => 42.000, 'making' => 9500, 'stone' => 28000, 'notes' => 'Colombian emeralds in gold setting. Statement piece for occasions.'],

            // CHAINS (10 products)
            ['name' => 'Classic Rope Chain 22"', 'category' => 'Chains', 'sub_index' => 0, 'purity' => 22, 'weight' => 12.500, 'making' => 1800, 'stone' => 0, 'notes' => '22 inch rope chain, 2.5mm thickness. Ideal for pendants.'],
            ['name' => 'Heavy Box Chain 24"', 'category' => 'Chains', 'sub_index' => 1, 'purity' => 22, 'weight' => 25.000, 'making' => 3200, 'stone' => 0, 'notes' => '24 inch box chain for men. 4mm thickness, sturdy clasp.'],
            ['name' => 'Delicate Cable Chain 18"', 'category' => 'Chains', 'sub_index' => 2, 'purity' => 18, 'weight' => 5.500, 'making' => 1200, 'stone' => 0, 'notes' => 'Lightweight 18 inch cable chain for ladies. 1.5mm thickness.'],
            ['name' => 'Italian Figaro Chain', 'category' => 'Chains', 'sub_index' => 3, 'purity' => 22, 'weight' => 18.750, 'making' => 2800, 'stone' => 0, 'notes' => 'Imported Italian design figaro chain. 22 inches, alternating links.'],
            ['name' => 'Snake Chain Diamond Cut', 'category' => 'Chains', 'sub_index' => 4, 'purity' => 22, 'weight' => 8.250, 'making' => 1500, 'stone' => 0, 'notes' => 'Diamond cut snake chain with brilliant shine. 20 inches.'],
            ['name' => 'Cuban Curb Chain', 'category' => 'Chains', 'sub_index' => 5, 'purity' => 22, 'weight' => 35.000, 'making' => 4500, 'stone' => 0, 'notes' => 'Heavy Cuban curb chain for men. 24 inches, 6mm width.'],
            ['name' => 'Bismark Chain', 'category' => 'Chains', 'sub_index' => 0, 'purity' => 22, 'weight' => 22.000, 'making' => 3500, 'stone' => 0, 'notes' => 'Traditional bismark pattern chain. 22 inches, premium finish.'],
            ['name' => 'Twisted Rope Chain', 'category' => 'Chains', 'sub_index' => 0, 'purity' => 18, 'weight' => 15.500, 'making' => 2200, 'stone' => 0, 'notes' => 'Twisted rope design with extra shine. 20 inches.'],
            ['name' => 'Franco Chain Heavy', 'category' => 'Chains', 'sub_index' => 1, 'purity' => 22, 'weight' => 42.000, 'making' => 5500, 'stone' => 0, 'notes' => 'Heavy franco chain for men. 26 inches, bold look.'],
            ['name' => 'Wheat Chain Ladies', 'category' => 'Chains', 'sub_index' => 2, 'purity' => 22, 'weight' => 6.750, 'making' => 1100, 'stone' => 0, 'notes' => 'Delicate wheat chain design. 16 inches, perfect for pendants.'],

            // BANGLES (10 products)
            ['name' => 'Traditional Kada Set', 'category' => 'Bangles', 'sub_index' => 1, 'purity' => 22, 'weight' => 48.000, 'making' => 7500, 'stone' => 0, 'notes' => 'Pair of traditional kadas with intricate carving. Size 2.6'],
            ['name' => 'Diamond Studded Bangle', 'category' => 'Bangles', 'sub_index' => 2, 'purity' => 18, 'weight' => 18.500, 'making' => 8000, 'stone' => 25000, 'notes' => 'Single bangle with channel set diamonds. Size 2.4'],
            ['name' => 'Meenakari Bangle Set', 'category' => 'Bangles', 'sub_index' => 3, 'purity' => 22, 'weight' => 65.000, 'making' => 12000, 'stone' => 0, 'notes' => 'Set of 4 meenakari bangles with Rajasthani design. Size 2.6'],
            ['name' => 'Antique Finish Kada', 'category' => 'Bangles', 'sub_index' => 4, 'purity' => 22, 'weight' => 28.500, 'making' => 5500, 'stone' => 0, 'notes' => 'Single antique finish kada with temple motifs. Adjustable size.'],
            ['name' => 'Plain Gold Bangle Pair', 'category' => 'Bangles', 'sub_index' => 0, 'purity' => 22, 'weight' => 32.000, 'making' => 3800, 'stone' => 0, 'notes' => 'Classic plain gold bangles. Set of 2, size 2.4'],
            ['name' => 'Ruby Stone Bangles', 'category' => 'Bangles', 'sub_index' => 2, 'purity' => 22, 'weight' => 42.500, 'making' => 8500, 'stone' => 15000, 'notes' => 'Ruby studded bangles with floral design. Set of 2.'],
            ['name' => 'Designer Openable Kada', 'category' => 'Bangles', 'sub_index' => 5, 'purity' => 22, 'weight' => 35.000, 'making' => 6800, 'stone' => 0, 'notes' => 'Modern openable kada with geometric patterns. One size fits all.'],
            ['name' => 'Kundan Work Bangles', 'category' => 'Bangles', 'sub_index' => 5, 'purity' => 22, 'weight' => 55.500, 'making' => 11000, 'stone' => 8000, 'notes' => 'Traditional kundan work bangles. Set of 4, size 2.6'],
            ['name' => 'Twisted Wire Bangles', 'category' => 'Bangles', 'sub_index' => 0, 'purity' => 22, 'weight' => 24.000, 'making' => 4200, 'stone' => 0, 'notes' => 'Twisted wire design bangles. Set of 6, lightweight.'],
            ['name' => 'Pearl Bangle Single', 'category' => 'Bangles', 'sub_index' => 2, 'purity' => 18, 'weight' => 15.250, 'making' => 4500, 'stone' => 6000, 'notes' => 'Single bangle with South sea pearls. Size 2.4'],

            // RINGS (10 products)
            ['name' => 'Solitaire Engagement Ring', 'category' => 'Rings', 'sub_index' => 0, 'purity' => 18, 'weight' => 4.500, 'making' => 3500, 'stone' => 85000, 'notes' => '1 carat solitaire diamond, VS1 clarity, F color. Six prong setting.'],
            ['name' => 'Classic Wedding Band Men', 'category' => 'Rings', 'sub_index' => 1, 'purity' => 22, 'weight' => 8.250, 'making' => 1500, 'stone' => 0, 'notes' => 'Plain gold wedding band for men. 5mm width, comfort fit.'],
            ['name' => 'Diamond Eternity Band', 'category' => 'Rings', 'sub_index' => 1, 'purity' => 18, 'weight' => 3.750, 'making' => 2800, 'stone' => 42000, 'notes' => 'Full eternity band with round diamonds. 2mm width.'],
            ['name' => 'Cocktail Statement Ring', 'category' => 'Rings', 'sub_index' => 2, 'purity' => 22, 'weight' => 12.500, 'making' => 4500, 'stone' => 18000, 'notes' => 'Bold cocktail ring with emerald center stone. Party wear.'],
            ['name' => 'Three Stone Ring', 'category' => 'Rings', 'sub_index' => 0, 'purity' => 18, 'weight' => 5.250, 'making' => 3200, 'stone' => 65000, 'notes' => 'Three stone diamond ring symbolizing past, present, future.'],
            ['name' => 'Stackable Gold Bands', 'category' => 'Rings', 'sub_index' => 4, 'purity' => 22, 'weight' => 6.000, 'making' => 2400, 'stone' => 0, 'notes' => 'Set of 3 stackable thin gold bands. Mix and match style.'],
            ['name' => 'Ruby Halo Ring', 'category' => 'Rings', 'sub_index' => 3, 'purity' => 22, 'weight' => 6.750, 'making' => 3800, 'stone' => 22000, 'notes' => 'Burma ruby center with diamond halo. Stunning design.'],
            ['name' => 'Mens Diamond Ring', 'category' => 'Rings', 'sub_index' => 3, 'purity' => 22, 'weight' => 10.500, 'making' => 3500, 'stone' => 15000, 'notes' => 'Bold mens ring with scattered diamonds. Size 22.'],
            ['name' => 'Vintage Art Deco Ring', 'category' => 'Rings', 'sub_index' => 2, 'purity' => 18, 'weight' => 5.500, 'making' => 4200, 'stone' => 28000, 'notes' => 'Art deco inspired design with sapphire and diamonds.'],
            ['name' => 'Simple Solitaire Band', 'category' => 'Rings', 'sub_index' => 5, 'purity' => 18, 'weight' => 3.250, 'making' => 2500, 'stone' => 45000, 'notes' => '0.5 carat solitaire in bezel setting. Minimalist design.'],

            // EARRINGS (10 products)
            ['name' => 'Diamond Stud Earrings', 'category' => 'Earrings', 'sub_index' => 0, 'purity' => 18, 'weight' => 2.500, 'making' => 1800, 'stone' => 35000, 'notes' => 'Classic diamond studs, 0.5 carat total. Four prong setting.'],
            ['name' => 'Traditional Jhumkas', 'category' => 'Earrings', 'sub_index' => 1, 'purity' => 22, 'weight' => 18.500, 'making' => 5500, 'stone' => 2000, 'notes' => 'Beautiful dome shaped jhumkas with pearl drops. Temple design.'],
            ['name' => 'Gold Hoop Earrings', 'category' => 'Earrings', 'sub_index' => 2, 'purity' => 22, 'weight' => 8.750, 'making' => 2200, 'stone' => 0, 'notes' => 'Classic gold hoops, 30mm diameter. Everyday wear.'],
            ['name' => 'Emerald Drop Earrings', 'category' => 'Earrings', 'sub_index' => 3, 'purity' => 22, 'weight' => 12.250, 'making' => 4500, 'stone' => 18000, 'notes' => 'Pear shaped emerald drops with diamond tops.'],
            ['name' => 'Chandbali Kundan', 'category' => 'Earrings', 'sub_index' => 4, 'purity' => 22, 'weight' => 22.000, 'making' => 7500, 'stone' => 8000, 'notes' => 'Crescent moon shaped chandbali with kundan work. Bridal.'],
            ['name' => 'Diamond Cluster Studs', 'category' => 'Earrings', 'sub_index' => 0, 'purity' => 18, 'weight' => 4.250, 'making' => 2800, 'stone' => 28000, 'notes' => 'Flower shaped diamond cluster studs. Party wear.'],
            ['name' => 'Antique Jhumka Large', 'category' => 'Earrings', 'sub_index' => 1, 'purity' => 22, 'weight' => 28.500, 'making' => 8500, 'stone' => 3500, 'notes' => 'Large statement jhumkas with antique finish. Wedding wear.'],
            ['name' => 'Modern Ear Cuffs', 'category' => 'Earrings', 'sub_index' => 5, 'purity' => 18, 'weight' => 5.500, 'making' => 2500, 'stone' => 12000, 'notes' => 'Trendy ear cuffs with diamonds. No piercing required.'],
            ['name' => 'Pearl Drop Earrings', 'category' => 'Earrings', 'sub_index' => 3, 'purity' => 22, 'weight' => 6.750, 'making' => 2200, 'stone' => 8000, 'notes' => 'South sea pearl drops with gold tops. Elegant design.'],
            ['name' => 'Ruby Stud Earrings', 'category' => 'Earrings', 'sub_index' => 0, 'purity' => 22, 'weight' => 3.500, 'making' => 1500, 'stone' => 15000, 'notes' => 'Burma ruby studs in gold setting. Classic design.'],

            // PENDANTS (10 products)
            ['name' => 'Om Pendant Gold', 'category' => 'Pendants', 'sub_index' => 0, 'purity' => 22, 'weight' => 4.250, 'making' => 1200, 'stone' => 0, 'notes' => 'Religious Om symbol pendant. Detailed carving.'],
            ['name' => 'Diamond Heart Pendant', 'category' => 'Pendants', 'sub_index' => 1, 'purity' => 18, 'weight' => 2.750, 'making' => 1800, 'stone' => 18000, 'notes' => 'Heart shaped pendant with pave diamonds. Gift perfect.'],
            ['name' => 'Initial Letter Pendant', 'category' => 'Pendants', 'sub_index' => 2, 'purity' => 22, 'weight' => 3.500, 'making' => 1500, 'stone' => 0, 'notes' => 'Customizable initial pendant. Script font design.'],
            ['name' => 'Blue Sapphire Pendant', 'category' => 'Pendants', 'sub_index' => 3, 'purity' => 22, 'weight' => 5.250, 'making' => 2500, 'stone' => 22000, 'notes' => 'Ceylon blue sapphire with diamond halo.'],
            ['name' => 'Gold Photo Locket', 'category' => 'Pendants', 'sub_index' => 4, 'purity' => 22, 'weight' => 8.500, 'making' => 2800, 'stone' => 0, 'notes' => 'Oval photo locket with floral engraving. Opens for photo.'],
            ['name' => 'Ganesh Ji Pendant', 'category' => 'Pendants', 'sub_index' => 0, 'purity' => 22, 'weight' => 6.750, 'making' => 2200, 'stone' => 0, 'notes' => 'Lord Ganesha pendant with detailed idol work.'],
            ['name' => 'Infinity Diamond Pendant', 'category' => 'Pendants', 'sub_index' => 5, 'purity' => 18, 'weight' => 2.250, 'making' => 1500, 'stone' => 12000, 'notes' => 'Infinity symbol with diamonds. Modern design.'],
            ['name' => 'Peacock Designer Pendant', 'category' => 'Pendants', 'sub_index' => 5, 'purity' => 22, 'weight' => 9.500, 'making' => 3800, 'stone' => 8000, 'notes' => 'Peacock design with colored stones. Traditional.'],
            ['name' => 'Cross Diamond Pendant', 'category' => 'Pendants', 'sub_index' => 0, 'purity' => 18, 'weight' => 3.750, 'making' => 2000, 'stone' => 15000, 'notes' => 'Christian cross with diamond accents.'],
            ['name' => 'Evil Eye Pendant', 'category' => 'Pendants', 'sub_index' => 5, 'purity' => 22, 'weight' => 2.500, 'making' => 1200, 'stone' => 3000, 'notes' => 'Evil eye protection pendant with blue stone.'],

            // BRACELETS (10 products)
            ['name' => 'Mens Link Bracelet', 'category' => 'Bracelets', 'sub_index' => 5, 'purity' => 22, 'weight' => 35.000, 'making' => 5500, 'stone' => 0, 'notes' => 'Heavy link bracelet for men. 8 inches length.'],
            ['name' => 'Diamond Tennis Bracelet', 'category' => 'Bracelets', 'sub_index' => 3, 'purity' => 18, 'weight' => 12.500, 'making' => 8500, 'stone' => 125000, 'notes' => '5 carat total diamond tennis bracelet. VS clarity.'],
            ['name' => 'Charm Bracelet Ladies', 'category' => 'Bracelets', 'sub_index' => 1, 'purity' => 22, 'weight' => 18.250, 'making' => 4500, 'stone' => 0, 'notes' => 'Chain bracelet with 5 gold charms. Customizable.'],
            ['name' => 'Gold Cuff Bracelet', 'category' => 'Bracelets', 'sub_index' => 2, 'purity' => 22, 'weight' => 28.500, 'making' => 6500, 'stone' => 0, 'notes' => 'Wide cuff bracelet with hammered finish. Adjustable.'],
            ['name' => 'Baby Gold Bracelet', 'category' => 'Bracelets', 'sub_index' => 0, 'purity' => 22, 'weight' => 4.500, 'making' => 1200, 'stone' => 0, 'notes' => 'Delicate baby bracelet with ID plate. Adjustable length.'],
            ['name' => 'Ruby Tennis Bracelet', 'category' => 'Bracelets', 'sub_index' => 3, 'purity' => 22, 'weight' => 15.750, 'making' => 6500, 'stone' => 45000, 'notes' => 'Oval rubies in gold tennis bracelet setting.'],
            ['name' => 'Curb Chain Bracelet', 'category' => 'Bracelets', 'sub_index' => 0, 'purity' => 22, 'weight' => 22.000, 'making' => 3500, 'stone' => 0, 'notes' => 'Classic curb chain bracelet for men. 8.5 inches.'],
            ['name' => 'Pearl Bracelet Gold', 'category' => 'Bracelets', 'sub_index' => 1, 'purity' => 22, 'weight' => 8.500, 'making' => 2800, 'stone' => 12000, 'notes' => 'Pearl and gold bead bracelet. Elegant design.'],
            ['name' => 'Bangle Style Bracelet', 'category' => 'Bracelets', 'sub_index' => 4, 'purity' => 22, 'weight' => 18.000, 'making' => 4200, 'stone' => 0, 'notes' => 'Hinged bangle style bracelet with safety clasp.'],
            ['name' => 'Kids Charm Bracelet', 'category' => 'Bracelets', 'sub_index' => 1, 'purity' => 22, 'weight' => 6.250, 'making' => 1800, 'stone' => 0, 'notes' => 'Cute charm bracelet for kids with animal charms.'],

            // MANGALSUTRA (10 products)
            ['name' => 'Traditional Long Mangalsutra', 'category' => 'Mangalsutra', 'sub_index' => 0, 'purity' => 22, 'weight' => 22.500, 'making' => 4500, 'stone' => 0, 'notes' => 'Traditional double-strand mangalsutra with black beads. 24 inches.'],
            ['name' => 'Diamond Mangalsutra Pendant', 'category' => 'Mangalsutra', 'sub_index' => 2, 'purity' => 18, 'weight' => 8.500, 'making' => 3500, 'stone' => 28000, 'notes' => 'Modern diamond pendant mangalsutra. 18 inches.'],
            ['name' => 'Daily Wear Short Mangalsutra', 'category' => 'Mangalsutra', 'sub_index' => 5, 'purity' => 22, 'weight' => 5.250, 'making' => 1500, 'stone' => 0, 'notes' => 'Simple daily wear mangalsutra. 16 inches, lightweight.'],
            ['name' => 'South Indian Thali Chain', 'category' => 'Mangalsutra', 'sub_index' => 0, 'purity' => 22, 'weight' => 35.000, 'making' => 7500, 'stone' => 0, 'notes' => 'Traditional South Indian thali with gold chain. Temple design.'],
            ['name' => 'Modern Sleek Mangalsutra', 'category' => 'Mangalsutra', 'sub_index' => 1, 'purity' => 18, 'weight' => 6.750, 'making' => 2500, 'stone' => 8000, 'notes' => 'Contemporary design mangalsutra. Office wear friendly.'],
            ['name' => 'Ruby Mangalsutra Set', 'category' => 'Mangalsutra', 'sub_index' => 4, 'purity' => 22, 'weight' => 18.500, 'making' => 5500, 'stone' => 15000, 'notes' => 'Long mangalsutra with ruby pendant and earrings set.'],
            ['name' => 'Layered Gold Mangalsutra', 'category' => 'Mangalsutra', 'sub_index' => 1, 'purity' => 22, 'weight' => 12.500, 'making' => 3800, 'stone' => 0, 'notes' => 'Multi-layered modern mangalsutra. Trendy design.'],
            ['name' => 'Maharashtrian Mangalsutra', 'category' => 'Mangalsutra', 'sub_index' => 0, 'purity' => 22, 'weight' => 15.750, 'making' => 4200, 'stone' => 0, 'notes' => 'Traditional Maharashtrian vati design mangalsutra.'],
            ['name' => 'Solitaire Mangalsutra', 'category' => 'Mangalsutra', 'sub_index' => 2, 'purity' => 18, 'weight' => 4.500, 'making' => 2800, 'stone' => 45000, 'notes' => 'Single solitaire diamond mangalsutra. Elegant.'],
            ['name' => 'Beaded Gold Mangalsutra', 'category' => 'Mangalsutra', 'sub_index' => 3, 'purity' => 22, 'weight' => 8.250, 'making' => 2500, 'stone' => 0, 'notes' => 'Gold beads mangalsutra without black beads. Short length.'],

            // ANKLETS (10 products)
            ['name' => 'Traditional Ghungroo Anklet', 'category' => 'Anklets', 'sub_index' => 0, 'purity' => 22, 'weight' => 25.000, 'making' => 5500, 'stone' => 0, 'notes' => 'Pair of traditional anklets with ghungroo bells. Musical sound.'],
            ['name' => 'Modern Chain Anklet', 'category' => 'Anklets', 'sub_index' => 3, 'purity' => 22, 'weight' => 8.500, 'making' => 2200, 'stone' => 0, 'notes' => 'Simple chain anklet for daily wear. Single piece.'],
            ['name' => 'Diamond Anklet Single', 'category' => 'Anklets', 'sub_index' => 5, 'purity' => 18, 'weight' => 6.250, 'making' => 2800, 'stone' => 15000, 'notes' => 'Delicate anklet with diamond charms. Party wear.'],
            ['name' => 'Charm Anklet Gold', 'category' => 'Anklets', 'sub_index' => 2, 'purity' => 22, 'weight' => 12.500, 'making' => 3500, 'stone' => 0, 'notes' => 'Gold anklet with hanging charms. Adjustable.'],
            ['name' => 'Beaded Anklet Pair', 'category' => 'Anklets', 'sub_index' => 4, 'purity' => 22, 'weight' => 18.750, 'making' => 4200, 'stone' => 0, 'notes' => 'Pair of beaded anklets with gold beads. Traditional.'],
            ['name' => 'Bridal Heavy Anklets', 'category' => 'Anklets', 'sub_index' => 0, 'purity' => 22, 'weight' => 45.000, 'making' => 9500, 'stone' => 5000, 'notes' => 'Heavy bridal anklets with intricate design. Wedding wear.'],
            ['name' => 'Baby Anklet Set', 'category' => 'Anklets', 'sub_index' => 1, 'purity' => 22, 'weight' => 5.500, 'making' => 1500, 'stone' => 0, 'notes' => 'Cute baby anklet pair with small bells. Adjustable.'],
            ['name' => 'Pearl Drop Anklet', 'category' => 'Anklets', 'sub_index' => 5, 'purity' => 22, 'weight' => 8.250, 'making' => 2500, 'stone' => 6000, 'notes' => 'Anklet with pearl drops. Elegant design.'],
            ['name' => 'Kundan Anklet Pair', 'category' => 'Anklets', 'sub_index' => 5, 'purity' => 22, 'weight' => 22.500, 'making' => 6500, 'stone' => 8000, 'notes' => 'Traditional kundan work anklets. Festive wear.'],
            ['name' => 'Simple Gold Anklet', 'category' => 'Anklets', 'sub_index' => 1, 'purity' => 22, 'weight' => 6.000, 'making' => 1800, 'stone' => 0, 'notes' => 'Plain gold anklet for daily wear. Lightweight.'],

            // NOSE PINS (10 products)
            ['name' => 'Diamond Nose Stud', 'category' => 'Nose Pins', 'sub_index' => 3, 'purity' => 18, 'weight' => 0.350, 'making' => 500, 'stone' => 5000, 'notes' => 'Single diamond nose stud. VS clarity, screw back.'],
            ['name' => 'Traditional Nath Large', 'category' => 'Nose Pins', 'sub_index' => 2, 'purity' => 22, 'weight' => 8.500, 'making' => 3500, 'stone' => 2000, 'notes' => 'Large bridal nath with pearls. Clip-on style.'],
            ['name' => 'Gold Ball Nose Pin', 'category' => 'Nose Pins', 'sub_index' => 0, 'purity' => 22, 'weight' => 0.250, 'making' => 300, 'stone' => 0, 'notes' => 'Simple gold ball nose pin. Screw back.'],
            ['name' => 'Pearl Nose Pin', 'category' => 'Nose Pins', 'sub_index' => 4, 'purity' => 22, 'weight' => 0.450, 'making' => 400, 'stone' => 1500, 'notes' => 'Single pearl nose pin. Elegant look.'],
            ['name' => 'Ruby Nose Stud', 'category' => 'Nose Pins', 'sub_index' => 5, 'purity' => 22, 'weight' => 0.400, 'making' => 450, 'stone' => 3500, 'notes' => 'Burma ruby nose stud. Rich red color.'],
            ['name' => 'Nose Ring Gold', 'category' => 'Nose Pins', 'sub_index' => 1, 'purity' => 22, 'weight' => 0.550, 'making' => 350, 'stone' => 0, 'notes' => 'Simple gold nose ring. Continuous hoop style.'],
            ['name' => 'Kundan Bridal Nath', 'category' => 'Nose Pins', 'sub_index' => 2, 'purity' => 22, 'weight' => 12.500, 'making' => 4500, 'stone' => 5000, 'notes' => 'Heavy kundan nath with chain. Bridal wear.'],
            ['name' => 'Designer Nose Pin', 'category' => 'Nose Pins', 'sub_index' => 5, 'purity' => 22, 'weight' => 0.650, 'making' => 600, 'stone' => 2000, 'notes' => 'Floral design nose pin with small stone.'],
            ['name' => 'Pressed Nose Stud', 'category' => 'Nose Pins', 'sub_index' => 0, 'purity' => 22, 'weight' => 0.300, 'making' => 350, 'stone' => 0, 'notes' => 'Press fit nose stud. Easy to wear.'],
            ['name' => 'Emerald Nose Pin', 'category' => 'Nose Pins', 'sub_index' => 5, 'purity' => 22, 'weight' => 0.500, 'making' => 550, 'stone' => 4500, 'notes' => 'Colombian emerald nose pin. Green stone.'],
        ];

        // Create products
        $designCodeCounter = 1;
        foreach ($products as $productData) {
            $category = $categories[$productData['category']];
            $subCategory = $subCategories[$productData['category']][$productData['sub_index']];
            
            Product::create([
                'shop_id' => $shopId,
                'name' => $productData['name'],
                'design_code' => 'PRD-' . str_pad($designCodeCounter++, 4, '0', STR_PAD_LEFT),
                'category_id' => $category->id,
                'sub_category_id' => $subCategory->id,
                'default_purity' => $productData['purity'],
                'approx_weight' => $productData['weight'],
                'default_making' => $productData['making'],
                'default_stone' => $productData['stone'],
                'notes' => $productData['notes'],
            ]);
        }

        $this->command->info('Created 10 categories with 60 sub-categories and 100 products!');
    }
}
