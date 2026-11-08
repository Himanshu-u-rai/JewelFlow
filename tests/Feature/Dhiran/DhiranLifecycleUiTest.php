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
        // Pay Interest, Repay, Pre-Close, Release Item, and Renew (since 2026-11-08 renewal asks how its interest was paid).
        $this->assertSame(5, substr_count($html, 'name="payment_method"'));
        $this->assertStringNotContainsString('name="method"', $html);
        // The controller accepts cash, upi, card, bank_transfer; "Bank Transfer" was offered as value="bank".
        $this->assertSame(5, substr_count($html, '<option value="bank_transfer">Bank Transfer</option>'));
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

    // An item's release share was its loan value over the loan's total *market* value, so with a 75% LTV releasing the
    // only item asked for 75% of the outstanding principal and left the rest with no collateral at all.
    public function test_releasing_the_only_item_requires_the_whole_outstanding_principal(): void
    {
        [$shop] = $this->dhiranShopWithOwner('9390000075');
        $loan = $this->makeLoan($shop, ['loan_date' => today()->toDateString()]);

        TenantContext::runFor($shop->id, function () use ($loan) {
            $loan = $this->fresh($loan->id);
            $item = $loan->items()->first();
            $outstanding = (float) $loan->outstanding_principal;

            try {
                $this->service->releaseItem($loan, $item, round($outstanding * 0.75, 2), 'cash', '');
                $this->fail('Released the only pledged item for three quarters of the principal.');
            } catch (\LogicException $e) {
                $this->assertStringContainsString('needed to release this item', $e->getMessage());
            }
        });
    }

    // Each release's share was measured against the loan's original total, which never shrinks: two equal items on a
    // loan meant the second release asked for only half of what was left, leaving principal owed with no gold held.
    public function test_releasing_items_one_by_one_clears_the_whole_principal(): void
    {
        [$shop, $owner] = $this->dhiranShopWithOwner('9390000076');
        $loan = TenantContext::runFor($shop->id, function () use ($shop) {
            $customer = Customer::create(['shop_id' => $shop->id, 'first_name' => 'Two', 'last_name' => 'Items', 'mobile' => '9811130001']);
            $item = ['metal_type' => 'gold', 'purity' => 22, 'gross_weight' => 50, 'rate_per_gram_at_pledge' => 6000];

            return $this->service->createLoan($shop, $customer, [['description' => 'Chain'] + $item, ['description' => 'Bangle'] + $item], [
                'principal_amount' => 100000, 'gold_rate_on_date' => 6000, 'tenure_months' => 1, 'grace_period_days' => 0,
                'loan_date' => today()->toDateString(), 'created_by' => null,
            ]);
        });

        TenantContext::runFor($shop->id, function () use ($loan, $shop, $owner) {
            [$chain, $bangle] = $this->fresh($loan->id)->items()->orderBy('id')->get()->all();

            $this->service->releaseItem($this->fresh($loan->id), $chain, $this->service->releaseMinimum($this->fresh($loan->id), $chain), 'cash', '');
            $left = $this->fresh($loan->id);
            $this->assertEqualsWithDelta(50000, (float) $left->outstanding_principal, 0.01, 'first item released for half');

            // The last item carries all that is left; half of it is not enough.
            $this->assertEqualsWithDelta($left->totalOutstanding(), $this->service->releaseMinimum($left, $bangle), 0.01);
            try {
                $this->service->releaseItem($left, $bangle, 25000, 'cash', '');
                $this->fail('Released the last pledged item while half the remaining principal stayed owed.');
            } catch (\LogicException $e) {
                $this->assertStringContainsString('needed to release this item', $e->getMessage());
            }
            // The release dialog says what each item needs.
            $dialog = $this->renderShow($shop, $owner, $loan->id);
            $this->assertStringContainsString('pay at least ₹' . number_format($left->totalOutstanding(), 2), $dialog);

            $this->service->releaseItem($this->fresh($loan->id), $bangle, $left->totalOutstanding(), 'cash', '');
            $this->assertEqualsWithDelta(0, (float) $this->fresh($loan->id)->outstanding_principal, 0.01, 'principal left with no gold held');
        });
    }

    // The payment pays interest and penalty first, so a minimum of only the principal share let interest eat into it.
    public function test_release_minimum_includes_interest_due(): void
    {
        [$shop] = $this->dhiranShopWithOwner('9390000077');
        $loan = $this->makeLoan($shop, ['loan_date' => today()->subDays(20)->toDateString()]);

        TenantContext::runFor($shop->id, function () use ($loan) {
            $loan = $this->fresh($loan->id);
            $this->service->accrueInterest($loan);
            $loan = $this->fresh($loan->id);
            $this->assertGreaterThan(0, (float) $loan->outstanding_interest, 'test premise: interest is due');
            $item = $loan->items()->first();

            try {
                $this->service->releaseItem($loan, $item, (float) $loan->outstanding_principal, 'cash', '');
                $this->fail('Released the only item for the principal alone while interest was due.');
            } catch (\LogicException $e) {
                $this->assertStringContainsString('needed to release this item', $e->getMessage());
            }
            $this->assertEqualsWithDelta($loan->totalOutstanding(), $this->service->releaseMinimum($loan, $item), 0.01);
        });
    }

    // ── Full Dhiran QA 2026-11-08 ────────────────────────────────────────────────────────────────────────────────

    // The dashboard view read $stats[...] but the controller passed separate variables, so every tile showed 0.
    public function test_dashboard_tiles_show_the_real_figures(): void
    {
        [$shop, $owner] = $this->dhiranShopWithOwner('9390000080');
        $this->makeLoan($shop, ['loan_date' => today()->toDateString(), 'tenure_months' => 6]);

        $html = $this->actingAs($owner)->get('https://dhiran.jewelflows.com/dhiran')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/Active Loans<\/p>\s*<p class="dd-kpi-value">1</', $html);
        $this->assertMatchesRegularExpression('/Total Outstanding<\/p>\s*<p class="dd-kpi-value">₹100,000\.00</', $html);
    }

    // Payment types printed raw ("Pre_closure").
    public function test_payment_history_names_types_in_words(): void
    {
        [$shop, $owner] = $this->dhiranShopWithOwner('9390000081');
        $loan = $this->makeLoan($shop, ['loan_date' => today()->toDateString(), 'tenure_months' => 6]);
        TenantContext::runFor($shop->id, fn () => $this->service->preCloseLoan($this->fresh($loan->id)));

        $html = $this->renderShow($shop, $owner, $loan->id);
        $this->assertStringContainsString('Pre Closure', $html);
        $this->assertStringNotContainsString('Pre_closure', $html);
    }

    // A forfeiture notice is only for a loan past maturity and grace: the button showed (and the server refused it),
    // and the printable notice opened for any loan, telling the customer a future date had already passed.
    public function test_forfeiture_notice_is_not_offered_before_grace_ends(): void
    {
        [$shop, $owner] = $this->dhiranShopWithOwner('9390000082');
        $loan = $this->makeLoan($shop, ['loan_date' => today()->toDateString(), 'tenure_months' => 6]);

        $this->assertStringNotContainsString(route('dhiran.send-notice', $loan), $this->renderShow($shop, $owner, $loan->id));
        TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)
            ->get("https://dhiran.jewelflows.com/dhiran/loans/{$loan->id}/forfeiture-notice"))->assertRedirect();

        // Past maturity and grace: offered.
        $due = $this->makeLoan($shop);
        $this->assertStringContainsString(route('dhiran.send-notice', $due), $this->renderShow($shop, $owner, $due->id));
    }

    // The pledge receipt printed "Dhiran" where the lender's name belongs: the view read a $shop nobody passed.
    public function test_pledge_receipt_names_the_lender(): void
    {
        [$shop, $owner] = $this->dhiranShopWithOwner('9390000083');
        $loan = $this->makeLoan($shop);

        $html = TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)
            ->get("https://dhiran.jewelflows.com/dhiran/loans/{$loan->id}/receipt"))->assertOk()->getContent();
        $this->assertStringContainsString('receipt-shop-name">' . e($shop->name), $html);
    }

    // The interest report's cards summed only the rows on the current page, left out penalty-only payments, and showed
    // "Principal Collected" for just the payments that carried interest.
    public function test_interest_report_totals_cover_the_whole_period(): void
    {
        [$shop, $owner] = $this->dhiranShopWithOwner('9390000084');
        $loan = $this->makeLoan($shop);
        TenantContext::runFor($shop->id, function () use ($loan, $shop) {
            foreach ([[0, 0, 500], [0, 200, 0]] as $i => [$principal, $penalty, $interest]) {
                \App\Models\Dhiran\DhiranPayment::record([
                    'shop_id' => $shop->id, 'dhiran_loan_id' => $loan->id, 'payment_date' => today()->toDateString(),
                    'type' => 'interest_payment', 'amount' => $principal + $penalty + $interest, 'direction' => 'in', 'payment_method' => 'cash',
                    'interest_component' => $interest, 'penalty_component' => $penalty, 'principal_component' => $principal,
                    'processing_fee_component' => 0, 'outstanding_principal_after' => 100000, 'outstanding_interest_after' => 0,
                    'outstanding_penalty_after' => 0, 'created_by' => null,
                ]);
            }
        });

        $html = $this->actingAs($owner)->get('https://dhiran.jewelflows.com/dhiran/reports/interest')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/Penalty Collected<\/p>\s*<p[^>]*>200\.00</', $html, 'a penalty-only payment was left out');
        $this->assertStringNotContainsString('Principal Collected', $html);
    }

    // A refused New Loan (e.g. principal above the LTV limit) came back with the items list empty, so staff typed
    // every item again.
    public function test_a_refused_new_loan_keeps_what_was_typed(): void
    {
        [$shop, $owner] = $this->dhiranShopWithOwner('9390000085');
        $customer = TenantContext::runFor($shop->id, fn () => Customer::create(['shop_id' => $shop->id, 'first_name' => 'Keep', 'last_name' => 'Typed', 'mobile' => '9811130009']));
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);

        $page = TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)
            ->from('https://dhiran.jewelflows.com/dhiran/create')
            ->followingRedirects()
            ->post('https://dhiran.jewelflows.com/dhiran', [
                'customer_id' => $customer->id, 'principal_amount' => 40000, 'gold_rate_on_date' => 6000,
                'interest_rate_monthly' => 1.5, 'interest_type' => 'flat', 'tenure_months' => 6,
                'items' => [['description' => 'Gold ring', 'metal_type' => 'gold', 'value_mode' => 'metal', 'gross_weight' => 8, 'stone_weight' => 0, 'purity' => 22, 'rate_per_gram_at_pledge' => 6000]],
            ]))->assertOk()->getContent();

        $this->assertStringContainsString('exceeds maximum loan value', $page);
        $this->assertStringContainsString('principal_amount: 40000', $page);
        $this->assertStringContainsString('Gold ring', $page, 'the items typed were dropped');
    }

    // Renewing collects the interest due (at least the minimum interest), but the dialog never said so or asked how it
    // was paid: every renewal was booked as cash. The old loan also gave no way to its new loan.
    public function test_renewal_says_what_to_collect_records_how_and_links_both_loans(): void
    {
        [$shop, $owner] = $this->dhiranShopWithOwner('9390000086');
        $loan = $this->makeLoan($shop, ['loan_date' => today()->toDateString(), 'tenure_months' => 6]);

        $due = TenantContext::runFor($shop->id, fn () => $this->service->renewalDue($this->fresh($loan->id)));
        $this->assertGreaterThan(0, $due, 'test premise: the minimum interest applies');
        $this->assertStringContainsString('collect ₹' . number_format($due, 2), $this->renderShow($shop, $owner, $loan->id));

        $new = TenantContext::runFor($shop->id, fn () => $this->service->renewLoan($this->fresh($loan->id), null, null, 'upi'));
        $payment = \App\Models\Dhiran\DhiranPayment::withoutGlobalScopes()->where('dhiran_loan_id', $loan->id)->where('type', 'renewal_interest')->firstOrFail();
        $this->assertSame('upi', $payment->payment_method);
        $this->assertEqualsWithDelta($due, (float) $payment->amount, 0.01);
        $this->assertSame('upi', \App\Models\Dhiran\DhiranCashEntry::withoutGlobalScopes()->where('dhiran_payment_id', $payment->id)->value('payment_method'));

        $old = TenantContext::runFor($shop->id, function () use ($owner, $loan) {
            $this->actingAs($owner);
            view()->share('errors', new \Illuminate\Support\ViewErrorBag);
            $l = DhiranLoan::with(['customer', 'items', 'payments', 'renewals', 'renewedFrom'])->findOrFail($loan->id);

            return view('dhiran.show', ['loan' => $l])->render();
        });
        $this->assertStringContainsString(route('dhiran.show', $new), $old, 'the renewed loan does not lead to its new loan');
    }

    // Settings were disconnected end to end: the page showed placeholder values (it read keys the settings do not have),
    // saving always failed (the form's field names were not the ones the save required), and New Loan never got the
    // shop's defaults.
    public function test_settings_show_save_and_feed_new_loans(): void
    {
        [$shop, $owner] = $this->dhiranShopWithOwner('9390000087');
        DhiranSettings::getForShop($shop->id)->update(['default_interest_rate_monthly' => 2.25]);
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
        $as = fn (callable $fn) => TenantContext::runFor($shop->id, fn () => $fn($this->actingAs($owner)));

        $as(fn ($t) => $t->get('https://dhiran.jewelflows.com/dhiran/settings'))->assertOk()
            ->assertSee('name="default_interest_rate_monthly"', false)->assertSee('value="2.25"', false);

        $as(fn ($t) => $t->patch('https://dhiran.jewelflows.com/dhiran/settings', [
            'default_interest_rate_monthly' => 2.5, 'default_interest_type' => 'flat', 'default_penalty_rate_monthly' => 1,
            'default_ltv_ratio' => 70, 'high_value_ltv_ratio' => 65, 'high_value_threshold' => 300000,
            'min_loan_amount' => 1000, 'max_loan_amount' => 900000, 'processing_fee_type' => 'percent', 'processing_fee_value' => 1,
            'default_tenure_months' => 9, 'default_min_lock_months' => 0, 'default_min_interest_months' => 1,
            'grace_period_days' => 15, 'forfeiture_notice_days' => 20, 'loan_number_prefix' => 'PL-',
            'receipt_terms_text' => 'Pay on time.', 'closure_certificate_text' => '', 'sms_reminders_enabled' => '0', 'reminder_days_before_due' => 7,
        ]))->assertRedirect()->assertSessionHasNoErrors();

        $s = DhiranSettings::getForShop($shop->id)->fresh();
        $this->assertSame([2.5, 9, 70.0, 20, 'PL-', 'percent'], [(float) $s->default_interest_rate_monthly, (int) $s->default_tenure_months,
            (float) $s->default_ltv_ratio, (int) $s->forfeiture_notice_days, $s->loan_number_prefix, $s->processing_fee_type]);

        $create = $as(fn ($t) => $t->get('https://dhiran.jewelflows.com/dhiran/create'))->assertOk()->getContent();
        foreach (['interest_rate_monthly: 2.5', 'tenure_months: 9', 'ltvPercent: 70', 'penalty_rate_monthly: 1'] as $default) {
            $this->assertStringContainsString($default, $create, "New Loan ignores the shop's {$default}");
        }
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
