<?php

namespace Database\Seeders;

use App\Models\CashTransaction;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Item;
use App\Models\Karigar;
use App\Models\MetalLot;
use App\Models\Permission;
use App\Models\Platform\Plan;
use App\Models\Platform\PlatformAdmin;
use App\Models\Platform\ShopSubscription;
use App\Models\Repair;
use App\Models\ReorderRule;
use App\Models\Role;
use App\Models\Shop;
use App\Models\ShopBillingSettings;
use App\Models\ShopDailyMetalRate;
use App\Models\ShopEditionAssignment;
use App\Models\ShopPaymentMethod;
use App\Models\User;
use App\Models\Vendor;
use App\Support\TenantContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * PilotDemoSeeder — one coherent, clearly-marked demo jewellery shop for
 * staging/client pilots. A client logs in as the demo owner and sees realistic
 * data across the main JewelFlow flows.
 *
 * SAFETY
 * - Demo-only. Refuses to run on production unless PILOT_DEMO_ALLOW_PROD=true.
 * - Idempotent: keyed on a fixed demo owner mobile; re-running reuses the shop
 *   instead of duplicating or overwriting real shops.
 * - Fake names / phones / GST only. No real personal data. No Dhiran data.
 * - No destructive deletes. Only creates, scoped to the demo shop.
 * - Uses real write paths on non-immutable tables (model create) and the
 *   append-only CashTransaction::record() for cash entries — never raw SQL.
 *
 * SCOPE NOTE — accounting transaction samples (POS sale → invoice, return /
 * exchange, buyback, scheme/EMI, purchase inward) are intentionally NOT seeded.
 * Those route through the service layer + ImmutableLedger + DB accounting
 * triggers; seeding them by hand risks constitutionally-invalid rows. Create
 * them LIVE through the UI on this demo shop (see STAGING_DEPLOY.md "Live demo
 * script") — that exercises real accounting and doubles as the demo walkthrough.
 *
 * Run (local/staging only):  php artisan db:seed --class=PilotDemoSeeder
 */
class PilotDemoSeeder extends Seeder
{
    /** Fixed demo identifiers — re-run detection + documented login creds. */
    private const SHOP_NAME      = 'JewelFlow Demo Jewellers';
    private const OWNER_MOBILE   = '9000000111';
    private const MANAGER_MOBILE = '9000000112';
    private const CASHIER_MOBILE = '9000000113';
    private const DEMO_PASSWORD  = 'password'; // demo only — change before any real use

    public function run(): void
    {
        if (App::environment('production') && ! filter_var(env('PILOT_DEMO_ALLOW_PROD', false), FILTER_VALIDATE_BOOL)) {
            $this->command?->warn('PilotDemoSeeder skipped: production environment. Set PILOT_DEMO_ALLOW_PROD=true to override.');
            return;
        }

        $existing = Shop::where('owner_mobile', self::OWNER_MOBILE)->first();
        if ($existing) {
            $this->command?->info("Demo shop already exists (#{$existing->id}) — reusing, nothing overwritten.");
            return;
        }

        DB::transaction(function () {
            $admin = PlatformAdmin::firstOrCreate(
                ['email' => 'pilot-demo-admin@example.com'],
                [
                    'first_name' => 'Pilot', 'last_name' => 'Admin', 'name' => 'Pilot Demo Admin',
                    'mobile_number' => '9000000100', 'password' => Hash::make(self::DEMO_PASSWORD),
                    'role' => 'super_admin', 'is_active' => true,
                ]
            );

            $plan = Plan::firstOrCreate(
                ['code' => 'retailer_demo'],
                [
                    'name' => 'Demo Retailer', 'price_monthly' => 0, 'grace_days' => 5,
                    'downgrade_to_read_only_on_due' => true, 'is_active' => true,
                ]
            );

            $shop = Shop::create([
                'name' => self::SHOP_NAME, 'shop_type' => 'retailer', 'phone' => self::OWNER_MOBILE,
                'owner_first_name' => 'Demo', 'owner_last_name' => 'Owner', 'owner_mobile' => self::OWNER_MOBILE,
                'gst_rate' => 3.00, 'wastage_recovery_percent' => 100.00, 'access_mode' => 'active', 'is_active' => true,
            ]);

            // Retailer edition so the shop is writable (mirrors onboarding's seed row).
            ShopEditionAssignment::firstOrCreate(
                ['shop_id' => $shop->id, 'edition' => 'retailer'],
                ['source' => 'seed', 'activated_at' => now()]
            );

            ShopSubscription::create([
                'shop_id' => $shop->id, 'plan_id' => $plan->id, 'status' => 'active',
                'starts_at' => now()->subDay()->toDateString(), 'ends_at' => now()->addYear()->toDateString(),
                'grace_ends_at' => now()->addYear()->addDays(5)->toDateString(), 'updated_by_admin_id' => $admin->id,
            ]);

            ShopBillingSettings::create([
                'shop_id' => $shop->id, 'invoice_prefix' => 'INV-', 'invoice_start_number' => 1001,
            ]);

            // Roles: owner (full), manager (full minus admin), cashier (counter subset).
            $ownerRole   = $this->role($shop->id, 'owner', 'Owner');
            $managerRole = $this->role($shop->id, 'manager', 'Manager');
            $cashierRole = $this->role($shop->id, 'cashier', 'Cashier');
            $ownerRole->permissions()->sync(Permission::query()->pluck('id'));
            $managerRole->permissions()->sync($this->permIdsExcluding(['settings.roles', 'settings.staff', 'staff.']));
            $cashierRole->permissions()->sync($this->permIdsMatching([
                'pos.%', 'sales.%', 'customers.%', 'invoices.%', 'quick-bills.%',
                'returns.view', 'cash.create', 'cash.view', 'repairs.%',
            ]));

            $owner   = $this->user($shop->id, $ownerRole->id, 'Demo Owner', self::OWNER_MOBILE);
            $this->user($shop->id, $managerRole->id, 'Demo Manager', self::MANAGER_MOBILE);
            $this->user($shop->id, $cashierRole->id, 'Demo Cashier', self::CASHIER_MOBILE);

            // Everything below is tenant-scoped; run inside the demo shop's context.
            TenantContext::runFor($shop->id, function () use ($shop, $owner) {
                $this->paymentMethods($shop->id);
                $this->rates($shop->id, $owner->id);

                Vendor::create([
                    'shop_id' => $shop->id, 'name' => 'Demo Bullion Supplier', 'contact_person' => 'Suresh',
                    'mobile' => '9000000201', 'city' => 'Mumbai', 'state' => 'Maharashtra', 'is_active' => true,
                ]);

                Karigar::create([
                    'shop_id' => $shop->id, 'name' => 'Demo Karigar', 'shop_name' => 'Ramesh Goldsmiths',
                    'contact_person' => 'Ramesh', 'mobile' => '9000000202', 'city' => 'Mumbai', 'state' => 'Maharashtra',
                ]);

                $lot = MetalLot::create([
                    'shop_id' => $shop->id, 'source' => 'purchase', 'purity' => 22.00,
                    'fine_weight_total' => 500.0, 'fine_weight_remaining' => 500.0, 'cost_per_fine_gram' => 6800.00,
                ]);

                $this->categories($shop->id);
                $this->items($shop->id, $lot->id);

                // Reorder rule that the seeded low Ring stock trips → live demo alert.
                ReorderRule::create(['shop_id' => $shop->id, 'category' => 'Ring', 'min_stock_threshold' => 5]);

                $this->customers($shop->id);
                $this->cashBook($shop->id, $owner->id);
                $this->repairs($shop->id);
            });

            $this->command?->info("Demo shop '" . self::SHOP_NAME . "' created (#{$shop->id}). Owner login: " . self::OWNER_MOBILE . " / " . self::DEMO_PASSWORD);
        });
    }

    private function role(int $shopId, string $name, string $display): Role
    {
        $r = new Role();
        $r->forceFill(['name' => $name, 'display_name' => $display, 'shop_id' => $shopId])->save();
        return $r;
    }

    private function permIdsMatching(array $patterns): array
    {
        return Permission::where(function ($q) use ($patterns) {
            foreach ($patterns as $p) {
                $q->orWhere('name', 'like', $p);
            }
        })->pluck('id')->all();
    }

    private function permIdsExcluding(array $prefixes): array
    {
        return Permission::where(function ($q) use ($prefixes) {
            foreach ($prefixes as $p) {
                $q->where('name', 'not like', $p . '%');
            }
        })->pluck('id')->all();
    }

    private function user(int $shopId, int $roleId, string $name, string $mobile): User
    {
        // Built directly, not via factory. Factories need fakerphp/faker, which is
        // a dev-only dependency excluded by composer install --no-dev.
        $user = new User();

        $user->forceFill([
            'shop_id' => $shopId,
            'role_id' => $roleId,
            'name' => $name,
            'mobile_number' => $mobile,
            'password' => Hash::make(self::DEMO_PASSWORD),
            'is_active' => true,
            'email_verified_at' => now(),
        ])->save();

        return $user;
    }
    private function paymentMethods(int $shopId): void
    {
        foreach ([['cash', 'Cash'], ['upi', 'Shop UPI'], ['bank', 'Shop Bank'], ['card', 'Card Machine']] as [$type, $name]) {
            ShopPaymentMethod::create(['shop_id' => $shopId, 'type' => $type, 'name' => $name]);
        }
    }

    private function rates(int $shopId, int $userId): void
    {
        ShopDailyMetalRate::create([
            'shop_id' => $shopId, 'business_date' => Carbon::today()->toDateString(), 'timezone' => 'Asia/Kolkata',
            'entered_by_user_id' => $userId, 'entered_at' => now(),
            'gold_24k_rate_per_gram' => 7200.0000, 'silver_999_rate_per_gram' => 95.0000,
        ]);
    }

    private function categories(int $shopId): void
    {
        foreach (['Ring', 'Necklace', 'Bangle', 'Earring'] as $name) {
            Category::create(['shop_id' => $shopId, 'name' => $name]);
        }
    }

    /** ~12 items. Only 2 Rings in stock (below the threshold of 5) → reorder alert. */
    private function items(int $shopId, int $lotId): void
    {
        $plan = [
            ['Ring', 2], ['Necklace', 4], ['Bangle', 3], ['Earring', 3],
        ];
        $n = 0;
        foreach ($plan as [$category, $qty]) {
            for ($i = 0; $i < $qty; $i++) {
                Item::create([
                    'shop_id' => $shopId,
                    'barcode' => 'DEMO' . str_pad((string) (++$n), 4, '0', STR_PAD_LEFT),
                    'design' => "Demo {$category} {$i}",
                    'category' => $category,
                    'gross_weight' => 10.000 + $i,
                    'stone_weight' => 0.500,
                    'net_metal_weight' => 9.500 + $i,
                    'purity' => 22.00,
                    'metal_lot_id' => $lotId,
                    'wastage' => 0.200,
                    'making_charges' => 1500.00,
                    'stone_charges' => 0.00,
                    'cost_price' => 65000.00 + ($i * 1000),
                    'status' => 'in_stock',
                ]);
            }
        }
    }

    private function customers(int $shopId): void
    {
        $people = [
            ['Aarti', 'Sharma', '9810000001'], ['Vikram', 'Mehta', '9810000002'],
            ['Priya', 'Nair', '9810000003'], ['Rahul', 'Gupta', '9810000004'],
            ['Sunita', 'Rao', '9810000005'], ['Imran', 'Khan', '9810000006'],
        ];
        foreach ($people as [$first, $last, $mobile]) {
            $c = new Customer();
            $c->forceFill([
                'shop_id' => $shopId, 'first_name' => $first, 'last_name' => $last, 'mobile' => $mobile,
            ])->save();
        }
    }

    /** Cash book via the real append-only record() path. */
    private function cashBook(int $shopId, int $userId): void
    {
        $base = ['shop_id' => $shopId, 'user_id' => $userId, 'payment_mode' => 'cash'];
        CashTransaction::record($base + ['type' => 'in', 'amount' => 50000, 'source_type' => 'opening_balance', 'description' => 'Opening cash', 'created_at' => now()->subDays(2)]);
        CashTransaction::record($base + ['type' => 'in', 'amount' => 12000, 'source_type' => 'customer_payment', 'description' => 'Counter sale', 'created_at' => now()->subDay()]);
        CashTransaction::record($base + ['type' => 'out', 'amount' => 3000, 'source_type' => 'petty_expense', 'description' => 'Shop expense', 'created_at' => now()->subDay()]);
    }

    private function repairs(int $shopId): void
    {
        // NOTE: a 'delivered' repair requires a linked invoice (Repair model guard —
        // delivery goes through the Bill flow). Seed pending + ready; mark a repair
        // delivered LIVE via the Bill flow on the demo shop.
        $customerId = Customer::withoutTenant()->where('shop_id', $shopId)->value('id');
        foreach ([['pending', 'Gold ring resize'], ['ready', 'Chain clasp fix']] as [$status, $desc]) {
            $r = new Repair();
            $r->forceFill([
                'shop_id' => $shopId, 'customer_id' => $customerId, 'item_description' => $desc, 'description' => $desc,
                'metal_type' => 'gold', 'gross_weight' => 8.000, 'purity' => 22.00,
                'estimated_cost' => 500.00, 'final_cost' => null, 'status' => $status,
            ])->save();
        }
    }
}
