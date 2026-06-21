<?php

namespace Tests\Feature\Dhiran;

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
use Tests\TestCase;

/**
 * Dhiran loan lifecycle UI reachability (workflow-completeness fixes):
 * Execute Forfeit, Print Forfeiture Notice, Print Closure Certificate, and
 * per-payment Print Receipt — all now reachable from the loan show page, gated by
 * status. Also verifies the print-document routes stay shop-scoped.
 */
class DhiranLifecycleUiTest extends TestCase
{
    use RefreshDatabase;

    private DhiranService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(DhiranService::class);
    }

    /** @return array{0:Shop,1:User} */
    private function dhiranShopWithOwner(string $mobile): array
    {
        $shop = Shop::create([
            'name' => 'Pawn Co', 'shop_type' => 'dhiran', 'phone' => '9990000070',
            'owner_first_name' => 'O', 'owner_last_name' => 'W', 'owner_mobile' => '9990000070',
            'country' => 'India', 'gst_rate' => 3.0, 'wastage_recovery_percent' => 0,
        ]);
        DhiranSettings::getForShop($shop->id)->update(['is_enabled' => true]);

        $role = new Role();
        $role->forceFill(['name' => 'owner', 'display_name' => 'Owner', 'shop_id' => $shop->id])->save();
        $role->permissions()->sync(Permission::query()->pluck('id'));

        $owner = User::create([
            'mobile_number' => $mobile, 'password' => bcrypt('x'), 'realm' => 'dhiran',
            'is_active' => true, 'email_verified_at' => now(), 'shop_id' => $shop->id, 'role_id' => $role->id,
        ]);

        return [$shop, $owner];
    }

    /** Create an active loan; loan_date defaults to today (can be backdated). */
    private function makeLoan(Shop $shop, array $params = []): DhiranLoan
    {
        return TenantContext::runFor($shop->id, function () use ($shop, $params) {
            $customer = Customer::create(['shop_id' => $shop->id, 'first_name' => 'C', 'last_name' => 'O', 'mobile' => '98' . fake()->unique()->numerify('########')]);
            return $this->service->createLoan($shop, $customer, [[
                'description' => 'Chain', 'metal_type' => 'gold', 'purity' => 22,
                'gross_weight' => 50, 'rate_per_gram_at_pledge' => 6000,
            ]], array_merge([
                'principal_amount' => 100000, 'gold_rate_on_date' => 6000,
                'tenure_months' => 1, 'grace_period_days' => 0,
                'loan_date' => today()->copy()->subDays(120)->toDateString(),
                'created_by' => null,
            ], $params));
        });
    }

    private function fresh(int $id): DhiranLoan
    {
        return TenantContext::runFor(DhiranLoan::withoutGlobalScope('shop')->find($id)->shop_id,
            fn () => DhiranLoan::findOrFail($id));
    }

    /**
     * Render the loan show view exactly as the controller does (compact('loan')
     * with the same relations), under the shop's tenant context + the acting user.
     * This deterministically exercises the status-gated action buttons without the
     * route-model-binding plumbing (which resolves under tenant middleware live).
     */
    private function renderShow(Shop $shop, User $owner, int $loanId): string
    {
        return TenantContext::runFor($shop->id, function () use ($owner, $loanId) {
            $this->actingAs($owner);
            view()->share('errors', new \Illuminate\Support\ViewErrorBag);
            $loan = DhiranLoan::with(['customer', 'items', 'payments'])->findOrFail($loanId);
            return view('dhiran.show', compact('loan'))->render();
        });
    }

    // 1 & 3. Execute Forfeit shows after notice sent + period elapsed; hidden before period.
    public function test_execute_forfeit_shows_after_notice_and_period(): void
    {
        [$shop, $owner] = $this->dhiranShopWithOwner('9390000070');
        // 0-day notice period so a sent notice can be executed today.
        DhiranSettings::getForShop($shop->id)->update(['forfeiture_notice_days' => 0]);
        $loan = $this->makeLoan($shop);

        // Before notice: no Execute Forfeit, Send Notice present.
        $html0 = $this->renderShow($shop, $owner, $loan->id);
        $this->assertStringContainsString('Send Notice', $html0);
        $this->assertStringNotContainsString('Execute Forfeit', $html0);

        // Send notice (backdate so the 0-day period is satisfied vs the datetime).
        TenantContext::runFor($shop->id, fn () => $this->service->sendForfeitureNotice($this->fresh($loan->id)));
        DhiranLoan::withoutGlobalScope('shop')->where('id', $loan->id)
            ->update(['forfeiture_notice_sent_at' => now()->subDays(1)]);

        $html1 = $this->renderShow($shop, $owner, $loan->id);
        $this->assertStringContainsString('Execute Forfeit', $html1);
        $this->assertStringNotContainsString('Send Notice', $html1);
    }

    // 2. Execute Forfeit hidden before notice sent.
    public function test_execute_forfeit_hidden_before_notice(): void
    {
        [$shop, $owner] = $this->dhiranShopWithOwner('9390000071');
        $loan = $this->makeLoan($shop);

        $this->assertStringNotContainsString('Execute Forfeit', $this->renderShow($shop, $owner, $loan->id));
    }

    // 3b. Execute Forfeit hidden when notice period NOT yet elapsed.
    public function test_execute_forfeit_hidden_before_period_elapsed(): void
    {
        [$shop, $owner] = $this->dhiranShopWithOwner('9390000072');
        DhiranSettings::getForShop($shop->id)->update(['forfeiture_notice_days' => 30]);
        $loan = $this->makeLoan($shop);

        TenantContext::runFor($shop->id, fn () => $this->service->sendForfeitureNotice($this->fresh($loan->id)));
        // Notice sent today, 30-day period → not elapsed.

        $html = $this->renderShow($shop, $owner, $loan->id);
        $this->assertStringContainsString('Print Forfeiture Notice', $html);
        $this->assertStringNotContainsString('Execute Forfeit', $html);
    }

    // 4 & 5. Execute Forfeit hidden after forfeiture; executing sets status forfeited.
    public function test_execute_forfeit_completes_and_is_not_repeatable(): void
    {
        [$shop, $owner] = $this->dhiranShopWithOwner('9390000073');
        DhiranSettings::getForShop($shop->id)->update(['forfeiture_notice_days' => 0]);
        $loan = $this->makeLoan($shop);

        TenantContext::runFor($shop->id, function () use ($loan) {
            $this->service->sendForfeitureNotice($this->fresh($loan->id));
            DhiranLoan::withoutGlobalScope('shop')->where('id', $loan->id)
                ->update(['forfeiture_notice_sent_at' => now()->subDays(1)]);
            $this->service->executeForfeit($this->fresh($loan->id));
        });

        $this->assertSame('forfeited', $this->fresh($loan->id)->status);
        $this->assertFalse($this->fresh($loan->id)->canExecuteForfeit());

        // Show page: no Execute Forfeit on a forfeited loan; notice still printable.
        $html = $this->renderShow($shop, $owner, $loan->id);
        $this->assertStringNotContainsString('Execute Forfeit', $html);
        $this->assertStringContainsString('Print Forfeiture Notice', $html);
    }

    // 6 & 7. Closure certificate link only on closed loans.
    public function test_closure_certificate_link_only_for_closed(): void
    {
        [$shop, $owner] = $this->dhiranShopWithOwner('9390000074');
        $loan = $this->makeLoan($shop);

        // Active → no closure cert.
        $this->assertStringNotContainsString('Print Closure Certificate', $this->renderShow($shop, $owner, $loan->id));

        // Pay it off → closed. Accrue first so the amount equals the post-accrual
        // total (recordPayment accrues before applying the payment).
        TenantContext::runFor($shop->id, function () use ($loan) {
            $this->service->accrueInterest($this->fresh($loan->id));
            $this->service->recordPayment($this->fresh($loan->id), $this->fresh($loan->id)->totalOutstanding());
        });
        $this->assertSame('closed', $this->fresh($loan->id)->status);

        $this->assertStringContainsString('Print Closure Certificate', $this->renderShow($shop, $owner, $loan->id));
    }

    // 8 & 9. Forfeiture notice link only after notice sent.
    public function test_forfeiture_notice_link_only_after_notice(): void
    {
        [$shop, $owner] = $this->dhiranShopWithOwner('9390000075');
        $loan = $this->makeLoan($shop);

        $this->assertStringNotContainsString('Print Forfeiture Notice', $this->renderShow($shop, $owner, $loan->id));

        TenantContext::runFor($shop->id, fn () => $this->service->sendForfeitureNotice($this->fresh($loan->id)));

        $this->assertStringContainsString('Print Forfeiture Notice', $this->renderShow($shop, $owner, $loan->id));
    }

    // 10. Each payment row links its payment receipt route.
    public function test_payment_rows_link_payment_receipt(): void
    {
        [$shop, $owner] = $this->dhiranShopWithOwner('9390000076');
        $loan = $this->makeLoan($shop);
        $payment = TenantContext::runFor($shop->id, fn () => $this->service->recordPayment($this->fresh($loan->id), 5000));

        $html = $this->renderShow($shop, $owner, $loan->id);
        $this->assertStringContainsString(route('dhiran.payment-receipt', [$loan->id, $payment->id]), $html);
        $this->assertStringContainsString('Print Receipt', $html);
    }

    // 11, 12, 13. Print-document routes are shop-scoped (cross-shop fails).
    public function test_print_routes_are_shop_scoped(): void
    {
        [$shopA, $ownerA] = $this->dhiranShopWithOwner('9390000077');
        [$shopB] = $this->dhiranShopWithOwner('9390000078');
        $loanB = $this->makeLoan($shopB);
        $paymentB = TenantContext::runFor($shopB->id, fn () => $this->service->recordPayment($this->fresh($loanB->id), 5000));

        // Owner A cannot reach Shop B's loan documents.
        $this->actingAs($ownerA)->get("https://dhiran.jewelflows.com/dhiran/loans/{$loanB->id}/closure-certificate")->assertNotFound();
        $this->actingAs($ownerA)->get("https://dhiran.jewelflows.com/dhiran/loans/{$loanB->id}/forfeiture-notice")->assertNotFound();
        $this->actingAs($ownerA)->get("https://dhiran.jewelflows.com/dhiran/loans/{$loanB->id}/payments/{$paymentB->id}/receipt")->assertNotFound();
    }
}
