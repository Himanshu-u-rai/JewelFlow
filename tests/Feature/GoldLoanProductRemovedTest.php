<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * The retired gold-loan product and its cross-product promotion stay gone:
 * no route, no public mention, no leftover data, and the removal migration
 * refuses to destroy real loan data in production.
 */
class GoldLoanProductRemovedTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    private const MIGRATION = 'database/migrations/2026_11_26_100000_remove_gold_loan_product.php';

    public function test_no_route_of_the_removed_product_or_promotion_is_registered(): void
    {
        $hits = collect(Route::getRoutes()->getRoutes())
            ->map(fn ($r) => $r->uri().' '.($r->getName() ?? '').' '.implode(',', $r->gatherMiddleware()))
            ->filter(fn ($s) => preg_match('/dhiran|product-preferences|ProductPromotion/i', $s))
            ->values()->all();

        $this->assertSame([], $hits);
    }

    public function test_public_pages_do_not_mention_the_removed_product(): void
    {
        foreach (['/', '/login', '/register', '/forgot-password', '/about', '/terms', '/privacy'] as $uri) {
            $response = $this->get($uri);
            $response->assertOk();
            $this->assertDoesNotMatchRegularExpression('/dhiran|gold.?loan|pledge|borrower|product preferences/i', $response->getContent(), $uri);
        }
    }

    public function test_old_product_urls_are_not_found(): void
    {
        $this->get('/dhiran')->assertNotFound();
        $this->get('/dhiran/loans')->assertNotFound();
        $this->get('/product-preferences')->assertNotFound();
    }

    public function test_its_tables_are_gone_and_no_edition_value_allows_it(): void
    {
        foreach (['dhiran_loans', 'dhiran_payments', 'dhiran_settings', 'dhiran_attachments', 'product_recognitions', 'product_promotion_preferences'] as $table) {
            $this->assertFalse(Schema::hasTable($table), $table);
        }

        $this->assertSame(0, DB::table('permissions')->where('group', 'dhiran')->count());

        foreach (['shop_editions_edition_check', 'shop_edition_requests_edition_check'] as $check) {
            $def = DB::selectOne('SELECT pg_get_constraintdef(oid) AS d FROM pg_constraint WHERE conname = ?', [$check])->d;
            $this->assertStringContainsString("'retailer'", $def, $check);
            $this->assertStringNotContainsString('dhiran', $def, $check);
        }
    }

    public function test_migration_removes_leftover_rows_outside_production(): void
    {
        $permissionId = DB::table('permissions')->insertGetId([
            'name' => 'dhiran.view', 'display_name' => 'Legacy', 'group' => 'dhiran',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('platform_settings')->insert(['key' => 'dhiran_enabled', 'value' => '1', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('plans')->insert(['code' => 'dhiran_monthly', 'name' => 'Legacy', 'is_active' => DB::raw('true'), 'created_at' => now(), 'updated_at' => now()]);
        $keptPermissions = DB::table('permissions')->where('group', '!=', 'dhiran')->count();

        (require base_path(self::MIGRATION))->up();

        $this->assertFalse(DB::table('permissions')->where('id', $permissionId)->exists());
        $this->assertFalse(DB::table('platform_settings')->where('key', 'dhiran_enabled')->exists());
        $this->assertFalse(DB::table('plans')->where('code', 'dhiran_monthly')->exists());
        $this->assertSame($keptPermissions, DB::table('permissions')->where('group', '!=', 'dhiran')->count());
    }

    public function test_migration_refuses_to_run_in_production_when_loan_data_exists(): void
    {
        Schema::create('dhiran_loans', function (Blueprint $table) {
            $table->id();
        });
        DB::table('dhiran_loans')->insert(['id' => 1]);
        $permissionId = DB::table('permissions')->insertGetId([
            'name' => 'dhiran.view', 'display_name' => 'Legacy', 'group' => 'dhiran',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->app['env'] = 'production';
        try {
            (require base_path(self::MIGRATION))->up();
            $this->fail('The migration ran over existing loan data in production.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('dhiran_loans', $e->getMessage());
        } finally {
            $this->app['env'] = 'testing';
        }

        // The guard runs before any change.
        $this->assertTrue(DB::table('dhiran_loans')->exists());
        $this->assertTrue(DB::table('permissions')->where('id', $permissionId)->exists());
    }

    public function test_migration_refuses_in_production_while_a_shop_of_that_type_or_its_billing_exists(): void
    {
        $shop = $this->createShop('retailer');
        DB::table('shops')->where('id', $shop->id)->update(['shop_type' => 'dhiran']);
        $planId = DB::table('plans')->insertGetId(['code' => 'dhiran_yearly', 'name' => 'Legacy', 'is_active' => DB::raw('true'), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('shop_subscriptions')->insert([
            'shop_id' => $shop->id, 'plan_id' => $planId, 'status' => 'active', 'billing_cycle' => 'yearly',
            'starts_at' => now()->toDateString(), 'ends_at' => now()->addYear()->toDateString(),
            'grace_ends_at' => now()->addYear()->toDateString(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->app['env'] = 'production';
        try {
            (require base_path(self::MIGRATION))->up();
            $this->fail('The migration ran in production while a shop of the retired type existed.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('shops.shop_type=dhiran', $e->getMessage());
            $this->assertStringContainsString('subscriptions or invoices on its plans', $e->getMessage());
        } finally {
            $this->app['env'] = 'testing';
        }

        $this->assertTrue(DB::table('plans')->where('id', $planId)->exists());
    }

    /**
     * Production's only gold-loan shop was the owner's own test account (Goldlux, JF-2, created
     * 2026-06-19; owner confirmed 2026-11-27). Its data may go; the owner chose to erase its
     * billing too, so the retired plan disappears. Anything else still stops the migration.
     */
    public function test_in_production_the_owner_confirmed_test_shop_is_erased_and_retired(): void
    {
        [$shopId, $userId, $planId, $subId, $invoiceId] = $this->confirmedTestShopWithLoanData();

        $this->app['env'] = 'production';
        try {
            (require base_path(self::MIGRATION))->up();
        } finally {
            $this->app['env'] = 'testing';
        }

        $this->assertFalse(Schema::hasTable('dhiran_loans'));
        $this->assertFalse(DB::table('subscription_events')->where('shop_subscription_id', $subId)->exists());
        $this->assertFalse(DB::table('platform_invoices')->where('id', $invoiceId)->exists());
        $this->assertFalse(DB::table('shop_subscriptions')->where('id', $subId)->exists());
        $this->assertFalse(DB::table('plans')->where('id', $planId)->exists(), 'the retired plan must go once nothing bills on it');
        $shop = DB::table('shops')->where('id', $shopId)->first();
        $this->assertSame(['suspended', false], [$shop->access_mode, (bool) $shop->is_active]);
        $user = DB::table('users')->where('id', $userId)->first();
        $this->assertSame([false, 'suspended'], [(bool) $user->is_active, $user->employment_status]);
    }

    public function test_in_production_loan_data_of_any_other_shop_still_stops_it(): void
    {
        $other = $this->createShop('retailer');
        [$testShopId, , $planId] = $this->confirmedTestShopWithLoanData();
        DB::table('dhiran_loans')->insert(['id' => 2, 'shop_id' => $other->id]);

        $this->app['env'] = 'production';
        try {
            (require base_path(self::MIGRATION))->up();
            $this->fail('The migration ran over loan data of a shop the owner did not confirm.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('dhiran_loans', $e->getMessage());
        } finally {
            $this->app['env'] = 'testing';
        }

        $this->assertTrue(DB::table('plans')->where('id', $planId)->exists());
        $this->assertSame('active', DB::table('shops')->where('id', $testShopId)->value('access_mode'));
    }

    /** @return array{int, int, int, int, int} shop, user, plan, subscription, invoice ids */
    private function confirmedTestShopWithLoanData(): array
    {
        $shop = $this->createShop('retailer');
        DB::table('shops')->where('id', $shop->id)->update([
            'shop_type' => 'dhiran', 'name' => 'Goldlux', 'shop_code' => 'JF-2', 'created_at' => '2026-06-19 10:00:00',
            'access_mode' => 'active', 'is_active' => DB::raw('true'),
        ]);
        Schema::create('dhiran_loans', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shop_id');
        });
        DB::table('dhiran_loans')->insert(['id' => 1, 'shop_id' => $shop->id]);
        $userId = \App\Models\User::factory()->create(['shop_id' => $shop->id])->id;
        DB::table('users')->where('id', $userId)->update(['realm' => 'dhiran']);
        $planId = DB::table('plans')->insertGetId(['code' => 'dhiran_yearly', 'name' => 'Legacy', 'is_active' => DB::raw('true'), 'created_at' => now(), 'updated_at' => now()]);
        $subId = DB::table('shop_subscriptions')->insertGetId([
            'shop_id' => $shop->id, 'plan_id' => $planId, 'status' => 'active', 'billing_cycle' => 'yearly',
            'starts_at' => '2026-06-19', 'ends_at' => '2027-06-19', 'grace_ends_at' => '2027-06-19',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $admin = \App\Models\Platform\PlatformAdmin::create([
            'first_name' => 'Platform', 'last_name' => 'Admin', 'name' => 'Platform Admin',
            'email' => 'goldlux-admin@example.com', 'mobile_number' => '9000000999',
            'password' => \Illuminate\Support\Facades\Hash::make('password123'), 'role' => 'super_admin', 'is_active' => true,
        ]);
        DB::table('subscription_events')->insert([
            'shop_subscription_id' => $subId, 'shop_id' => $shop->id, 'admin_id' => $admin->id, 'event_type' => 'created',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $invoiceId = DB::table('platform_invoices')->insertGetId([
            'shop_id' => $shop->id, 'shop_subscription_id' => $subId, 'plan_id' => $planId,
            'invoice_number' => 'JF-TEST-0001', 'invoice_sequence' => 1, 'billing_cycle' => 'yearly',
            'billing_period_start' => '2026-06-19', 'billing_period_end' => '2027-06-19',
            'amount_before_tax' => 0, 'total_amount' => 0, 'issued_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$shop->id, $userId, $planId, $subId, $invoiceId];
    }
}
