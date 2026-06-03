<?php

namespace Database\Seeders;

use App\Models\Customer;
use App\Models\Shop;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

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

            $targetCount = 55;
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
                $slot    = $existingCount + $i + 1;

                $mobile    = $this->nextUniqueMobile($shopId, $slot, $usedMobiles);
                $dob       = Carbon::createFromFormat('Y-m-d', $profile['dob'])->toDateString();
                $anniversary = $profile['anniversary']
                    ? Carbon::createFromFormat('Y-m-d', $profile['anniversary'])->toDateString()
                    : null;

                // Generate per-slot unique PAN and GSTIN so they don't collide
                $panBase  = strtoupper(substr(str_pad($profile['first_name'], 3, 'X'), 0, 3)
                    . substr(str_pad($profile['last_name'], 2, 'P'), 0, 2));
                $panDigits = str_pad((string) (($shopId * 100 + $slot) % 9999), 4, '0', STR_PAD_LEFT);
                $panSuffix = chr(65 + ($slot % 26));
                $pan       = $panBase . $panDigits . $panSuffix;   // e.g. AARSM1234A

                $stateCode = $profile['state_code'];
                $gstin     = $profile['customer_type'] === 'b2b'
                    ? $stateCode . $pan . ($slot % 10) . 'Z' . chr(65 + (($shopId + $slot) % 26))
                    : null;

                // Aadhaar-format placeholder: 12 digits
                $idNumber = str_pad((string) (700000000000 + ($shopId * 10000) + $slot), 12, '0', STR_PAD_LEFT);

                $email = strtolower($profile['first_name'] . '.' . $profile['last_name'])
                    . $slot . '@' . $this->emailDomain($stateCode);

                $customer = new Customer();
                $customer->shop_id         = $shopId;
                $customer->first_name      = $profile['first_name'];
                $customer->last_name       = $profile['last_name'];
                $customer->mobile          = $mobile;
                $customer->email           = $email;
                $customer->address         = $profile['address'];
                $customer->state_code      = $stateCode;
                $customer->customer_type   = $profile['customer_type'];
                $customer->gstin           = $gstin;
                $customer->pan             = $pan;
                $customer->id_number       = $idNumber;
                $customer->date_of_birth   = $dob;
                $customer->anniversary_date = $anniversary;
                $customer->wedding_date     = $anniversary;
                $customer->notes           = $profile['notes'];
                $customer->loyalty_points  = $profile['loyalty_points'];

                // Mark some customers as compliance-verified (realistic mix)
                if ($profile['customer_type'] === 'b2b' || $profile['loyalty_points'] > 300) {
                    $customer->compliance_verified_at = Carbon::now()->subDays(rand(10, 365));
                    $customer->consent_given_at       = $customer->compliance_verified_at;
                }

                $customer->save();
            }

            $this->command?->info("CustomerSeeder: added {$needed} customers for shop {$shopId}.");
        }
    }

    private function emailDomain(string $stateCode): string
    {
        return match ($stateCode) {
            'MH' => 'gmail.com',
            'GJ' => 'yahoo.in',
            'RJ' => 'hotmail.com',
            'DL' => 'gmail.com',
            'KA' => 'outlook.com',
            'WB' => 'gmail.com',
            'UP' => 'rediffmail.com',
            'TN' => 'gmail.com',
            default => 'gmail.com',
        };
    }

    /**
     * 60 realistic Indian jewellery-shop customer profiles.
     * Intentional duplicate first+last names appear at indices 12/42 (Priya Sharma),
     * 21/43 (Rahul Patel), 30/44 (Anjali Gupta), 5/45 (Rohan Singh) — same name,
     * different city/details/mobile so the unique constraint (shop_id, mobile) still holds.
     *
     * All fields: first_name, last_name, dob, anniversary, address, state_code,
     * customer_type (b2c/b2b), notes, loyalty_points.
     */
    private function profiles(): array
    {
        return [
            // ── 1–10: Gujarat regulars ──────────────────────────────────────
            ['first_name' => 'Aarav',     'last_name' => 'Sharma',   'dob' => '1992-04-12', 'anniversary' => '2018-02-21', 'address' => 'Bodakdev, Ahmedabad, Gujarat 380054',           'state_code' => 'GJ', 'customer_type' => 'b2c', 'notes' => 'Prefers lightweight office wear sets.',           'loyalty_points' => 210],
            ['first_name' => 'Vivaan',    'last_name' => 'Patel',    'dob' => '1989-08-06', 'anniversary' => '2015-11-30', 'address' => 'Navrangpura, Ahmedabad, Gujarat 380009',        'state_code' => 'GJ', 'customer_type' => 'b2c', 'notes' => 'Wedding jewellery repeat buyer.',               'loyalty_points' => 430],
            ['first_name' => 'Aditya',    'last_name' => 'Mehta',    'dob' => '1994-01-19', 'anniversary' => null,         'address' => 'Satellite, Ahmedabad, Gujarat 380015',           'state_code' => 'GJ', 'customer_type' => 'b2c', 'notes' => 'Buys silver accessories monthly.',             'loyalty_points' => 155],
            ['first_name' => 'Arjun',     'last_name' => 'Nair',     'dob' => '1990-06-25', 'anniversary' => '2017-10-09', 'address' => 'Vastrapur, Ahmedabad, Gujarat 380015',           'state_code' => 'GJ', 'customer_type' => 'b2c', 'notes' => 'Interested in hallmark-certified pieces.',       'loyalty_points' => 320],
            ['first_name' => 'Krishna',   'last_name' => 'Iyer',     'dob' => '1987-12-02', 'anniversary' => '2013-07-15', 'address' => 'Paldi, Ahmedabad, Gujarat 380007',               'state_code' => 'GJ', 'customer_type' => 'b2b', 'notes' => 'High value festive purchases. B2B retailer.',    'loyalty_points' => 580],
            ['first_name' => 'Rohan',     'last_name' => 'Singh',    'dob' => '1991-03-14', 'anniversary' => '2019-06-10', 'address' => 'Prahlad Nagar, Ahmedabad, Gujarat 380015',       'state_code' => 'GJ', 'customer_type' => 'b2c', 'notes' => 'First entry — Prahlad Nagar branch.',           'loyalty_points' => 180],
            ['first_name' => 'Saanvi',    'last_name' => 'Gupta',    'dob' => '1993-09-14', 'anniversary' => '2019-01-26', 'address' => 'Naranpura, Ahmedabad, Gujarat 380013',           'state_code' => 'GJ', 'customer_type' => 'b2c', 'notes' => 'Tracks scheme maturity closely.',              'loyalty_points' => 275],
            ['first_name' => 'Ananya',    'last_name' => 'Joshi',    'dob' => '1991-11-08', 'anniversary' => '2016-03-03', 'address' => 'Jodhpur, Ahmedabad, Gujarat 380015',             'state_code' => 'GJ', 'customer_type' => 'b2c', 'notes' => 'Prefers diamond pendants and earrings.',        'loyalty_points' => 365],
            ['first_name' => 'Diya',      'last_name' => 'Kulkarni', 'dob' => '1998-02-22', 'anniversary' => null,         'address' => 'Thaltej, Ahmedabad, Gujarat 380059',             'state_code' => 'GJ', 'customer_type' => 'b2c', 'notes' => 'Occasional gifting purchases.',                'loyalty_points' => 120],
            ['first_name' => 'Myra',      'last_name' => 'Bose',     'dob' => '1995-07-04', 'anniversary' => '2021-12-12', 'address' => 'South Bopal, Ahmedabad, Gujarat 380058',         'state_code' => 'GJ', 'customer_type' => 'b2c', 'notes' => 'Likes contemporary daily wear.',               'loyalty_points' => 198],

            // ── 11–20: Maharashtra ──────────────────────────────────────────
            ['first_name' => 'Aadhya',    'last_name' => 'Singh',    'dob' => '1988-03-28', 'anniversary' => '2014-09-18', 'address' => 'Bandra West, Mumbai, Maharashtra 400050',        'state_code' => 'MH', 'customer_type' => 'b2c', 'notes' => 'Strong loyalty member. Bridal set buyer.',     'loyalty_points' => 510],
            ['first_name' => 'Riya',      'last_name' => 'Chopra',   'dob' => '1997-10-11', 'anniversary' => null,         'address' => 'Andheri East, Mumbai, Maharashtra 400069',       'state_code' => 'MH', 'customer_type' => 'b2c', 'notes' => 'Prefers silver and stone combos.',             'loyalty_points' => 140],
            ['first_name' => 'Priya',     'last_name' => 'Sharma',   'dob' => '1990-01-30', 'anniversary' => '2012-06-20', 'address' => 'Kandivali West, Mumbai, Maharashtra 400067',     'state_code' => 'MH', 'customer_type' => 'b2c', 'notes' => 'Bulk family purchases during festivals.',      'loyalty_points' => 620],
            ['first_name' => 'Neha',      'last_name' => 'Agarwal',  'dob' => '1992-12-15', 'anniversary' => '2018-04-08', 'address' => 'Powai, Mumbai, Maharashtra 400076',              'state_code' => 'MH', 'customer_type' => 'b2c', 'notes' => 'Interested in EMI options.',                   'loyalty_points' => 260],
            ['first_name' => 'Sneha',     'last_name' => 'Desai',    'dob' => '1994-08-27', 'anniversary' => null,         'address' => 'Viman Nagar, Pune, Maharashtra 411014',          'state_code' => 'MH', 'customer_type' => 'b2c', 'notes' => 'Regular monthly scheme payments.',             'loyalty_points' => 245],
            ['first_name' => 'Kavya',     'last_name' => 'Pillai',   'dob' => '1999-04-03', 'anniversary' => null,         'address' => 'Shivaji Nagar, Pune, Maharashtra 411005',        'state_code' => 'MH', 'customer_type' => 'b2c', 'notes' => 'Prefers minimal modern designs.',             'loyalty_points' => 130],
            ['first_name' => 'Meera',     'last_name' => 'Bhatia',   'dob' => '1989-09-01', 'anniversary' => '2010-12-05', 'address' => 'Kothrud, Pune, Maharashtra 411038',              'state_code' => 'MH', 'customer_type' => 'b2c', 'notes' => 'High-ticket festive buyer.',                   'loyalty_points' => 540],
            ['first_name' => 'Tanya',     'last_name' => 'Malhotra', 'dob' => '1996-11-23', 'anniversary' => null,         'address' => 'Kondhwa, Pune, Maharashtra 411048',              'state_code' => 'MH', 'customer_type' => 'b2c', 'notes' => 'Prefers catalog-based preorders.',             'loyalty_points' => 175],
            ['first_name' => 'Rahul',     'last_name' => 'Desai',    'dob' => '1988-02-10', 'anniversary' => '2013-01-19', 'address' => 'Dadar, Mumbai, Maharashtra 400014',              'state_code' => 'MH', 'customer_type' => 'b2b', 'notes' => 'Corporate gifting. B2B wholesale orders.',     'loyalty_points' => 290],
            ['first_name' => 'Pooja',     'last_name' => 'Mishra',   'dob' => '1986-05-09', 'anniversary' => '2011-11-17', 'address' => 'Thane West, Thane, Maharashtra 400601',          'state_code' => 'MH', 'customer_type' => 'b2c', 'notes' => 'Traditional bridal collection buyer.',         'loyalty_points' => 700],

            // ── 21–30: Rajasthan & Delhi ────────────────────────────────────
            ['first_name' => 'Rahul',     'last_name' => 'Patel',    'dob' => '1993-07-29', 'anniversary' => null,         'address' => 'Vaishali Nagar, Jaipur, Rajasthan 302021',       'state_code' => 'RJ', 'customer_type' => 'b2c', 'notes' => 'Prefers buyback-friendly items.',             'loyalty_points' => 205],
            ['first_name' => 'Nisha',     'last_name' => 'Yadav',    'dob' => '1991-06-16', 'anniversary' => '2017-02-14', 'address' => 'Malviya Nagar, Jaipur, Rajasthan 302017',        'state_code' => 'RJ', 'customer_type' => 'b2c', 'notes' => 'Refers friends frequently.',                  'loyalty_points' => 340],
            ['first_name' => 'Kunal',     'last_name' => 'Jain',     'dob' => '1990-10-07', 'anniversary' => '2016-08-22', 'address' => 'Civil Lines, Delhi 110054',                      'state_code' => 'DL', 'customer_type' => 'b2b', 'notes' => 'Gold coin and bar purchases. Dealer account.', 'loyalty_points' => 415],
            ['first_name' => 'Siddharth', 'last_name' => 'Tiwari',   'dob' => '1995-03-13', 'anniversary' => null,         'address' => 'Lajpat Nagar, Delhi 110024',                     'state_code' => 'DL', 'customer_type' => 'b2c', 'notes' => 'Tracks price trends before buying.',          'loyalty_points' => 160],
            ['first_name' => 'Aman',      'last_name' => 'Chauhan',  'dob' => '1992-01-05', 'anniversary' => '2020-02-20', 'address' => 'Dwarka Sector 12, Delhi 110075',                 'state_code' => 'DL', 'customer_type' => 'b2c', 'notes' => 'Occasional wedding season shopper.',          'loyalty_points' => 230],
            ['first_name' => 'Ritika',    'last_name' => 'Bansal',   'dob' => '1994-04-18', 'anniversary' => '2019-11-05', 'address' => 'Rohini Sector 7, Delhi 110085',                  'state_code' => 'DL', 'customer_type' => 'b2c', 'notes' => 'Festival collection buyer.',                  'loyalty_points' => 310],
            ['first_name' => 'Simran',    'last_name' => 'Kaur',     'dob' => '1997-08-30', 'anniversary' => null,         'address' => 'Pitampura, Delhi 110034',                        'state_code' => 'DL', 'customer_type' => 'b2c', 'notes' => 'Youngest regular — loves rose gold.',          'loyalty_points' => 95],
            ['first_name' => 'Harshit',   'last_name' => 'Verma',    'dob' => '1989-12-11', 'anniversary' => '2015-05-22', 'address' => 'Saket, Delhi 110017',                            'state_code' => 'DL', 'customer_type' => 'b2c', 'notes' => 'Anniversary purchase every year.',            'loyalty_points' => 450],
            ['first_name' => 'Priyanka',  'last_name' => 'Rawat',    'dob' => '1993-05-25', 'anniversary' => '2018-10-14', 'address' => 'C-Scheme, Jaipur, Rajasthan 302001',             'state_code' => 'RJ', 'customer_type' => 'b2c', 'notes' => 'Prefers polki and kundan work.',             'loyalty_points' => 385],
            ['first_name' => 'Anjali',    'last_name' => 'Gupta',    'dob' => '1990-07-20', 'anniversary' => '2014-03-08', 'address' => 'Tonk Road, Jaipur, Rajasthan 302015',            'state_code' => 'RJ', 'customer_type' => 'b2c', 'notes' => 'Buys matching sets. First-entry Jaipur.',     'loyalty_points' => 270],

            // ── 31–40: Karnataka, Tamil Nadu, West Bengal ───────────────────
            ['first_name' => 'Deepika',   'last_name' => 'Rao',      'dob' => '1992-02-17', 'anniversary' => '2017-11-11', 'address' => 'Indiranagar, Bengaluru, Karnataka 560038',       'state_code' => 'KA', 'customer_type' => 'b2c', 'notes' => 'Loyal since opening day.',                    'loyalty_points' => 460],
            ['first_name' => 'Harini',    'last_name' => 'Krishnan', 'dob' => '1996-06-29', 'anniversary' => null,         'address' => 'JP Nagar, Bengaluru, Karnataka 560078',          'state_code' => 'KA', 'customer_type' => 'b2c', 'notes' => 'Prefers South Indian temple jewellery.',      'loyalty_points' => 200],
            ['first_name' => 'Vignesh',   'last_name' => 'Subramanian', 'dob' => '1988-09-04', 'anniversary' => '2012-04-25', 'address' => 'Koramangala, Bengaluru, Karnataka 560034',    'state_code' => 'KA', 'customer_type' => 'b2b', 'notes' => 'B2B vendor. Buys for resale.',               'loyalty_points' => 630],
            ['first_name' => 'Lavanya',   'last_name' => 'Venkat',   'dob' => '1994-11-13', 'anniversary' => '2020-01-17', 'address' => 'T. Nagar, Chennai, Tamil Nadu 600017',           'state_code' => 'TN', 'customer_type' => 'b2c', 'notes' => 'Traditional 22K gold buyer.',                 'loyalty_points' => 350],
            ['first_name' => 'Janani',    'last_name' => 'Muthukumar', 'dob' => '1991-03-07', 'anniversary' => '2016-12-20', 'address' => 'Anna Nagar, Chennai, Tamil Nadu 600040',      'state_code' => 'TN', 'customer_type' => 'b2c', 'notes' => 'Interested in bridal weight calculations.',   'loyalty_points' => 420],
            ['first_name' => 'Karthik',   'last_name' => 'Sundaram',  'dob' => '1987-06-15', 'anniversary' => '2011-08-30', 'address' => 'Velachery, Chennai, Tamil Nadu 600042',         'state_code' => 'TN', 'customer_type' => 'b2b', 'notes' => 'Retail chain buyer — bulk orders.',           'loyalty_points' => 740],
            ['first_name' => 'Subha',     'last_name' => 'Basu',     'dob' => '1993-10-01', 'anniversary' => '2019-05-04', 'address' => 'Salt Lake Sector V, Kolkata, West Bengal 700091', 'state_code' => 'WB', 'customer_type' => 'b2c', 'notes' => 'Purchases on birthdays and anniversaries.', 'loyalty_points' => 300],
            ['first_name' => 'Tanushree', 'last_name' => 'Ghosh',    'dob' => '1990-01-14', 'anniversary' => '2015-06-27', 'address' => 'Gariahat, Kolkata, West Bengal 700019',          'state_code' => 'WB', 'customer_type' => 'b2c', 'notes' => 'Loves Bengali gold work (filigree).',        'loyalty_points' => 480],
            ['first_name' => 'Debashish', 'last_name' => 'Das',      'dob' => '1985-04-22', 'anniversary' => '2010-02-15', 'address' => 'Park Street, Kolkata, West Bengal 700016',       'state_code' => 'WB', 'customer_type' => 'b2b', 'notes' => 'Wholesale inquiries for festive season.',     'loyalty_points' => 560],
            ['first_name' => 'Sourav',    'last_name' => 'Chatterjee', 'dob' => '1992-08-18', 'anniversary' => null,        'address' => 'Howrah, West Bengal 711101',                    'state_code' => 'WB', 'customer_type' => 'b2c', 'notes' => 'First-time buyer. Referred by Tanushree.',    'loyalty_points' => 80],

            // ── 41–50: Uttar Pradesh, Punjab, Haryana ───────────────────────
            ['first_name' => 'Puja',      'last_name' => 'Srivastava', 'dob' => '1991-07-30', 'anniversary' => '2016-04-12', 'address' => 'Hazratganj, Lucknow, Uttar Pradesh 226001',   'state_code' => 'UP', 'customer_type' => 'b2c', 'notes' => 'Avid scheme collector. 3 active schemes.',   'loyalty_points' => 490],
            ['first_name' => 'Divya',     'last_name' => 'Pandey',   'dob' => '1995-02-05', 'anniversary' => null,         'address' => 'Gomti Nagar, Lucknow, Uttar Pradesh 226010',    'state_code' => 'UP', 'customer_type' => 'b2c', 'notes' => 'Recent college graduate. First purchase.',   'loyalty_points' => 60],
            // Duplicate name: same "Priya Sharma" — different city and mobile
            ['first_name' => 'Priya',     'last_name' => 'Sharma',   'dob' => '1988-06-11', 'anniversary' => '2013-11-22', 'address' => 'Varanasi Cantonment, Varanasi, UP 221002',       'state_code' => 'UP', 'customer_type' => 'b2c', 'notes' => 'Second Priya Sharma — Varanasi branch ref.', 'loyalty_points' => 355],
            // Duplicate name: same "Rahul Patel" — different city and mobile
            ['first_name' => 'Rahul',     'last_name' => 'Patel',    'dob' => '1985-10-20', 'anniversary' => '2010-05-18', 'address' => 'Agra Cantt, Agra, Uttar Pradesh 282001',         'state_code' => 'UP', 'customer_type' => 'b2b', 'notes' => 'Second Rahul Patel — dealer, Agra.',         'loyalty_points' => 520],
            // Duplicate name: same "Anjali Gupta" — different city and mobile
            ['first_name' => 'Anjali',    'last_name' => 'Gupta',    'dob' => '1993-12-03', 'anniversary' => null,         'address' => 'Sector 15, Noida, Uttar Pradesh 201301',         'state_code' => 'UP', 'customer_type' => 'b2c', 'notes' => 'Second Anjali Gupta — Noida office buyer.',  'loyalty_points' => 185],
            // Duplicate name: same "Rohan Singh" — different city and mobile
            ['first_name' => 'Rohan',     'last_name' => 'Singh',    'dob' => '1994-04-08', 'anniversary' => '2021-08-16', 'address' => 'Civil Lines, Allahabad, UP 211001',               'state_code' => 'UP', 'customer_type' => 'b2c', 'notes' => 'Second Rohan Singh — Allahabad.',           'loyalty_points' => 225],
            ['first_name' => 'Gurmeet',   'last_name' => 'Kaur',     'dob' => '1986-01-25', 'anniversary' => '2009-10-13', 'address' => 'Model Town, Ludhiana, Punjab 141002',            'state_code' => 'PB', 'customer_type' => 'b2c', 'notes' => 'Traditional Punjabi bridal buyer.',          'loyalty_points' => 680],
            ['first_name' => 'Jaspreet',  'last_name' => 'Singh',    'dob' => '1991-05-19', 'anniversary' => '2018-07-07', 'address' => 'Sector 22, Chandigarh 160022',                   'state_code' => 'PB', 'customer_type' => 'b2c', 'notes' => 'Referral from Gurmeet Kaur.',                 'loyalty_points' => 310],
            ['first_name' => 'Sunita',    'last_name' => 'Hooda',    'dob' => '1984-09-09', 'anniversary' => '2008-02-28', 'address' => 'Sector 14, Hisar, Haryana 125001',               'state_code' => 'HR', 'customer_type' => 'b2c', 'notes' => 'Long-term customer. 10+ years.',             'loyalty_points' => 780],
            ['first_name' => 'Manpreet',  'last_name' => 'Brar',     'dob' => '1996-12-17', 'anniversary' => null,         'address' => 'Sector 46, Gurugram, Haryana 122003',            'state_code' => 'HR', 'customer_type' => 'b2b', 'notes' => 'Corporate gifts for HR team.',               'loyalty_points' => 405],

            // ── 51–60: Andhra Pradesh, Telangana, Madhya Pradesh, misc ──────
            ['first_name' => 'Padmavathi', 'last_name' => 'Reddy',  'dob' => '1990-03-06', 'anniversary' => '2014-11-09', 'address' => 'Jubilee Hills, Hyderabad, Telangana 500033',      'state_code' => 'TG', 'customer_type' => 'b2c', 'notes' => 'Wedding jewellery and gold saving plan.',   'loyalty_points' => 590],
            ['first_name' => 'Sravani',   'last_name' => 'Rao',     'dob' => '1997-01-22', 'anniversary' => null,         'address' => 'Banjara Hills, Hyderabad, Telangana 500034',      'state_code' => 'TG', 'customer_type' => 'b2c', 'notes' => 'Fashion-forward daily wear buyer.',          'loyalty_points' => 145],
            ['first_name' => 'Vikram',    'last_name' => 'Naidu',   'dob' => '1986-07-14', 'anniversary' => '2010-09-25', 'address' => 'Gachibowli, Hyderabad, Telangana 500032',         'state_code' => 'TG', 'customer_type' => 'b2b', 'notes' => 'Tech park corporate gifting account.',       'loyalty_points' => 660],
            ['first_name' => 'Madhavi',   'last_name' => 'Krishna', 'dob' => '1993-08-11', 'anniversary' => '2019-02-20', 'address' => 'Vijayawada Civil, Vijayawada, Andhra Pradesh 520001', 'state_code' => 'AP', 'customer_type' => 'b2c', 'notes' => 'Traditional Kuchipudi occasion buyer.', 'loyalty_points' => 280],
            ['first_name' => 'Rekha',     'last_name' => 'Narayanan', 'dob' => '1989-11-30', 'anniversary' => '2013-10-03', 'address' => 'MG Road, Vijayawada, Andhra Pradesh 520010',   'state_code' => 'AP', 'customer_type' => 'b2c', 'notes' => 'Anniversary gift purchaser every year.',     'loyalty_points' => 395],
            ['first_name' => 'Sushma',    'last_name' => 'Patil',   'dob' => '1987-04-15', 'anniversary' => '2010-12-18', 'address' => 'Arera Colony, Bhopal, Madhya Pradesh 462016',     'state_code' => 'MP', 'customer_type' => 'b2c', 'notes' => 'Trusts only 22K BIS hallmark pieces.',      'loyalty_points' => 460],
            ['first_name' => 'Bharat',    'last_name' => 'Saxena',  'dob' => '1983-02-27', 'anniversary' => '2008-11-01', 'address' => 'Napier Town, Jabalpur, Madhya Pradesh 482001',    'state_code' => 'MP', 'customer_type' => 'b2b', 'notes' => 'Wholesale inquiries twice a year.',          'loyalty_points' => 520],
            // Extra duplicate: "Neha Patel" — same last name as Vivaan Patel, different first
            ['first_name' => 'Neha',      'last_name' => 'Patel',   'dob' => '1994-09-18', 'anniversary' => null,         'address' => 'Alkapuri, Vadodara, Gujarat 390007',             'state_code' => 'GJ', 'customer_type' => 'b2c', 'notes' => 'Cousin of Vivaan Patel — same surname.',    'loyalty_points' => 155],
            // Another "Anjali Sharma"
            ['first_name' => 'Anjali',    'last_name' => 'Sharma',  'dob' => '1996-05-02', 'anniversary' => '2022-04-30', 'address' => 'Ratanada, Jodhpur, Rajasthan 342001',             'state_code' => 'RJ', 'customer_type' => 'b2c', 'notes' => 'Newlywed — interested in daily wear gold.',  'loyalty_points' => 115],
            // "Amit Singh" — common name, distinct person
            ['first_name' => 'Amit',      'last_name' => 'Singh',   'dob' => '1984-11-06', 'anniversary' => '2009-06-14', 'address' => 'Vikas Nagar, Ranchi, Jharkhand 834009',           'state_code' => 'JH', 'customer_type' => 'b2c', 'notes' => 'Travels from Ranchi; big festive purchaser.', 'loyalty_points' => 640],
            // "Kiran Sharma" — another common surname
            ['first_name' => 'Kiran',     'last_name' => 'Sharma',  'dob' => '1992-10-23', 'anniversary' => '2018-08-12', 'address' => 'Sector 9, Bokaro Steel City, Jharkhand 827009',   'state_code' => 'JH', 'customer_type' => 'b2c', 'notes' => 'Regular scheme member since 2022.',         'loyalty_points' => 330],
        ];
    }

    /**
     * @param array<int, string> $usedMobiles
     */
    private function nextUniqueMobile(int $shopId, int $slot, array &$usedMobiles): string
    {
        // Start from realistic Indian mobile prefixes (7xxx/8xxx/9xxx)
        $bases = [9800000000, 8700000000, 7600000000, 9900000000, 8800000000];
        $base  = $bases[$shopId % count($bases)];

        $counter = 0;
        while (true) {
            $raw    = $base + ($slot * 7) + $counter;
            $mobile = substr((string) $raw, 0, 10);

            if (!in_array($mobile, $usedMobiles, true)) {
                $usedMobiles[] = $mobile;
                return $mobile;
            }
            $counter++;
        }
    }
}
