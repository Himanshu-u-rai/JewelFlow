<?php

namespace Tests\Feature\Mobile;

use App\Models\Customer;
use App\Models\ReorderRule;
use App\Models\Repair;
use App\Models\Role;
use App\Models\Shop;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\Support\ProfilePhotoFixtures;
use Tests\TestCase;

/**
 * Mobile companion API: customer profile photos and repair challans for the app,
 * through the same services and rules as the website, with bearer-token auth only
 * (no tokens or session data in URLs, no public files).
 */
class MobileCompanionApiTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        Storage::fake('local');
    }

    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        // Console route binding needs explicit context on every request; middleware clears it.
        $shopId = auth()->user()?->shop_id;
        $request = fn () => parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);

        return $shopId ? TenantContext::runFor($shopId, $request) : $request();
    }

    private function staff(Shop $shop, array $permissions): User
    {
        return TenantContext::runFor($shop->id, function () use ($shop, $permissions) {
            $role = Role::firstOrCreate(['name' => 'staff-' . implode('-', $permissions)], ['display_name' => 'Staff', 'shop_id' => $shop->id]);
            $role->syncPermissions($permissions);

            return User::factory()->create(['shop_id' => $shop->id, 'role_id' => $role->id, 'is_active' => true]);
        });
    }

    private function receive(User $user, Customer $customer, array $extra = []): Repair
    {
        Sanctum::actingAs($user, ['*']);
        $this->postJson('/api/mobile/repairs', array_merge([
            'customer_id' => $customer->id, 'item_description' => 'Gold chain clasp', 'gross_weight' => 5.25,
            'purity' => 22, 'estimated_cost' => 800,
        ], $extra))->assertCreated();

        return Repair::withoutGlobalScopes()->where('customer_id', $customer->id)->latest('id')->firstOrFail();
    }

    // ── Customer profile photos ────────────────────────────────────────────

    public function test_a_photo_is_uploaded_shown_and_removed_through_the_mobile_api(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $customer = $this->createCustomer($shop->id);
        Sanctum::actingAs($owner, ['*']);
        $base = "/api/mobile/customers/{$customer->id}/photo";

        $this->getJson($base)->assertOk()->assertExactJson(['photo' => null]);

        $saved = $this->post($base, ['customer_photo' => ProfilePhotoFixtures::portrait(), 'customer_photo_version' => ''], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('message', 'Customer photo saved.');
        $version = $saved->json('photo.version');
        $url = $saved->json('photo.url');
        $this->assertStringContainsString("/api/mobile/customers/{$customer->id}/photo", $url);
        $this->assertStringNotContainsString('token', $url);

        $file = $this->get($url, ['Accept' => 'image/webp']);
        $file->assertOk()->assertHeader('Content-Type', 'image/webp');
        $this->assertStringContainsString('no-store', (string) $file->headers->get('Cache-Control'));

        // The customer payloads say which photo version exists, so the app can refetch only on change.
        $this->getJson('/api/mobile/customers')->assertOk()->assertJsonPath('data.0.photo_version', $version);
        $this->getJson("/api/mobile/customers/{$customer->id}/context")->assertOk()->assertJsonPath('customer.photo_version', $version);

        // A stale version (another device replaced it) is refused, not overwritten.
        $this->post($base, ['customer_photo' => ProfilePhotoFixtures::portrait(), 'customer_photo_version' => '00000000-0000-4000-8000-000000000000'], ['Accept' => 'application/json'])
            ->assertStatus(409);

        $this->deleteJson($base, ['customer_photo_version' => $version])->assertOk()->assertJsonPath('photo', null);
        $this->getJson($base)->assertOk()->assertExactJson(['photo' => null]);
    }

    public function test_photos_keep_tenant_and_permission_boundaries(): void
    {
        [$ownerA, $shopA] = $this->createRetailerTenant();
        $customerA = $this->createCustomer($shopA->id);
        [$ownerB, $shopB] = $this->createRetailerTenant();
        Sanctum::actingAs($ownerA, ['*']);
        $this->post("/api/mobile/customers/{$customerA->id}/photo", ['customer_photo' => ProfilePhotoFixtures::portrait(), 'customer_photo_version' => ''], ['Accept' => 'application/json'])->assertOk();

        Sanctum::actingAs($ownerB, ['*']);
        $this->getJson("/api/mobile/customers/{$customerA->id}/photo")->assertNotFound();
        $this->get("/api/mobile/customers/{$customerA->id}/photo", ['Accept' => 'image/webp'])->assertNotFound();

        $viewer = $this->staff($shopA, ['customers.view']);
        Sanctum::actingAs($viewer, ['*']);
        $this->getJson("/api/mobile/customers/{$customerA->id}/photo")->assertOk();
        $this->deleteJson("/api/mobile/customers/{$customerA->id}/photo", ['customer_photo_version' => null])->assertForbidden();

        $this->app['auth']->forgetGuards();
        $this->getJson("/api/mobile/customers/{$customerA->id}/photo")->assertUnauthorized();
    }

    // ── Repairs: intake fields, partial edits, challan ─────────────────────

    public function test_create_accepts_the_intake_fields_and_show_carries_the_challan(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $shop->forceFill(['name' => 'Sona Jewels'])->save();
        $customer = $this->createCustomer($shop->id, ['mobile' => '9812300077']);
        $repair = $this->receive($owner, $customer, ['item_quantity' => 2, 'intake_condition' => 'Clasp bent', 'due_date' => '2026-11-03']);

        $this->assertSame([2, 'Clasp bent', '2026-11-03'], [$repair->item_quantity, $repair->intake_condition, $repair->due_date->toDateString()]);
        $ref = $repair->reference();
        $show = $this->getJson("/api/mobile/repairs/{$repair->id}")->assertOk()
            ->assertJsonPath('item_quantity', 2)->assertJsonPath('intake_condition', 'Clasp bent')
            ->assertJsonPath('challan.reference', $ref)
            ->assertJsonPath('challan.pdf_filename', "{$ref}-challan.pdf")
            ->assertJsonPath('challan.has_intake_copy', true)
            ->assertJsonPath('challan.legacy_notice', null)
            ->assertJsonPath('challan.intake.item_quantity', '2')
            ->assertJsonPath('challan.intake.due_date', '03 Nov 2026')
            ->assertJsonPath('challan.labels.intake_condition', 'Condition at receipt')
            ->assertJsonPath('challan.whatsapp.problem', null);
        $this->assertSame([], $show->json('challan.changes'));
        $message = "Hello from Sona Jewels. We have received your item for repair, reference {$ref}. Please keep this reference for collection.";
        $this->assertSame('https://wa.me/919812300077?text=' . rawurlencode($message), $show->json('challan.whatsapp.url'));
        $this->assertArrayNotHasKey('intake_snapshot', $show->json());
    }

    public function test_partial_edit_changes_only_sent_fields_clears_on_null_and_keeps_the_intake_copy(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $customer = $this->createCustomer($shop->id);
        $repair = $this->receive($owner, $customer, ['item_quantity' => 2, 'intake_condition' => 'Clasp bent', 'due_date' => '2026-11-03']);
        $intakeCopy = $repair->intake_snapshot;

        $this->putJson("/api/mobile/repairs/{$repair->id}", ['item_quantity' => 3])->assertOk()
            ->assertJsonPath('repair.item_quantity', 3)
            ->assertJsonPath('repair.intake_condition', 'Clasp bent')
            ->assertJsonPath('repair.challan.changes.item_quantity', '3');

        $this->putJson("/api/mobile/repairs/{$repair->id}", ['intake_condition' => null, 'due_date' => null])->assertOk()
            ->assertJsonPath('repair.intake_condition', null)->assertJsonPath('repair.due_date', null)
            ->assertJsonPath('repair.challan.changes.intake_condition', null)
            ->assertJsonPath('repair.challan.intake.intake_condition', 'Clasp bent');

        $fresh = $repair->fresh();
        $this->assertSame($intakeCopy, $fresh->intake_snapshot, 'edits never touch the intake copy');
        $this->assertSame(['Gold chain clasp', '5.250', '800.00'], [$fresh->item_description, number_format((float) $fresh->gross_weight, 3), (string) $fresh->estimated_cost]);

        $this->putJson("/api/mobile/repairs/{$repair->id}", ['item_quantity' => 0, 'intake_condition' => str_repeat('x', 501), 'due_date' => 'soon', 'item_description' => ''])
            ->assertUnprocessable()->assertJsonValidationErrors(['item_quantity', 'intake_condition', 'due_date', 'item_description']);
    }

    public function test_edit_respects_tenant_permission_and_delivered_boundaries(): void
    {
        [$ownerA, $shopA] = $this->createRetailerTenant();
        $repair = $this->receive($ownerA, $this->createCustomer($shopA->id));
        [$ownerB] = $this->createRetailerTenant();

        Sanctum::actingAs($ownerB, ['*']);
        $this->putJson("/api/mobile/repairs/{$repair->id}", ['item_quantity' => 3])->assertNotFound();

        Sanctum::actingAs($this->staff($shopA, ['repairs.view']), ['*']);
        $this->putJson("/api/mobile/repairs/{$repair->id}", ['item_quantity' => 3])->assertForbidden();

        Repair::withoutGlobalScopes()->whereKey($repair->id)->update(['status' => 'delivered']);
        Sanctum::actingAs($ownerA, ['*']);
        $this->putJson("/api/mobile/repairs/{$repair->id}", ['item_quantity' => 3])->assertStatus(422)
            ->assertJsonPath('message', 'Delivered repairs cannot be edited.');
        $this->assertNull($repair->fresh()->item_quantity);
    }

    public function test_a_repair_from_before_intake_copies_carries_the_legacy_notice(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $repair = $this->receive($owner, $this->createCustomer($shop->id, ['mobile' => '']));
        Repair::withoutGlobalScopes()->whereKey($repair->id)->update(['intake_snapshot' => null]);

        $this->getJson("/api/mobile/repairs/{$repair->id}")->assertOk()
            ->assertJsonPath('challan.has_intake_copy', false)
            ->assertJsonPath('challan.legacy_notice', 'This repair was received before JewelFlows kept an intake copy, so these are the current details and may include later changes.')
            ->assertJsonPath('challan.whatsapp.url', null)
            ->assertJsonPath('challan.whatsapp.problem', 'No mobile number is saved for this customer.');
    }

    public function test_the_challan_pdf_needs_a_token_and_the_same_shop(): void
    {
        [$ownerA, $shopA] = $this->createRetailerTenant();
        $repair = $this->receive($ownerA, $this->createCustomer($shopA->id));
        $url = "/api/mobile/repairs/{$repair->id}/challan/pdf";

        [$ownerB] = $this->createRetailerTenant();
        Sanctum::actingAs($ownerB, ['*']);
        $this->assertContains($this->get($url, ['Accept' => 'application/pdf'])->status(), [403, 404]);

        Sanctum::actingAs($this->staff($shopA, ['sales.view']), ['*']);
        $this->get($url, ['Accept' => 'application/pdf'])->assertForbidden();

        $this->app['auth']->forgetGuards();
        $signedOut = $this->get($url, ['Accept' => 'application/pdf']);
        $signedOut->assertUnauthorized();
        $this->assertStringNotContainsString('application/pdf', (string) $signedOut->headers->get('Content-Type'));
    }

    public function test_the_challan_pdf_is_a_real_pdf_for_the_app(): void
    {
        $path = config('reporting.chromium_path');
        if (! is_string($path) || $path === '' || ! file_exists($path)) {
            $this->markTestSkipped('Chromium binary not available at: ' . var_export($path, true));
        }
        [$owner, $shop] = $this->createRetailerTenant();
        $repair = $this->receive($owner, $this->createCustomer($shop->id), ['item_quantity' => 1]);

        $pdf = $this->get("/api/mobile/repairs/{$repair->id}/challan/pdf", ['Accept' => 'application/pdf']);
        $pdf->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', (string) $pdf->getContent());
        $this->assertStringContainsString('filename="' . $repair->reference() . '-challan.pdf"', (string) $pdf->headers->get('Content-Disposition'));
    }

    // ── Shop notices (low stock) ───────────────────────────────────────────

    public function test_the_dashboard_lists_every_low_stock_rule_for_the_notices_page(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        TenantContext::runFor($shop->id, function () use ($shop) {
            foreach (['Ring', 'Chain', 'Bangle', 'Earring', 'Pendant'] as $category) {
                ReorderRule::create(['category' => $category, 'min_stock_threshold' => 3, 'is_active' => true]);
            }
            ReorderRule::create(['category' => null, 'min_stock_threshold' => 50, 'is_active' => true]);
            ReorderRule::create(['category' => 'Ring', 'sub_category' => 'Band', 'min_stock_threshold' => 2, 'is_active' => true]);
            $this->createItem($shop->id, null, ['category' => 'Ring', 'sub_category' => 'Band']);
        });
        Sanctum::actingAs($owner, ['*']);

        $alerts = $this->getJson('/api/mobile/dashboard')->assertOk()->json('alerts');

        $this->assertSame(7, $alerts['reorder_count']);
        $this->assertCount(7, $alerts['reorder_items'], 'every rule, not the website dashboard\'s first four');
        $shopWide = collect($alerts['reorder_items'])->firstWhere('threshold', 50);
        $this->assertNull($shopWide['category'], 'a shop-wide rule stays null so the app does not filter stock by a made-up name');
        $band = collect($alerts['reorder_items'])->firstWhere('sub_category', 'Band');
        $this->assertSame(['category' => 'Ring', 'sub_category' => 'Band', 'current_stock' => 1, 'threshold' => 2], array_intersect_key($band, array_flip(['category', 'sub_category', 'current_stock', 'threshold'])));
    }

    public function test_the_dashboard_carries_the_websites_warnings_for_the_notices_page(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $customer = $this->createCustomer($shop->id);
        TenantContext::runFor($shop->id, function () use ($shop) {
            $shop->preferences()->updateOrCreate(['shop_id' => $shop->id], ['low_stock_threshold' => 5]);
            $this->createItem($shop->id);
            $this->createItem($shop->id);
        });
        foreach (range(1, 9) as $_) {
            $this->receive($owner, $customer);
        }
        Sanctum::actingAs($owner, ['*']);

        $warnings = $this->getJson('/api/mobile/dashboard')->assertOk()->json('alerts.warnings');

        $this->assertSame([
            ['key' => 'repair_backlog', 'count' => 9, 'message' => 'Repair backlog is high (9)'],
            ['key' => 'low_stock', 'count' => 2, 'message' => 'Low stock: only 2 item(s) in stock'],
        ], $warnings);
        $this->actingAs($owner)->get('/dashboard')->assertOk()
            ->assertSee('Repair backlog is high (9)')->assertSee('Low stock: only 2 item(s) in stock');
    }

    public function test_stock_can_be_filtered_by_sub_category_like_the_low_stock_rule(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        TenantContext::runFor($shop->id, function () use ($shop) {
            $this->createItem($shop->id, null, ['category' => 'Ring', 'sub_category' => 'Band']);
            $this->createItem($shop->id, null, ['category' => 'Ring', 'sub_category' => 'Solitaire']);
        });
        Sanctum::actingAs($owner, ['*']);

        $items = $this->getJson('/api/mobile/stock?category=ring&sub_category=band')->assertOk()->json('data');

        $this->assertSame(['Band'], array_column($items, 'sub_category'));
    }

    // ── Customer list: sort and KYC filter across all customers ─────────────

    public function test_the_customer_list_sorts_and_filters_on_the_server(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        TenantContext::runFor($shop->id, function () use ($shop) {
            $this->createCustomer($shop->id, ['first_name' => 'Zoya', 'last_name' => 'Ali', 'loyalty_points' => 5, 'pan' => 'ABCDE1234F']);
            $this->createCustomer($shop->id, ['first_name' => 'Amit', 'last_name' => 'Rao', 'loyalty_points' => 50, 'pan' => null]);
            $this->createCustomer($shop->id, ['first_name' => 'Meera', 'last_name' => 'Iyer', 'loyalty_points' => 20, 'pan' => '']);
        });
        Sanctum::actingAs($owner, ['*']);
        $names = fn (string $query) => array_column($this->getJson("/api/mobile/customers?$query")->assertOk()->json('data'), 'first_name');

        $this->assertSame(['Amit', 'Meera', 'Zoya'], $names('sort=name'));
        $this->assertSame(['Amit', 'Meera', 'Zoya'], $names('sort=loyalty'));
        $this->assertSame(['Zoya'], $names('kyc=verified'));
        $this->assertSame(['Amit', 'Meera'], $names('kyc=pending&sort=name'));
        $this->assertSame(1, $this->getJson('/api/mobile/customers?kyc=verified')->json('total'), 'the count matches the filter');
        $this->assertCount(3, $this->getJson('/api/mobile/customers?sort=bogus&kyc=bogus')->assertOk()->json('data'), 'unknown values are ignored');
    }

    public function test_repair_and_bill_lists_filter_by_customer_within_the_shop(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $asha = $this->createCustomer($shop->id, ['first_name' => 'Asha']);
        $ravi = $this->createCustomer($shop->id, ['first_name' => 'Ravi']);
        $this->receive($owner, $asha);
        $this->receive($owner, $asha);
        $this->receive($owner, $ravi);
        [, $otherShop] = $this->createRetailerTenant();
        $stranger = $this->createCustomer($otherShop->id);
        Sanctum::actingAs($owner, ['*']);

        $repairs = $this->getJson("/api/mobile/repairs?customer_id={$asha->id}")->assertOk();
        $this->assertSame(2, $repairs->json('total'));
        $this->assertSame([$asha->id, $asha->id], array_column($repairs->json('data'), 'customer_id'));
        $this->assertSame(3, $this->getJson('/api/mobile/repairs')->json('total'), 'no filter = all repairs, as before');
        $this->assertSame(0, $this->getJson("/api/mobile/repairs?customer_id={$stranger->id}")->json('total'), 'another shop\'s customer shows nothing');

        $this->getJson("/api/mobile/invoices?customer_id={$asha->id}")->assertOk()->assertJsonPath('total', 0);
        $this->getJson("/api/mobile/quick-bills?customer_id={$asha->id}")->assertOk()->assertJsonPath('total', 0);
    }

    public function test_the_repair_list_searches_item_customer_and_number(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $asha = $this->createCustomer($shop->id, ['first_name' => 'Asha', 'mobile' => '9812300001']);
        $ravi = $this->createCustomer($shop->id, ['first_name' => 'Ravi', 'mobile' => '9812300002']);
        $this->receive($owner, $asha, ['item_description' => 'Gold chain clasp']);
        $this->receive($owner, $ravi, ['item_description' => 'Silver anklet']);
        $number = Repair::withoutGlobalScopes()->where('customer_id', $ravi->id)->value('repair_number');
        Sanctum::actingAs($owner, ['*']);
        $items = fn (string $q) => array_column($this->getJson('/api/mobile/repairs?search=' . urlencode($q))->assertOk()->json('data'), 'item_description');

        $this->assertSame(['Silver anklet'], $items('anklet'));
        $this->assertSame(['Gold chain clasp'], $items('asha'));
        $this->assertSame(['Silver anklet'], $items('9812300002'));
        $this->assertSame(['Silver anklet'], $items('REP-' . str_pad((string) $number, 3, '0', STR_PAD_LEFT)));
        $this->assertCount(2, $this->getJson('/api/mobile/repairs')->json('data'), 'no search = all, as before');
    }

    public function test_the_released_app_still_creates_repairs_without_the_new_fields(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $repair = $this->receive($owner, $this->createCustomer($shop->id));

        $this->assertNull($repair->item_quantity);
        $this->assertNull($repair->intake_condition);
        $this->assertNotNull($repair->intake_snapshot);
    }

    // ── Scanner: any tag a shop already uses ────────────────────────────────

    public function test_the_scanner_finds_supplier_codes_with_slashes_and_symbols(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $codes = ['https://tags.example.com/t/GLG0145', 'RCPGR\\C0219--232', 'GLG01454'];
        TenantContext::runFor($shop->id, function () use ($shop, $codes) {
            foreach ($codes as $code) {
                $this->createItem($shop->id, null, ['barcode' => $code]);
            }
        });
        Sanctum::actingAs($owner, ['*']);

        foreach ($codes as $code) {
            // The app sends encodeURIComponent(code); rawurlencode is the same.
            $this->getJson('/api/mobile/items/barcode/' . rawurlencode($code))
                ->assertOk()
                ->assertJsonPath('barcode', $code);
        }
    }

    public function test_the_scanner_gets_the_shops_barcodes_to_check_reads_against(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        [, $otherShop] = $this->createRetailerTenant();
        TenantContext::runFor($shop->id, function () use ($shop) {
            $this->createItem($shop->id, null, ['barcode' => 'JF-0001']);
            $this->createItem($shop->id, null, ['barcode' => 'JF-0002', 'status' => 'sold']);
        });
        TenantContext::runFor($otherShop->id, fn () => $this->createItem($otherShop->id, null, ['barcode' => 'OTHER-1']));
        Sanctum::actingAs($owner, ['*']);

        $codes = $this->getJson('/api/mobile/items/barcodes')->assertOk()->json('barcodes');

        // Every tag the shop has issued (a sold tag still identifies its item), never another shop's.
        sort($codes);
        $this->assertSame(['JF-0001', 'JF-0002'], $codes);
    }

    public function test_the_barcode_list_needs_stock_access(): void
    {
        [, $shop] = $this->createRetailerTenant();
        $staff = $this->staff($shop, ['customers.view']);
        Sanctum::actingAs($staff, ['*']);

        $this->getJson('/api/mobile/items/barcodes')->assertForbidden();
    }
}
