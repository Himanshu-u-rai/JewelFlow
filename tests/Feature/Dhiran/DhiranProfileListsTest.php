<?php

namespace Tests\Feature\Dhiran;

use App\Http\Controllers\DhiranController;
use App\Models\Customer;
use App\Models\Dhiran\DhiranLoan;
use App\Models\Dhiran\DhiranSettings;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Shop;
use App\Models\User;
use App\Services\DhiranService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Owner 2026-11-11: the customer profile listed every loan at once and only the last 25 payments. Loans get status
 * chips (with counts) and pages; payments show the full history in pages, with type and loan chips, a summary of
 * the filtered payments and a CSV export. Each table pages on its own and links land back on its section.
 */
class DhiranProfileListsTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;
    private User $owner;
    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->shop = Shop::create([
            'name' => 'Pawn Co', 'shop_type' => 'dhiran', 'phone' => '9390010000',
            'owner_first_name' => 'O', 'owner_last_name' => 'W', 'owner_mobile' => '9390010000',
            'country' => 'India', 'gst_rate' => 3.0, 'wastage_recovery_percent' => 0,
        ]);
        DhiranSettings::getForShop($this->shop->id)->update(['is_enabled' => true, 'processing_fee_value' => 0]);
        $role = new Role();
        $role->forceFill(['name' => 'owner', 'display_name' => 'Owner', 'shop_id' => $this->shop->id])->save();
        $role->permissions()->sync(Permission::query()->pluck('id'));
        $this->owner = User::create([
            'mobile_number' => '9390010000', 'password' => bcrypt('x'), 'realm' => 'dhiran',
            'is_active' => true, 'email_verified_at' => now(), 'shop_id' => $this->shop->id, 'role_id' => $role->id,
        ]);
        $this->customer = TenantContext::runFor($this->shop->id, fn () => Customer::create(['shop_id' => $this->shop->id, 'first_name' => 'Prof', 'last_name' => 'Ile', 'mobile' => '9819990001']));
    }

    /** @return list<DhiranLoan> */
    private function loans(int $n): array
    {
        return TenantContext::runFor($this->shop->id, fn () => array_map(fn ($i) => app(DhiranService::class)->createLoan($this->shop, $this->customer, [[
            'description' => 'Chain', 'metal_type' => 'gold', 'purity' => 22, 'gross_weight' => 50, 'rate_per_gram_at_pledge' => 6000,
        ]], ['principal_amount' => 10000 + $i, 'gold_rate_on_date' => 6000, 'created_by' => $this->owner->id, 'loan_date' => now()->subMonths(2)->toDateString()]), range(1, $n)));
    }

    private function profile(array $query = [])
    {
        return TenantContext::runFor($this->shop->id, function () use ($query) {
            $this->actingAs($this->owner);
            view()->share('errors', new \Illuminate\Support\ViewErrorBag);
            $req = Request::create('/dhiran/borrowers/' . $this->customer->id, 'GET', $query);
            $this->app->instance('request', $req);
            $res = app(DhiranController::class)->borrowerProfile($this->customer->fresh());
            if ($res instanceof \Illuminate\Contracts\View\View) {
                return $res->render();
            }
            ob_start();
            $res->sendContent(); // streamed inside the shop context, as a real request does

            return [$res, ob_get_clean()];
        });
    }

    private function section(string $html, string $id): string
    {
        $start = strpos($html, 'id="' . $id . '"');
        $this->assertNotFalse($start, "section #{$id}");
        $end = strpos($html, 'data-profile-section', $start + strlen('id="' . $id . '" data-profile-section'));

        return substr($html, $start, $end === false ? null : $end - $start);
    }

    public function test_loans_filter_by_status_with_counts_and_page(): void
    {
        $loans = $this->loans(12);
        TenantContext::runFor($this->shop->id, fn () => \Illuminate\Support\Facades\DB::table('dhiran_loans')->where('id', $loans[0]->id)->update(['status' => 'closed']));

        $all = $this->section($this->profile(), 'loans');
        // Owner: the standard Filters button + panel, not chip rows thrown on the page.
        $this->assertStringNotContainsString('dh-segment', $all);
        $this->assertMatchesRegularExpression('/<dialog[^>]*data-filter-panel/', $all);
        $this->assertMatchesRegularExpression('/value="active"[^>]*>\s*<span>Active\s*<em class="dh-fopt-count">11</', $all);
        $this->assertMatchesRegularExpression('/value="closed"[^>]*>\s*<span>Closed\s*<em class="dh-fopt-count">1</', $all);
        $this->assertStringContainsString('loans_page=2', $all, '10 per page');
        $this->assertStringContainsString('#loans', $all, 'links land on the section');

        $closed = $this->section($this->profile(['loan_status' => 'closed', 'payment_type' => 'disbursement']), 'loans');
        $this->assertStringContainsString($loans[0]->loan_number, $closed);
        $this->assertStringNotContainsString($loans[1]->loan_number, $closed);
        $this->assertMatchesRegularExpression('/<input type="radio" name="loan_status" value="closed" checked/', $closed);
        $this->assertMatchesRegularExpression('/<span class="dh-fbar-badge">1</', $closed);
        $chips = substr($closed, strpos($closed, 'data-active-filters'));
        $this->assertStringContainsString('Remove filter: Closed', $chips);
        $this->assertStringContainsString('payment_type=disbursement#loans"', $chips, 'removing it keeps the payments filter');
        $this->assertStringContainsString('<input type="hidden" name="payment_type" value="disbursement">', $closed, 'the panel keeps the other section\'s filter');

        // The other sections still cover every loan.
        $this->assertStringContainsString($loans[1]->loan_number, $this->section($this->profile(['loan_status' => 'closed']), 'items'));
    }

    public function test_payments_show_the_full_history_in_pages_with_chips_and_a_summary(): void
    {
        $loans = $this->loans(12); // each loan's payout is a payment: 12
        TenantContext::runFor($this->shop->id, function () use ($loans) {
            $svc = app(DhiranService::class);
            $svc->accrueInterest($loans[0]->refresh());
            $svc->recordInterestPayment($loans[0]->refresh(), 300, 'upi');
        });

        $all = $this->section($this->profile(), 'payments');
        $this->assertMatchesRegularExpression('/Showing\s*<strong>1–10<\/strong>\s*of\s*<strong>13<\/strong>\s*payments/u', $all, 'full history, 10 a page');
        $this->assertStringContainsString('payments_page=2', $all);
        $this->assertMatchesRegularExpression('/value="interest"[^>]*>\s*<span>Interest\s*<em class="dh-fopt-count">1</', $all, 'type choice with count');
        $this->assertStringNotContainsString('dh-segment', $all);

        $interest = $this->section($this->profile(['payment_type' => 'interest']), 'payments');
        $this->assertMatchesRegularExpression('/Showing\s*<strong>1–1<\/strong>\s*of\s*<strong>1<\/strong>/u', $interest);
        $this->assertStringContainsString('data-payments-summary', $interest);
        $this->assertStringContainsString('₹300.00', $interest);

        $oneLoan = $this->section($this->profile(['payment_loan' => $loans[0]->id]), 'payments');
        $this->assertMatchesRegularExpression('/of\s*<strong>2<\/strong>/u', $oneLoan, 'payout + interest of that loan');

        // CSV of this customer's (filtered) payments.
        [$res, $csv] = $this->profile(['export' => 'payments', 'payment_type' => 'interest']);
        $this->assertStringStartsWith('text/csv', $res->headers->get('Content-Type'));
        $rows = array_filter(explode("\n", $csv));
        $this->assertCount(2, $rows, 'header + the one interest payment');
    }

    public function test_bad_filters_are_refused_and_other_customers_loans_cannot_be_used(): void
    {
        $this->loans(1);
        $other = TenantContext::runFor($this->shop->id, function () {
            $c = Customer::create(['shop_id' => $this->shop->id, 'first_name' => 'Other', 'last_name' => 'One', 'mobile' => '9819990002']);
            return app(DhiranService::class)->createLoan($this->shop, $c, [[
                'description' => 'Ring', 'metal_type' => 'gold', 'purity' => 22, 'gross_weight' => 10, 'rate_per_gram_at_pledge' => 6000,
            ]], ['principal_amount' => 20000, 'gold_rate_on_date' => 6000, 'created_by' => $this->owner->id]);
        });
        foreach ([['loan_status' => 'eaten'], ['payment_type' => 'gift'], ['payment_loan' => $other->id]] as $bad) {
            try {
                $this->profile($bad);
                $this->fail('accepted ' . json_encode($bad));
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
