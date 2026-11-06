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

    // The payment history read paid_at and method, which DhiranPayment does not have (payment_date, payment_method):
    // every date showed "---" and every payment showed "Cash", even a UPI disbursement.
    public function test_payment_history_shows_the_payment_date_and_method(): void
    {
        [$shop, $owner] = $this->dhiranShopWithOwner('9390000079');
        $loan = $this->makeLoan($shop, ['payment_method' => 'upi']);

        $html = $this->renderShow($shop, $owner, $loan->id);
        $history = substr($html, strpos($html, 'Payment History'), 4000);

        $this->assertStringContainsString(today()->subDays(120)->format('d M Y'), $history);
        $this->assertStringContainsString('Upi', $history);
        $this->assertStringNotContainsString('---</td>', $history);
    }

    // Pay Interest, Repay and Pre-Close posted the chosen method as `method`; the controller reads `payment_method`,
    // so a UPI or bank-transfer payment was recorded (payment row and cash book) as cash.
    public function test_payment_dialogs_post_the_method_the_controller_reads(): void
    {
        [$shop, $owner] = $this->dhiranShopWithOwner('9390000077');
        $loan = $this->makeLoan($shop);

        $html = $this->renderShow($shop, $owner, $loan->id);
        // Pay Interest, Repay, Pre-Close, and Release Item (since the release dialog sends what the controller needs).
        $this->assertSame(4, substr_count($html, 'name="payment_method"'));
        $this->assertStringNotContainsString('name="method"', $html);
        // The controller accepts cash, upi, card, bank_transfer; "Bank Transfer" was offered as value="bank".
        $this->assertSame(4, substr_count($html, '<option value="bank_transfer">Bank Transfer</option>'));
        $this->assertStringNotContainsString('value="bank"', $html);

        TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)
            ->post("https://dhiran.jewelflows.com/dhiran/loans/{$loan->id}/repay", ['amount' => 1000, 'payment_method' => 'upi'])
            ->assertRedirect());
        $this->assertSame('upi', TenantContext::runFor($shop->id, fn () => $this->fresh($loan->id)->payments()->latest('id')->first()->payment_method));
    }

    // The Release Item dialog sent only item_id; the controller requires payment_amount (and takes payment_method and
    // condition_note), so every release was refused, and the Dhiran layout never showed validation errors: nothing
    // happened on screen.
    public function test_release_item_dialog_sends_what_the_controller_requires_and_refusals_are_shown(): void
    {
        [$shop, $owner] = $this->dhiranShopWithOwner('9390000076');
        $loan = $this->makeLoan($shop);

        $html = $this->renderShow($shop, $owner, $loan->id);
        $release = substr($html, strpos($html, 'id="releaseItemModal"'), 3000);
        foreach (['name="item_id"', 'name="payment_amount"', 'name="payment_method"', 'name="condition_note"'] as $field) {
            $this->assertStringContainsString($field, $release);
        }

        $item = TenantContext::runFor($shop->id, fn () => $this->fresh($loan->id)->items()->first());
        TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)
            ->from("https://dhiran.jewelflows.com/dhiran/loans/{$loan->id}")
            ->post("https://dhiran.jewelflows.com/dhiran/loans/{$loan->id}/release-item", ['item_id' => $item->id])
            ->assertSessionHasErrors('payment_amount'));

        $shown = TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)
            ->get("https://dhiran.jewelflows.com/dhiran/loans/{$loan->id}")->getContent());
        $this->assertMatchesRegularExpression('/dh-flash-error">[^<]*payment amount/i', $shown);
    }

    // The cash book read source and method on each entry; DhiranCashEntry stores source_type and payment_method,
    // so both columns showed "-" for every entry.
    public function test_cash_book_shows_entry_source_and_method(): void
    {
        [$shop, $owner] = $this->dhiranShopWithOwner('9390000078');
        $this->makeLoan($shop, ['payment_method' => 'upi']);
        $from = today()->subDays(130)->toDateString();
        $to = today()->toDateString();

        $html = $this->actingAs($owner)
            ->get("https://dhiran.jewelflows.com/dhiran/reports/cashbook?from={$from}&to={$to}")
            ->assertOk()
            ->getContent();
        $rows = substr($html, strpos($html, '<tbody'), 3000);

        $this->assertStringContainsString('Disbursement', $rows);
        $this->assertStringContainsString('Upi', $rows);
    }
}
