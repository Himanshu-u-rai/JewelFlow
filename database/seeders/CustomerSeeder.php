<?php

namespace Database\Seeders;

use App\Models\Customer;
use App\Models\Shop;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class CustomerSeeder extends Seeder
{
    public function run(): void
    {
        $shops = Shop::query()->get(['id']);

        if ($shops->isEmpty()) {
            $this->command?->warn('CustomerSeeder: no shops found, nothing seeded.');
            return;
        }

        $profiles = $this->profiles();

        foreach ($shops as $shop) {
            $shopId = (int) $shop->id;

            $existingCount = Customer::withoutTenant()
                ->where('shop_id', $shopId)
                ->count();

            $targetCount = 25;
            if ($existingCount >= $targetCount) {
                $this->command?->info("CustomerSeeder: shop {$shopId} already has {$existingCount} customers (skipped).");
                continue;
            }

            $needed = $targetCount - $existingCount;
            $usedMobiles = Customer::withoutTenant()
                ->where('shop_id', $shopId)
                ->pluck('mobile')
                ->filter()
                ->values()
                ->all();

            for ($i = 0; $i < $needed; $i++) {
                $profile = $profiles[$i % count($profiles)];
                $slot = $existingCount + $i + 1;

                $mobile = $this->nextUniqueMobile($shopId, $slot, $usedMobiles);
                $dob = Carbon::createFromFormat('Y-m-d', $profile['dob']);
                $anniversary = $profile['anniversary']
                    ? Carbon::createFromFormat('Y-m-d', $profile['anniversary'])
                    : null;

                $customer = new Customer();
                $customer->shop_id = $shopId;
                $customer->first_name = $profile['first_name'];
                $customer->last_name = $profile['last_name'];
                $customer->mobile = $mobile;
                $customer->email = strtolower($profile['first_name'] . '.' . $profile['last_name']) . $shopId . '@example.in';
                $customer->address = $profile['address'];
                $customer->date_of_birth = $dob->toDateString();
                $customer->anniversary_date = $anniversary?->toDateString();
                $customer->wedding_date = $anniversary?->toDateString();
                $customer->notes = $profile['notes'];
                $customer->loyalty_points = $profile['loyalty_points'];
                $customer->save();
            }

            $this->command?->info("CustomerSeeder: added {$needed} customers for shop {$shopId}.");
        }
    }

    /**
     * @return array<int, array{
     *  first_name:string,
     *  last_name:string,
     *  dob:string,
     *  anniversary:?string,
     *  address:string,
     *  notes:string,
     *  loyalty_points:int
     * }>
     */
    private function profiles(): array
    {
        return [
            ['first_name' => 'Aarav', 'last_name' => 'Sharma', 'dob' => '1992-04-12', 'anniversary' => '2018-02-21', 'address' => 'Bodakdev, Ahmedabad, Gujarat', 'notes' => 'Prefers lightweight office wear sets.', 'loyalty_points' => 210],
            ['first_name' => 'Vivaan', 'last_name' => 'Patel', 'dob' => '1989-08-06', 'anniversary' => '2015-11-30', 'address' => 'Navrangpura, Ahmedabad, Gujarat', 'notes' => 'Wedding jewellery repeat buyer.', 'loyalty_points' => 430],
            ['first_name' => 'Aditya', 'last_name' => 'Mehta', 'dob' => '1994-01-19', 'anniversary' => null, 'address' => 'Satellite, Ahmedabad, Gujarat', 'notes' => 'Buys silver accessories monthly.', 'loyalty_points' => 155],
            ['first_name' => 'Arjun', 'last_name' => 'Nair', 'dob' => '1990-06-25', 'anniversary' => '2017-10-09', 'address' => 'Vastrapur, Ahmedabad, Gujarat', 'notes' => 'Interested in hallmark-certified pieces.', 'loyalty_points' => 320],
            ['first_name' => 'Krishna', 'last_name' => 'Iyer', 'dob' => '1987-12-02', 'anniversary' => '2013-07-15', 'address' => 'Paldi, Ahmedabad, Gujarat', 'notes' => 'High value festive purchases.', 'loyalty_points' => 580],
            ['first_name' => 'Ishaan', 'last_name' => 'Reddy', 'dob' => '1996-05-17', 'anniversary' => null, 'address' => 'Maninagar, Ahmedabad, Gujarat', 'notes' => 'Prefers exchange offers.', 'loyalty_points' => 90],
            ['first_name' => 'Saanvi', 'last_name' => 'Gupta', 'dob' => '1993-09-14', 'anniversary' => '2019-01-26', 'address' => 'Naranpura, Ahmedabad, Gujarat', 'notes' => 'Tracks scheme maturity closely.', 'loyalty_points' => 275],
            ['first_name' => 'Ananya', 'last_name' => 'Joshi', 'dob' => '1991-11-08', 'anniversary' => '2016-03-03', 'address' => 'Prahlad Nagar, Ahmedabad, Gujarat', 'notes' => 'Prefers diamond pendants.', 'loyalty_points' => 365],
            ['first_name' => 'Diya', 'last_name' => 'Kulkarni', 'dob' => '1998-02-22', 'anniversary' => null, 'address' => 'Thaltej, Ahmedabad, Gujarat', 'notes' => 'Occasional gifting purchases.', 'loyalty_points' => 120],
            ['first_name' => 'Myra', 'last_name' => 'Bose', 'dob' => '1995-07-04', 'anniversary' => '2021-12-12', 'address' => 'South Bopal, Ahmedabad, Gujarat', 'notes' => 'Likes contemporary daily wear.', 'loyalty_points' => 198],
            ['first_name' => 'Aadhya', 'last_name' => 'Singh', 'dob' => '1988-03-28', 'anniversary' => '2014-09-18', 'address' => 'Ghatlodia, Ahmedabad, Gujarat', 'notes' => 'Strong loyalty member.', 'loyalty_points' => 510],
            ['first_name' => 'Riya', 'last_name' => 'Chopra', 'dob' => '1997-10-11', 'anniversary' => null, 'address' => 'Motera, Ahmedabad, Gujarat', 'notes' => 'Prefers silver and stone combos.', 'loyalty_points' => 140],
            ['first_name' => 'Priya', 'last_name' => 'Verma', 'dob' => '1990-01-30', 'anniversary' => '2012-06-20', 'address' => 'Kandivali, Mumbai, Maharashtra', 'notes' => 'Bulk family purchases during festivals.', 'loyalty_points' => 620],
            ['first_name' => 'Neha', 'last_name' => 'Agarwal', 'dob' => '1992-12-15', 'anniversary' => '2018-04-08', 'address' => 'Andheri West, Mumbai, Maharashtra', 'notes' => 'Interested in EMI options.', 'loyalty_points' => 260],
            ['first_name' => 'Pooja', 'last_name' => 'Mishra', 'dob' => '1986-05-09', 'anniversary' => '2011-11-17', 'address' => 'Salt Lake, Kolkata, West Bengal', 'notes' => 'Traditional bridal collection buyer.', 'loyalty_points' => 700],
            ['first_name' => 'Sneha', 'last_name' => 'Saxena', 'dob' => '1994-08-27', 'anniversary' => null, 'address' => 'Aliganj, Lucknow, Uttar Pradesh', 'notes' => 'Regular monthly scheme payments.', 'loyalty_points' => 245],
            ['first_name' => 'Kavya', 'last_name' => 'Pillai', 'dob' => '1999-04-03', 'anniversary' => null, 'address' => 'Indiranagar, Bengaluru, Karnataka', 'notes' => 'Prefers minimal modern designs.', 'loyalty_points' => 130],
            ['first_name' => 'Nisha', 'last_name' => 'Yadav', 'dob' => '1991-06-16', 'anniversary' => '2017-02-14', 'address' => 'Raj Nagar, Ghaziabad, Uttar Pradesh', 'notes' => 'Refers friends frequently.', 'loyalty_points' => 340],
            ['first_name' => 'Meera', 'last_name' => 'Bhatia', 'dob' => '1989-09-01', 'anniversary' => '2010-12-05', 'address' => 'Vaishali Nagar, Jaipur, Rajasthan', 'notes' => 'High-ticket festive buyer.', 'loyalty_points' => 540],
            ['first_name' => 'Tanya', 'last_name' => 'Malhotra', 'dob' => '1996-11-23', 'anniversary' => null, 'address' => 'Model Town, Ludhiana, Punjab', 'notes' => 'Prefers catalog-based preorders.', 'loyalty_points' => 175],
            ['first_name' => 'Rahul', 'last_name' => 'Desai', 'dob' => '1988-02-10', 'anniversary' => '2013-01-19', 'address' => 'Alkapuri, Vadodara, Gujarat', 'notes' => 'Corporate gifting inquiries.', 'loyalty_points' => 290],
            ['first_name' => 'Rohan', 'last_name' => 'Kapoor', 'dob' => '1993-07-29', 'anniversary' => null, 'address' => 'Viman Nagar, Pune, Maharashtra', 'notes' => 'Prefers buyback-friendly items.', 'loyalty_points' => 205],
            ['first_name' => 'Kunal', 'last_name' => 'Jain', 'dob' => '1990-10-07', 'anniversary' => '2016-08-22', 'address' => 'Civil Lines, Delhi', 'notes' => 'Gold coin and bar purchases.', 'loyalty_points' => 415],
            ['first_name' => 'Siddharth', 'last_name' => 'Tiwari', 'dob' => '1995-03-13', 'anniversary' => null, 'address' => 'Arera Colony, Bhopal, Madhya Pradesh', 'notes' => 'Tracks price trends before buying.', 'loyalty_points' => 160],
            ['first_name' => 'Aman', 'last_name' => 'Chauhan', 'dob' => '1992-01-05', 'anniversary' => '2020-02-20', 'address' => 'Shastri Nagar, Meerut, Uttar Pradesh', 'notes' => 'Occasional wedding season shopper.', 'loyalty_points' => 230],
        ];
    }

    /**
     * @param array<int, string> $usedMobiles
     */
    private function nextUniqueMobile(int $shopId, int $slot, array &$usedMobiles): string
    {
        $counter = 0;
        while (true) {
            $raw = 7000000000 + (($shopId % 10000) * 1000) + $slot + $counter;
            $mobile = (string) $raw;
            $mobile = substr($mobile, 0, 10);

            if (!in_array($mobile, $usedMobiles, true)) {
                $usedMobiles[] = $mobile;
                return $mobile;
            }
            $counter++;
        }
    }
}

