<?php

namespace Tests\Feature;

use App\Models\CashTransaction;
use App\Models\Customer;
use App\Models\OnboardingBatch;
use App\Models\OnboardingEntry;
use App\Reporting\LedgerService;
use App\Reporting\ReportPeriod;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Onboarding Phase 6 — opening rows never pollute period income, even when the
 * report window spans go-live. The is_opening flag is the backstop: opening is
 * classified by (created_at < start OR is_opening), and period movement excludes
 * is_opening rows — so the closing identity (opening + in − out) still holds and
 * every rupee is conserved.
 */
class OnboardingReportingTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    public function test_opening_cash_is_not_counted_as_period_income(): void
    {
        [$user, $shop] = $this->createManufacturerTenant();

        // Batch: go-live 2026-08-01, as-of 2026-07-31.
        $this->actingAs($user)->post(route('onboarding.store'), ['start_date' => '2026-08-01']);
        $batch = OnboardingBatch::withoutTenant()->firstOrFail();

        TenantContext::set($shop->id);
        $this->actingAs($user)->post(route('onboarding.entries.store', $batch), [
            'kind' => OnboardingEntry::KIND_CASH, 'payment_mode' => 'cash', 'amount' => 100000,
        ])->assertSessionHasNoErrors();

        TenantContext::set($shop->id);
        $this->actingAs($user)->post(route('onboarding.lock', $batch));

        // A real post-start sale receipt inside the window.
        $live = new CashTransaction();
        $live->forceFill([
            'shop_id' => $shop->id, 'user_id' => $user->id, 'type' => 'in',
            'amount' => 7000, 'source_type' => 'sale', 'payment_mode' => 'cash',
            'created_at' => '2026-08-02 10:00:00', 'updated_at' => '2026-08-02 10:00:00',
        ]);
        $live->timestamps = false;
        $live->save();

        // Window deliberately spans go-live (starts on the as-of date).
        $period = ReportPeriod::range('2026-07-31', '2026-08-05');
        $cf = TenantContext::runFor($shop->id, fn () => app(LedgerService::class)->cashFlow($shop->id, $period));

        $this->assertEquals(100000, $cf->opening, 'Seeded opening must land in opening, even inside the window.');
        $this->assertEquals(7000, $cf->cashIn, 'Only the live receipt is period income — opening excluded.');
        $this->assertEquals(0, $cf->cashOut);
        $this->assertEquals(107000, $cf->closing, 'Closing identity: opening + in − out.');
    }

    public function test_opening_cash_excluded_from_day_book_and_cash_book_stat_cards(): void
    {
        [$user, $shop] = $this->createManufacturerTenant();

        $this->actingAs($user)->post(route('onboarding.store'), ['start_date' => '2026-08-01']);
        $batch = OnboardingBatch::withoutTenant()->firstOrFail();

        TenantContext::set($shop->id);
        $this->actingAs($user)->post(route('onboarding.entries.store', $batch), [
            'kind' => OnboardingEntry::KIND_CASH, 'payment_mode' => 'cash', 'amount' => 100000,
        ])->assertSessionHasNoErrors();

        TenantContext::set($shop->id);
        $this->actingAs($user)->post(route('onboarding.lock', $batch));

        // Day Book over a window that spans the as-of date must not count opening
        // as a period event.
        $period = ReportPeriod::range('2026-07-31', '2026-08-31');
        $db = TenantContext::runFor($shop->id, fn () => app(LedgerService::class)->dayBook($shop->id, $period));
        $this->assertEquals(0, $db->cashIn, 'Opening cash is a starting position, not a Day Book event.');

        // Cash Book stat cards: an opening row dated to today must not inflate the
        // today/month income cards. One opening + one live receipt, both dated now.
        TenantContext::set($shop->id);
        CashTransaction::record([
            'shop_id' => $shop->id, 'user_id' => $user->id, 'type' => 'in', 'amount' => 250000,
            'source_type' => 'opening_balance', 'payment_mode' => 'cash', 'is_opening' => true,
        ]);
        CashTransaction::record([
            'shop_id' => $shop->id, 'user_id' => $user->id, 'type' => 'in', 'amount' => 7000,
            'source_type' => 'sale', 'payment_mode' => 'cash',
        ]);

        $stats = $this->actingAs($user)->get(route('cashbook.index'))->assertOk()->viewData('stats');
        $this->assertEquals(7000, $stats['today_in'], 'Opening cash must not inflate today income.');
        $this->assertEquals(7000, $stats['month_in'], 'Opening cash must not inflate month income.');
    }

    public function test_wizard_and_supplier_views_render(): void
    {
        [$user, $shop] = $this->createManufacturerTenant();

        $this->actingAs($user)->post(route('onboarding.store'), ['start_date' => '2026-08-01']);
        $batch = OnboardingBatch::withoutTenant()->firstOrFail();

        TenantContext::set($shop->id);
        $this->actingAs($user)->post(route('onboarding.entries.store', $batch), [
            'kind' => OnboardingEntry::KIND_CASH, 'payment_mode' => 'cash', 'amount' => 500,
        ])->assertSessionHasNoErrors();

        // Wizard renders with a staged row (exercises _staged partial + review).
        TenantContext::set($shop->id);
        $this->actingAs($user)->get(route('onboarding.index'))->assertOk()->assertSee('Review');

        TenantContext::set($shop->id);
        $this->actingAs($user)->get(route('onboarding.suppliers'))->assertOk();
    }

    public function test_validator_stays_green_with_opening_batch_present(): void
    {
        [$user, $shop] = $this->createManufacturerTenant();

        $this->actingAs($user)->post(route('onboarding.store'), ['start_date' => '2026-08-01']);
        $batch = OnboardingBatch::withoutTenant()->firstOrFail();

        // Cash + vault metal opening — exercises CASH-4 and METAL-5.
        $stage = function (array $data) use ($user, $shop, $batch) {
            TenantContext::set($shop->id);
            $this->actingAs($user)
                ->post(route('onboarding.entries.store', $batch), $data)
                ->assertSessionHasNoErrors();
        };
        $stage(['kind' => OnboardingEntry::KIND_CASH, 'payment_mode' => 'cash', 'amount' => 100000]);
        $stage(['kind' => OnboardingEntry::KIND_VAULT_METAL, 'metal_type' => 'gold', 'purity' => 22, 'fine_weight' => 100, 'cost_per_fine_gram' => 5000]);

        TenantContext::set($shop->id);
        $this->actingAs($user)->post(route('onboarding.lock', $batch));

        // Read-only reconciliation over the go-live month: 0 = every invariant
        // (incl. CASH-4 opening sum and METAL-5 opening-lot sum) holds.
        $exit = $this->artisan('reports:validate', ['--shop' => $shop->id, '--month' => 8, '--year' => 2026]);
        $exit->assertExitCode(0);
    }

    public function test_customer_csv_import_dedupes_by_mobile(): void
    {
        [$user, $shop] = $this->createManufacturerTenant();

        // Pre-existing customer with a mobile that also appears in the CSV.
        $this->createCustomer($shop->id, ['mobile' => '9800000001']);

        $this->actingAs($user)->post(route('onboarding.store'), ['start_date' => '2026-08-01']);
        $batch = OnboardingBatch::withoutTenant()->firstOrFail();

        $csv = "first_name,last_name,mobile,email,address\n"
            . "Asha,Rao,9800000001,,\n"       // dup of existing → skip
            . "Bina,Sen,9800000002,,\n"       // new
            . "Bina,Sen,9800000002,,\n"       // dup within file → skip (created above)
            . "Nomobile,Person,,,\n";         // no mobile → skip

        $file = \Illuminate\Http\UploadedFile::fake()->createWithContent('customers.csv', $csv);

        TenantContext::set($shop->id);
        $this->actingAs($user)
            ->post(route('onboarding.customers.import', $batch), ['file' => $file])
            ->assertSessionHasNoErrors();

        // Original + exactly one new (9800000002).
        $this->assertSame(2, Customer::withoutTenant()->where('shop_id', $shop->id)->count());
        $this->assertSame(1, Customer::withoutTenant()->where('mobile', '9800000002')->count());
    }
}
