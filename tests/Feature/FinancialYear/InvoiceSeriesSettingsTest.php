<?php

namespace Tests\Feature\FinancialYear;

use App\Models\Invoice;
use App\Models\Shop;
use App\Models\User;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Settings → Billing: choosing a continuous or a yearly invoice series (owner, 2026-11-16). Replaces the
 * "Auto-reset on New Financial Year" switch, which reused last year's numbers and stopped billing on 1 April.
 */
class InvoiceSeriesSettingsTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    private User $owner;
    private Shop $shop;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
        [$this->owner, $this->shop] = $this->createRetailerTenant();
        $this->travelTo(CarbonImmutable::parse('2026-11-16 12:00:00', 'Asia/Kolkata'));
    }

    private function save(array $fields)
    {
        return TenantContext::runFor($this->shop->id, fn () => $this->actingAs($this->owner)
            ->patch(route('settings.update.billing'), array_merge(['invoice_prefix' => 'INV-', 'invoice_start_number' => 1001], $fields)));
    }

    private function settings(): object
    {
        return DB::table('shop_billing_settings')->where('shop_id', $this->shop->id)->first();
    }

    private function sell(): Invoice
    {
        $customer = $this->createCustomer($this->shop->id);

        return TenantContext::runFor($this->shop->id, fn () => Invoice::issue([
            'shop_id' => $this->shop->id, 'customer_id' => $customer->id, 'gold_rate' => 7200,
            'subtotal' => 1000, 'gst' => 0, 'total' => 1000, 'status' => Invoice::STATUS_FINALIZED, 'finalized_at' => now(),
        ]))->refresh();
    }

    public function test_choosing_a_yearly_series_before_any_bill_this_year_starts_it_now(): void
    {
        $this->save(['invoice_prefix' => 'INV/{FY}/', 'invoice_numbering' => 'yearly'])->assertSessionHasNoErrors();

        $this->assertSame('2026-27', $this->settings()->invoice_series_yearly_from);
        $this->assertNull($this->settings()->invoice_series_yearly_until);
        $this->assertSame('INV/26-27/1001', $this->sell()->invoice_number);
    }

    public function test_choosing_a_yearly_series_after_billing_this_year_starts_it_next_april(): void
    {
        $this->sell(); // INV-1001 in 2026-27: this year's series is already continuous

        $this->save(['invoice_prefix' => 'INV/{FY}/', 'invoice_numbering' => 'yearly'])->assertSessionHasNoErrors();

        $this->assertSame('2027-28', $this->settings()->invoice_series_yearly_from);
        $this->assertSame('INV/26-27/1002', $this->sell()->invoice_number); // still the continuous counter this year
    }

    public function test_a_yearly_series_without_the_year_in_the_number_is_refused(): void
    {
        $this->save(['invoice_prefix' => 'INV-', 'invoice_numbering' => 'yearly'])
            ->assertSessionHasErrors('invoice_prefix');

        $this->assertNull($this->settings()->invoice_series_yearly_from);
    }

    public function test_removing_the_year_token_while_a_yearly_series_runs_is_refused(): void
    {
        $this->save(['invoice_prefix' => 'INV/{FY}/', 'invoice_numbering' => 'yearly'])->assertSessionHasNoErrors();

        $this->save(['invoice_prefix' => 'INV-', 'invoice_numbering' => 'yearly'])->assertSessionHasErrors('invoice_prefix');
        $this->assertSame('INV/{FY}/', $this->settings()->invoice_prefix);
    }

    public function test_the_suffix_can_carry_the_year_instead(): void
    {
        $this->save(['invoice_prefix' => 'INV-', 'invoice_suffix' => '/{FY}', 'invoice_numbering' => 'yearly'])->assertSessionHasNoErrors();

        $this->assertSame('INV-1001/26-27', $this->sell()->invoice_number);
    }

    public function test_only_the_fy_token_may_use_braces(): void
    {
        $this->save(['invoice_prefix' => 'INV/{YEAR}/', 'invoice_numbering' => 'continuous'])->assertSessionHasErrors('invoice_prefix');
        $this->save(['invoice_prefix' => 'INV-', 'invoice_suffix' => '{fy}', 'invoice_numbering' => 'continuous'])->assertSessionHasErrors('invoice_suffix');
    }

    public function test_switching_back_to_continuous_mid_year_takes_effect_next_april(): void
    {
        $this->save(['invoice_prefix' => 'INV/{FY}/', 'invoice_numbering' => 'yearly']);
        $this->sell(); // yearly running this year

        $this->save(['invoice_prefix' => 'INV/{FY}/', 'invoice_numbering' => 'continuous'])->assertSessionHasNoErrors();

        $this->assertSame('2026-27', $this->settings()->invoice_series_yearly_from);
        $this->assertSame('2027-28', $this->settings()->invoice_series_yearly_until);
        $this->assertSame('INV/26-27/1002', $this->sell()->invoice_number); // this year's series is unchanged
    }

    public function test_cancelling_a_planned_yearly_series_before_it_starts_clears_it(): void
    {
        $this->sell();
        $this->save(['invoice_prefix' => 'INV/{FY}/', 'invoice_numbering' => 'yearly']); // planned for 2027-28

        $this->save(['invoice_prefix' => 'INV/{FY}/', 'invoice_numbering' => 'continuous'])->assertSessionHasNoErrors();

        $this->assertNull($this->settings()->invoice_series_yearly_from);
        $this->assertNull($this->settings()->invoice_series_yearly_until);
    }

    public function test_a_starting_number_change_moves_the_running_yearly_counter_forward_never_back(): void
    {
        $this->save(['invoice_prefix' => 'INV/{FY}/', 'invoice_numbering' => 'yearly']);
        $this->sell(); // 1001
        $this->sell(); // 1002

        // After billing a jump must be confirmed (InvoiceStartNumberTest has the full rules).
        $this->save(['invoice_prefix' => 'INV/{FY}/', 'invoice_numbering' => 'yearly', 'invoice_start_number' => 5001, 'confirm_number_gap' => '1'])
            ->assertSessionHasNoErrors();
        $this->assertSame('INV/26-27/5001', $this->sell()->invoice_number);

        $this->save(['invoice_prefix' => 'INV/{FY}/', 'invoice_numbering' => 'yearly', 'invoice_start_number' => 1])
            ->assertSessionHasErrors('invoice_start_number');
        $this->assertSame('INV/26-27/5002', $this->sell()->invoice_number);
    }

    public function test_the_old_reset_switch_is_gone_and_the_new_choice_is_shown(): void
    {
        $page = TenantContext::runFor($this->shop->id, fn () => $this->actingAs($this->owner)->get(route('settings.edit', ['tab' => 'billing'])));

        $page->assertOk()
            ->assertDontSee('Auto-reset on New Financial Year')
            ->assertSee('name="invoice_numbering"', false)
            ->assertSee('{FY}');
    }

    public function test_a_planned_change_is_explained_on_the_page(): void
    {
        $this->sell();
        $this->save(['invoice_prefix' => 'INV/{FY}/', 'invoice_numbering' => 'yearly']);

        TenantContext::runFor($this->shop->id, fn () => $this->actingAs($this->owner)->get(route('settings.edit', ['tab' => 'billing'])))
            ->assertSee('Numbers restart every year from 1 April 2027');
    }

    public function test_an_old_form_posting_year_reset_is_harmless(): void
    {
        $this->save(['year_reset' => '1'])->assertSessionHasNoErrors();

        $this->assertNull($this->settings()->invoice_series_yearly_from);
        $this->assertSame('INV-1001', $this->sell()->invoice_number);
    }
}
