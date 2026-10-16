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
 * or moves the status. It shows only what was recorded, and what was recorded
 * at intake stays apart from anything changed later.
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

    public function test_the_web_intake_form_offers_and_saves_quantity_condition_and_expected_date(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $customer = $this->createCustomer($shop->id);

        TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)->get(self::ERP . '/repairs'))->assertOk()
            ->assertSee('name="item_quantity"', false)
            ->assertSee('name="intake_condition"', false)
            ->assertSee('name="due_date"', false)
            ->assertSee('Expected ready by');

        $due = now()->addDays(6)->toDateString();
        $repair = $this->receive($owner, $shop, $customer, [
            'item_quantity' => 2, 'intake_condition' => 'Clasp bent; one stone missing', 'due_date' => $due,
        ]);

        $this->assertSame(2, (int) $repair->item_quantity);
        $this->assertSame('Clasp bent; one stone missing', $repair->intake_condition);
        $this->assertSame($due, $repair->due_date->toDateString());

        TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)->get(self::ERP . '/repairs/' . $repair->id))->assertOk()
            ->assertSee('Clasp bent; one stone missing')->assertSee(now()->addDays(6)->format('d M Y'));
    }

    public function test_intake_fields_are_validated(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $customer = $this->createCustomer($shop->id);

        TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)->post(self::ERP . '/repairs', [
            'customer_id' => $customer->id, 'item_description' => 'Ring', 'gross_weight' => 3,
            'item_quantity' => 0, 'intake_condition' => str_repeat('x', 501), 'due_date' => 'not-a-date',
        ]))->assertSessionHasErrors(['item_quantity', 'intake_condition', 'due_date']);

        $this->assertSame(0, Repair::withoutGlobalScopes()->where('shop_id', $shop->id)->count());
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
            'item_quantity' => 3, 'intake_condition' => 'Scratches on the back',
            'due_date' => now()->addDays(5)->toDateString(),
        ]);
        $ref = 'REP-' . str_pad((string) $repair->repair_number, 3, '0', STR_PAD_LEFT);

        $this->challan($owner, $shop, $repair)->assertOk()
            ->assertSee('Repair Intake Challan')->assertSee($ref)
            ->assertSee('Sona Jewels')->assertSee('12 Main Road')
            ->assertSee('Asha Rao')->assertSee('98123 00077')
            ->assertSee('As received at intake')
            ->assertSee('Gold chain clasp')->assertSee('Replace broken clasp and polish')
            ->assertSee('5.250 g')->assertSee('22K')
            ->assertSee('Quantity (pieces)')->assertSee('Scratches on the back')
            ->assertSeeInOrder(['Intake date', $repair->created_at->format('d M Y'), 'Copy generated'])
            ->assertSee(now()->addDays(5)->format('d M Y'))
            ->assertSee('Estimated charge')->assertSee('1,500.00')->assertSee('This is an estimate, not a payment')
            ->assertSee('do not track advances')->assertDontSee('None recorded')
            ->assertDontSee('write by hand')->assertDontSee('Changed after intake')
            ->assertSee('Customer signature')->assertSee('Bring this challan when you collect the item');
    }

    public function test_fields_that_were_not_recorded_are_not_invented(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $repair = $this->receive($owner, $shop, $this->createCustomer($shop->id), ['metal_type' => 'other']);

        $this->challan($owner, $shop, $repair)->assertOk()
            ->assertDontSee('Purity')->assertDontSee('Estimated charge')->assertDontSee('Expected ready by')
            ->assertDontSee('Work requested')
            ->assertSeeInOrder(['Quantity (pieces)', 'Not recorded', 'Condition at receipt', 'Not recorded'])
            ->assertSee('do not track advances')->assertDontSee('None recorded');
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
            json_encode(Repair::withoutGlobalScopes()->whereKey($repair->id)->value('intake_snapshot')),
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

    public function test_a_reprint_after_edits_keeps_the_intake_details_and_lists_the_changes_apart(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $customer = $this->createCustomer($shop->id);
        $repair = $this->receive($owner, $shop, $customer, ['estimated_cost' => 800, 'item_quantity' => 1]);
        $intakeCopy = $repair->intake_snapshot;
        $this->challan($owner, $shop, $repair)->assertOk()->assertDontSee('Changed after intake');

        $this->travel(2)->days();
        TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)->put(self::ERP . '/repairs/' . $repair->id, [
            'customer_id' => $customer->id, 'item_description' => 'Gold chain clasp and hook',
            'gross_weight' => 6.1, 'estimated_cost' => 1200, 'status' => 'in_repair',
        ]))->assertSessionHasNoErrors();
        $repair = $repair->fresh();
        $this->assertSame('in_repair', $repair->status);

        $this->assertSame($intakeCopy, $repair->intake_snapshot, 'the intake copy is written once');
        $this->challan($owner, $shop, $repair)->assertOk()
            ->assertSeeInOrder([
                'Intake date', $repair->created_at->format('d M Y'), 'Copy generated', now()->format('d M Y'),
                'As received at intake', 'Gold chain clasp', '5.250 g', '800.00',
                'Changed after intake', 'Gold chain clasp and hook', '6.100 g', '1,200.00',
                'Current status', 'In repair',
            ]);
    }

    public function test_a_status_change_alone_is_shown_as_the_current_status_only(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $repair = $this->receive($owner, $shop, $this->createCustomer($shop->id));
        TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)->patch(self::ERP . '/repairs/' . $repair->id . '/status', ['status' => 'ready']));
        $this->assertSame('ready', $repair->fresh()->status);

        $this->challan($owner, $shop, $repair->fresh())->assertOk()
            ->assertSeeInOrder(['As received at intake', 'Gold chain clasp', 'Changed after intake', 'Current status', 'Ready for collection'])
            ->assertSee('Gold chain clasp')->assertDontSee('Item (now)');
    }

    public function test_a_repair_received_before_intake_copies_says_its_details_are_current(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $repair = $this->receive($owner, $shop, $this->createCustomer($shop->id));
        Repair::withoutGlobalScopes()->whereKey($repair->id)->update(['intake_snapshot' => null]);

        $this->challan($owner, $shop, $repair->fresh())->assertOk()
            ->assertSee('Details as currently recorded')
            ->assertSee('before JewelFlows kept an intake copy')
            ->assertDontSee('As received at intake')
            ->assertSeeInOrder(['Quantity (pieces)', 'Not recorded']);
    }

    public function test_the_migration_is_additive_and_existing_repairs_get_no_invented_values(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $repair = $this->receive($owner, $shop, $this->createCustomer($shop->id), ['estimated_cost' => 700]);
        $migration = require database_path('migrations/2026_10_16_000001_add_intake_details_to_repairs_table.php');

        // Back to the schema before this release: the repair row itself is untouched.
        $migration->down();
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumns('repairs', ['item_quantity', 'intake_condition', 'intake_snapshot']));
        $this->assertSame('Gold chain clasp', \Illuminate\Support\Facades\DB::table('repairs')->where('id', $repair->id)->value('item_description'));

        // Forward again: the existing repair gets NULLs, never a backfilled value.
        $migration->up();
        $row = \Illuminate\Support\Facades\DB::table('repairs')->where('id', $repair->id)->first();
        $this->assertNull($row->item_quantity);
        $this->assertNull($row->intake_condition);
        $this->assertNull($row->intake_snapshot);
        $this->assertSame('700.00', (string) $row->estimated_cost);

        $this->challan($owner, $shop, $repair->fresh())->assertOk()->assertSee('Details as currently recorded');
    }

    public function test_the_intake_copy_cannot_be_posted_or_rewritten(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $repair = $this->receive($owner, $shop, $this->createCustomer($shop->id), [
            'intake_snapshot' => ['item_description' => 'Forged'],
        ]);
        $this->assertSame('Gold chain clasp', $repair->intake_snapshot['item_description']);

        $this->expectException(\DomainException::class);
        TenantContext::runFor($shop->id, fn () => $repair->forceFill(['intake_snapshot' => ['item_description' => 'Forged']])->save());
    }

    public function test_long_descriptions_are_printed_in_full(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $long = str_repeat('Tighten every prong, re-solder the joint and polish. ', 18) . 'END-OF-NOTE';
        $repair = $this->receive($owner, $shop, $this->createCustomer($shop->id), [
            'description' => mb_substr($long, -1000),
            'intake_condition' => str_repeat('Dented. ', 60) . 'COND-END',
        ]);

        $this->challan($owner, $shop, $repair)->assertOk()->assertSee('END-OF-NOTE')->assertSee('COND-END');
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

    public function test_a_signed_out_pdf_request_never_returns_a_pdf(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $repair = $this->receive($owner, $shop, $this->createCustomer($shop->id));
        auth()->logout();

        $page = $this->get(self::ERP . '/repairs/' . $repair->id . '/challan/pdf');
        $page->assertRedirect();
        $this->assertStringNotContainsString('application/pdf', (string) $page->headers->get('Content-Type'));

        $this->getJson(self::ERP . '/repairs/' . $repair->id . '/challan/pdf')->assertUnauthorized();
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
            'item_quantity' => 2, 'intake_condition' => 'Pendant loop bent; two stones loose; light scratches.',
            'due_date' => now()->addDays(7)->toDateString(),
        ]);
        if (getenv('CHALLAN_SAMPLE_EDITED')) {
            // Sample of a reprint after an edit: the intake details stay, the change is listed apart.
            $this->travel(1)->days();
            TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)->put(self::ERP . '/repairs/' . $repair->id, [
                'customer_id' => $customer->id, 'item_description' => 'Gold necklace with pendant',
                'description' => 'Re-solder the broken link near the clasp, tighten two loose stones and polish.',
                'metal_type' => 'gold', 'purity' => '22', 'gross_weight' => 18.39, 'estimated_cost' => 1450, 'status' => 'in_repair',
            ]))->assertSessionHasNoErrors();
        }
        $ref = 'REP-' . str_pad((string) $repair->repair_number, 3, '0', STR_PAD_LEFT);

        $response = $this->challan($owner, $shop, $repair->fresh(), '/pdf');
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
