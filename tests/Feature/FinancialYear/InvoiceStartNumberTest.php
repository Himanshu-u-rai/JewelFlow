<?php

namespace Tests\Feature\FinancialYear;

use App\Models\Invoice;
use App\Models\User;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * The invoice starting number (owner, 2026-11-16): free before a series' first bill; after that owner-only,
 * forward-only, the gap it leaves must be confirmed and is audited; Settings shows the next number.
 */
class InvoiceStartNumberTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    private User $owner;
    private $shop;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
        [$this->owner, $this->shop] = $this->createRetailerTenant();
        $this->travelTo(CarbonImmutable::parse('2026-11-16 12:00:00', 'Asia/Kolkata'));
    }

    private function save(User $as, array $fields)
    {
        return TenantContext::runFor($this->shop->id, fn () => $this->actingAs($as)
            ->patch(route('settings.update.billing'), array_merge(['invoice_prefix' => 'INV-', 'invoice_start_number' => 1001], $fields)));
    }

    private function sell(): Invoice
    {
        $customer = $this->createCustomer($this->shop->id);

        return TenantContext::runFor($this->shop->id, fn () => Invoice::issue([
            'shop_id' => $this->shop->id, 'customer_id' => $customer->id, 'gold_rate' => 7200,
            'subtotal' => 1000, 'gst' => 0, 'total' => 1000, 'status' => Invoice::STATUS_FINALIZED, 'finalized_at' => now(),
        ]));
    }

    /** A manager holding every permission (settings.edit included) — still not the owner. */
    private function manager(): User
    {
        $role = new \App\Models\Role();
        $role->forceFill(['name' => 'manager', 'display_name' => 'Manager', 'shop_id' => $this->shop->id])->save();
        $role->permissions()->sync(\App\Models\Permission::query()->pluck('id'));

        return $this->createOwnerUser($this->shop, $role);
    }

    public function test_before_the_first_bill_the_starting_number_changes_freely(): void
    {
        $this->save($this->owner, ['invoice_start_number' => 5001])->assertSessionHasNoErrors();

        $this->assertSame('INV-5001', $this->sell()->invoice_number);
    }

    public function test_after_billing_a_jump_must_be_confirmed_and_names_the_unused_numbers(): void
    {
        $this->sell();
        $this->sell(); // 1001, 1002

        $this->save($this->owner, ['invoice_start_number' => 5001])->assertSessionHasErrors('confirm_number_gap');
        $this->assertStringContainsString('INV-1003 to INV-5000', session('errors')->first('confirm_number_gap'));

        $this->assertSame('INV-1003', $this->sell()->invoice_number);
    }

    public function test_a_confirmed_jump_is_applied_and_audited(): void
    {
        $this->sell();

        $this->save($this->owner, ['invoice_start_number' => 5001, 'confirm_number_gap' => '1'])->assertSessionHasNoErrors();

        $this->assertSame('INV-5001', $this->sell()->invoice_number);
        $audit = DB::table('audit_logs')->where('shop_id', $this->shop->id)->where('action', 'invoice.start_number_changed')->first();
        $this->assertNotNull($audit);
        $this->assertStringContainsString('INV-1002', (string) $audit->description);
        $this->assertStringContainsString('INV-5000', (string) $audit->description);
    }

    public function test_after_billing_the_starting_number_cannot_go_back(): void
    {
        $this->sell();
        $this->sell();

        $this->save($this->owner, ['invoice_start_number' => 1, 'confirm_number_gap' => '1'])
            ->assertSessionHasErrors('invoice_start_number');

        $this->assertSame('INV-1003', $this->sell()->invoice_number);
    }

    public function test_after_billing_only_the_owner_may_change_it(): void
    {
        $this->sell();
        $manager = $this->manager();

        $response = $this->save($manager, ['invoice_start_number' => 5001, 'confirm_number_gap' => '1']);

        $response->assertSessionHasErrors('invoice_start_number');
        $this->assertSame(\App\Support\NotAvailable::MESSAGE, session('errors')->first('invoice_start_number'));
        $this->assertSame('INV-1002', $this->sell()->invoice_number);
    }

    public function test_saving_other_settings_without_touching_the_number_needs_no_confirmation(): void
    {
        $this->sell();

        $this->save($this->manager(), ['invoice_suffix' => '/X'])->assertSessionHasNoErrors();
    }

    public function test_settings_show_the_next_number_without_using_it_up(): void
    {
        DB::table('shop_billing_settings')->where('shop_id', $this->shop->id)
            ->update(['invoice_prefix' => 'INV/{FY}/', 'invoice_series_yearly_from' => '2026-27']);
        $this->sell();
        $this->sell();

        $page = fn () => TenantContext::runFor($this->shop->id, fn () => $this->actingAs($this->owner)->get(route('settings.edit', ['tab' => 'billing'])));
        $page()->assertSee('Next invoice number')->assertSee('INV/26-27/1003');
        $page()->assertSee('INV/26-27/1003'); // still the same: viewing never consumes a number

        $this->assertSame('INV/26-27/1003', $this->sell()->invoice_number);
    }
}
