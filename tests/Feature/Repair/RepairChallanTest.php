<?php

namespace Tests\Feature\Repair;

use App\Models\CashTransaction;
use App\Models\Customer;
use App\Models\Permission;
use App\Models\Repair;
use App\Models\Role;
use App\Models\Shop;
use App\Models\ShopBillingSettings;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Repair intake challan: a receipt the customer keeps and brings back at
 * collection. It is a read-only view of the existing repair: printing it (or
 * downloading the PDF) never creates a repair, uses a number, records a payment
 * or moves the status. It shows only what was recorded.
 */
class RepairChallanTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestTenant;

    private const ERP = 'https://jewelflows.com';

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        $this->withoutMiddleware([
            \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
            \Illuminate\Routing\Middleware\ThrottleRequests::class,
        ]);
    }

    private function receive(User $owner, Shop $shop, Customer $customer, array $extra = []): Repair
    {
        TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)->post(self::ERP . '/repairs', array_merge([
            'customer_id' => $customer->id,
            'item_description' => 'Gold chain clasp',
            'gross_weight' => 5.25,
        ], $extra)))->assertSessionHasNoErrors();

        return Repair::withoutGlobalScopes()->where('shop_id', $shop->id)->latest('id')->firstOrFail();
    }

    private function challan(User $user, Shop $shop, Repair $repair, string $suffix = '')
    {
        return TenantContext::runFor($shop->id, fn () => $this->actingAs($user)->get(self::ERP . '/repairs/' . $repair->id . '/challan' . $suffix));
    }

    private function userWithPerms(Shop $shop, array $perms, string $mobile): User
    {
        return TenantContext::runFor($shop->id, function () use ($shop, $perms, $mobile) {
            $role = (new Role())->forceFill(['name' => 'r' . $mobile, 'display_name' => 'R', 'shop_id' => $shop->id]);
            $role->save();
            $role->permissions()->sync(Permission::whereIn('name', $perms)->pluck('id'));

            return User::create([
                'name' => 'U' . $mobile, 'mobile_number' => $mobile, 'password' => Hash::make('password'),
                'realm' => 'erp', 'is_active' => true, 'shop_id' => $shop->id, 'role_id' => $role->id,
            ]);
        });
    }

    public function test_creating_a_repair_leads_to_its_page_with_print_and_pdf_offered(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $customer = $this->createCustomer($shop->id);

        $response = TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)->post(self::ERP . '/repairs', [
            'customer_id' => $customer->id, 'item_description' => 'Ring resize', 'gross_weight' => 3,
        ]));
        $repair = Repair::withoutGlobalScopes()->where('shop_id', $shop->id)->sole();
        $response->assertRedirect(route('repairs.show', $repair));

        $page = TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)->get(self::ERP . '/repairs/' . $repair->id));
        $ref = 'REP-' . str_pad((string) $repair->repair_number, 3, '0', STR_PAD_LEFT);
        $page->assertOk()->assertSee('Print challan')->assertSee('Download PDF')->assertSee('Share challan')
            ->assertSee('data-filename="' . $ref . '-challan.pdf"', false)
            ->assertSee('Attach (📎) → Document', false)
            ->assertDontSee('wa.me', false)->assertDontSee('api.whatsapp', false)
            ->assertSee(route('repairs.challan', $repair), false)->assertSee(route('repairs.challan.pdf', $repair), false);
    }

    public function test_the_challan_shows_what_was_recorded_under_the_existing_reference(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $shop->forceFill(['name' => 'Sona Jewels', 'phone' => '9812300001', 'address' => '12 Main Road'])->save();
        $customer = $this->createCustomer($shop->id, ['first_name' => 'Asha', 'last_name' => 'Rao', 'mobile' => '9812300077']);
        $repair = $this->receive($owner, $shop, $customer, [
            'description' => 'Replace broken clasp and polish',
            'metal_type' => 'gold', 'purity' => '22',
            'estimated_cost' => 1500,
        ]);
        // The web form does not take a due date; the mobile app records one.
        Repair::withoutGlobalScopes()->whereKey($repair->id)->update(['due_date' => now()->addDays(5)->toDateString(), 'updated_at' => $repair->created_at]);
        $repair = $repair->fresh();
        $ref = 'REP-' . str_pad((string) $repair->repair_number, 3, '0', STR_PAD_LEFT);

        $this->challan($owner, $shop, $repair)->assertOk()
            ->assertSee('Repair Intake Challan')->assertSee($ref)
            ->assertSee('Sona Jewels')->assertSee('12 Main Road')
            ->assertSee('Asha Rao')->assertSee('98123 00077')
            ->assertSee('Gold chain clasp')->assertSee('Replace broken clasp and polish')
            ->assertSee('5.250 g')->assertSee('22K')
            ->assertSee($repair->created_at->format('d M Y'))
            ->assertSee(now()->addDays(5)->format('d M Y'))
            ->assertSee('Estimated charge')->assertSee('1,500.00')->assertSee('This is an estimate, not a payment')
            ->assertSee('None recorded')
            ->assertSee('Customer signature')->assertSee('Bring this challan when you collect the item');
    }

    public function test_fields_that_were_not_recorded_are_not_invented(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $repair = $this->receive($owner, $shop, $this->createCustomer($shop->id), ['metal_type' => 'other']);

        $this->challan($owner, $shop, $repair)->assertOk()
            ->assertDontSee('Purity')->assertDontSee('Estimated charge')->assertDontSee('Expected ready by')
            ->assertDontSee('Work requested')
            ->assertSee('None recorded') // payment received
            ->assertSee('Pieces and condition at intake (write by hand)'); // blank lines, not a recorded value
    }

    public function test_internal_and_later_billing_details_stay_off_the_customer_copy(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $repair = $this->receive($owner, $shop, $this->createCustomer($shop->id), ['estimated_cost' => 800]);
        Repair::withoutGlobalScopes()->whereKey($repair->id)->update(['final_cost' => 98765.43]);

        $this->challan($owner, $shop, $repair->fresh())->assertOk()
            ->assertDontSee('98,765.43')->assertDontSee('98765.43')->assertDontSee('Final cost')->assertDontSee('Invoice');
    }

    public function test_printing_and_downloading_again_change_nothing(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $repair = $this->receive($owner, $shop, $this->createCustomer($shop->id));
        $snapshot = fn () => [
            Repair::withoutGlobalScopes()->count(),
            Repair::withoutGlobalScopes()->whereKey($repair->id)->value('repair_number'),
            Repair::withoutGlobalScopes()->whereKey($repair->id)->value('status'),
            (string) Repair::withoutGlobalScopes()->whereKey($repair->id)->value('updated_at'),
            CashTransaction::withoutGlobalScopes()->count(),
            \App\Models\ShopCounter::withoutGlobalScopes()->where('shop_id', $shop->id)->pluck('current_value', 'counter_key')->all(),
        ];
        $before = $snapshot();

        $this->challan($owner, $shop, $repair)->assertOk();
        $this->challan($owner, $shop, $repair)->assertOk();
        $this->challan($owner, $shop, $repair, '/pdf'); // 200 with Chromium, 503 without: neither may write

        $this->assertSame($before, $snapshot());
    }

    public function test_another_shop_cannot_open_the_challan_or_its_pdf(): void
    {
        [$ownerA, $shopA] = $this->createRetailerTenant();
        $repairA = $this->receive($ownerA, $shopA, $this->createCustomer($shopA->id, ['first_name' => 'Secret']));
        [$ownerB, $shopB] = $this->createRetailerTenant();

        foreach (['', '/pdf'] as $suffix) {
            $response = $this->challan($ownerB, $shopB, $repairA, $suffix);
            $this->assertContains($response->status(), [403, 404], "challan{$suffix} must be refused across shops");
            $this->assertStringNotContainsString('Secret', (string) $response->getContent());
        }
    }

    public function test_view_permission_is_enough_and_required(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $repair = $this->receive($owner, $shop, $this->createCustomer($shop->id));

        $viewer = $this->userWithPerms($shop, ['repairs.view'], '9812300031');
        $this->challan($viewer, $shop, $repair)->assertOk();

        $other = $this->userWithPerms($shop, ['sales.view'], '9812300032');
        $this->challan($other, $shop, $repair)->assertForbidden();
        $this->challan($other, $shop, $repair, '/pdf')->assertForbidden();
    }

    public function test_a_copy_printed_after_the_record_changed_says_so(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $customer = $this->createCustomer($shop->id);
        $repair = $this->receive($owner, $shop, $customer);
        $this->challan($owner, $shop, $repair)->assertOk()->assertDontSee('changed after intake');

        $this->travel(2)->days();
        TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)->put(self::ERP . '/repairs/' . $repair->id, [
            'customer_id' => $customer->id, 'item_description' => 'Gold chain clasp and hook', 'gross_weight' => 5.25, 'status' => 'in_repair',
        ]))->assertSessionHasNoErrors();

        $this->challan($owner, $shop, $repair->fresh())->assertOk()
            ->assertSee('changed after intake')->assertSee(now()->format('d M Y'))
            ->assertSee('Gold chain clasp and hook')->assertSee('Current status: In repair');
    }

    public function test_long_descriptions_are_printed_in_full(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $long = str_repeat('Tighten every prong, re-solder the joint and polish. ', 18) . 'END-OF-NOTE';
        $repair = $this->receive($owner, $shop, $this->createCustomer($shop->id), ['description' => mb_substr($long, -1000)]);

        $this->challan($owner, $shop, $repair)->assertOk()->assertSee('END-OF-NOTE');
    }

    public function test_only_the_shops_own_terms_appear(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $repair = $this->receive($owner, $shop, $this->createCustomer($shop->id));
        $this->challan($owner, $shop, $repair)->assertOk()->assertDontSee('Shop terms');

        TenantContext::runFor($shop->id, fn () => ShopBillingSettings::query()->updateOrCreate(
            ['shop_id' => $shop->id], ['terms_and_conditions' => 'Goods once sold are subject to our policy.']
        ));
        $this->challan($owner, $shop, $repair)->assertOk()->assertSee('Shop terms')->assertSee('Goods once sold are subject to our policy.');
    }

    public function test_the_pdf_is_a_real_pdf_of_the_same_challan(): void
    {
        $path = config('reporting.chromium_path');
        if (! is_string($path) || $path === '' || ! file_exists($path)) {
            $this->markTestSkipped('Chromium binary not available at: ' . var_export($path, true));
        }
        [$owner, $shop] = $this->createRetailerTenant();
        $shop->forceFill(['name' => 'Sample Jewellers (synthetic)', 'phone' => '9000000001', 'address' => '1 Test Street', 'city' => 'Pune', 'state' => 'Maharashtra', 'pincode' => '411001'])->save();
        TenantContext::runFor($shop->id, fn () => ShopBillingSettings::query()->updateOrCreate(
            ['shop_id' => $shop->id], ['terms_and_conditions' => 'Sample shop terms (synthetic).']
        ));
        $customer = $this->createCustomer($shop->id, ['first_name' => 'Test', 'last_name' => 'Customer', 'mobile' => '9000000002']);
        $repair = $this->receive($owner, $shop, $customer, [
            'item_description' => 'Gold necklace with pendant',
            'description' => 'Re-solder the broken link near the clasp, tighten two loose stones and polish.',
            'metal_type' => 'gold', 'purity' => '22', 'gross_weight' => 18.42, 'estimated_cost' => 1250,
        ]);
        Repair::withoutGlobalScopes()->whereKey($repair->id)->update(['due_date' => now()->addDays(7)->toDateString(), 'updated_at' => $repair->created_at]);
        $ref = 'REP-' . str_pad((string) $repair->repair_number, 3, '0', STR_PAD_LEFT);

        $response = $this->challan($owner, $shop, $repair, '/pdf');
        $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $pdf = (string) $response->getContent();
        $this->assertStringStartsWith('%PDF', $pdf);
        if ($out = getenv('CHALLAN_SAMPLE_PDF')) {
            file_put_contents($out, $pdf);
        }
        $this->assertStringContainsString('attachment; filename="' . $ref . '-challan.pdf"', (string) $response->headers->get('Content-Disposition'));
        $this->assertStringNotContainsString('Customer', (string) $response->headers->get('Content-Disposition'), 'no personal data in the filename');
        // A5 portrait: 420 x 595 points.
        $this->assertMatchesRegularExpression('#/MediaBox\s*\[\s*0\s+0\s+(419\.\d+|420(\.\d+)?)\s+(594\.\d+|595(\.\d+)?)\s*\]#', $pdf);

    }
}
