<?php

namespace Tests\Feature\Tables;

use App\Models\Customer;
use App\Models\InstallmentPlan;
use App\Models\Invoice;
use App\Models\LoyaltyTransaction;
use App\Models\QuickBill;
use App\Models\Repair;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\LengthAwarePaginator;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

class CustomerRowNumbersUiTest extends TestCase
{
    use CreatesTestTenant, RefreshDatabase;

    public function test_directory_and_loyalty_numbers_follow_their_own_pagination_and_filtered_results(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        for ($i = 1; $i <= 16; $i++) {
            // Read-only presentation fixtures; no synthetic sales/ledger writes needed.
            $this->createCustomer($shop->id, [
                'first_name' => sprintf('Indexed %02d', $i), 'loyalty_points' => 100 + $i,
                'date_of_birth' => today()->addDays(3)->subYears(30),
            ]);
        }
        $this->withoutVite()->actingAs($owner);
        $html = $this->get('/customers?page=2')->assertOk()->getContent();
        $this->assertNumbers($html, 'customers-data-table--directory', [16]);
        $this->assertMobileNumbers($html, 'customers-mobile-cards--directory', [16]);
        $html = $this->get('/customers?view=loyalty&loyalty_page=2')->assertOk()->getContent();
        $this->assertNumbers($html, 'customers-data-table--loyalty', [16]);
        $this->assertMobileNumbers($html, 'customers-mobile-cards--loyalty', [16]);
        foreach (['search' => 'directory', 'loyalty_search' => 'loyalty'] as $parameter => $view) {
            $html = $this->get('/customers?'.$parameter.'=Indexed%2001')->assertOk()->getContent();
            $this->assertNumbers($html, 'customers-data-table--'.$view, [1]);
        }
        $html = $this->get('/customers?view=occasions')->assertOk()->getContent();
        $this->assertNumbers($html, 'customers-data-table--occasions', range(1, 16));
        $this->assertMobileNumbers($html, 'customers-mobile-cards--occasions', range(1, 16));
        // The report page uses the individual-report table (ir-table, ir-index cells) since the reports redesign.
        $report = $this->xpath($this->get('/report/occasions')->assertOk()->getContent());
        $this->assertSame('Row number', $report->evaluate('string(//table[contains(@class,"ir-table")]/thead/tr/th[1]/@aria-label)'));
        $numbers = [];
        foreach ($report->query('//table[contains(@class,"ir-table")]/tbody/tr/td[contains(@class,"ir-index")]') as $cell) {
            $numbers[] = (int) trim($cell->textContent);
        }
        $this->assertSame(range(1, 16), $numbers);
    }

    public function test_both_emi_registers_number_rendered_rows_without_using_plan_ids(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $this->withoutVite()->actingAs($owner);
        $plans = collect([901, 4302])->map(function ($id) {
            $plan = new InstallmentPlan(['total_amount' => 12000, 'emi_amount' => 1000, 'total_emis' => 12, 'emis_paid' => 2, 'status' => 'active', 'next_due_date' => now()->addMonth()]);
            $plan->id = $id;
            $plan->setRelation('customer', new Customer(['first_name' => 'Indexed borrower']));
            $plan->setRelation('invoice', null);

            return $plan;
        });
        foreach (['/customers?view=emi' => 'customers.index', '/installments' => 'installments.index'] as $url => $view) {
            $data = $this->get($url)->assertOk()->original->getData();
            $paginator = new LengthAwarePaginator($plans, 31, 15, 2);
            if ($view === 'customers.index') {
                $data['installmentData']['installmentPlans'] = $paginator;
            } else {
                $data['plans'] = $paginator;
            }
            $html = TenantContext::runFor($shop->id, fn () => view($view, $data)->render());
            $this->assertNumbers($html, $view === 'customers.index' ? 'customers-data-table--emi' : 'installments-data-table', [16, 17]);
            $this->assertMobileNumbers($html, $view === 'customers.index' ? 'customers-mobile-cards--emi' : 'installments-mobile-cards', [16, 17]);
        }
    }

    public function test_customer_and_loyalty_history_continue_numbering_on_later_pages(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $customer = $this->createCustomer($shop->id);
        $this->withoutVite()->actingAs($owner);
        $txn = new LoyaltyTransaction(['type' => 'earn', 'points' => 10, 'balance_after' => 10, 'description' => 'Example', 'created_at' => now()]);
        $txn->created_at = now();
        $txn->setRelation('invoice', null);
        $html = TenantContext::runFor($shop->id, fn () => view('loyalty.history', [
            'customer' => $customer, 'transactions' => new LengthAwarePaginator([$txn], 21, 20, 2),
        ])->render());
        $this->assertNumbers($html, 'jf-table', [21]);
        foreach (['invoices' => new Invoice, 'quick-bills' => new QuickBill, 'repairs' => new Repair] as $tab => $row) {
            $row->forceFill(['id' => 777, 'created_at' => now(), 'status' => 'draft']);
            $html = TenantContext::runFor($shop->id, fn () => view('customers.history', [
                'customer' => $customer, 'tabs' => [$tab => true], 'tab' => $tab,
                'rows' => new LengthAwarePaginator([$row], 21, 20, 2),
            ])->render());
            $this->assertNumbers($html, 'jf-table', [21]);
        }
    }

    public function test_empty_tables_span_the_added_number_column_without_a_fake_row_number(): void
    {
        [$owner] = $this->createRetailerTenant();
        $this->withoutVite()->actingAs($owner);
        $html = $this->get('/customers')->assertOk()->getContent();
        foreach (['directory' => 5, 'loyalty' => 6, 'emi' => 8, 'occasions' => 6] as $suffix => $columns) {
            $xp = $this->xpath($html);
            $table = '//table[contains(@class,"customers-data-table--'.$suffix.'")]';
            $this->assertSame('Row number', $xp->evaluate('string('.$table.'/thead/tr/th[1]/@aria-label)'));
            foreach ($xp->query($table.'/tbody/tr/td[@colspan]') as $cell) {
                $this->assertSame((string) $columns, $cell->getAttribute('colspan'));
            }
            $this->assertSame(0, $xp->query($table.'/tbody/tr/td[contains(@class,"jf-table-index")]')->length);
        }
    }

    public function test_profile_history_tables_and_mobile_loyalty_cards_show_row_numbers(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $customer = $this->createCustomer($shop->id);
        $this->withoutVite()->actingAs($owner);
        TenantContext::runFor($shop->id, fn () => app(\App\Services\LoyaltyService::class)->adjustPoints($customer, 10, 'earn', 'Example history'));
        $html = TenantContext::runFor($shop->id, fn () => $this->get(route('customers.show', $customer)))->assertOk()->getContent();
        $this->assertNumbers($html, 'customers-show-retailer-table', [1]);
        $this->assertMobileNumbers($html, 'customers-show-retailer-mobile-cards', [1]);

        [$owner, $shop] = $this->createManufacturerTenant();
        $customer = $this->createCustomer($shop->id);
        $data = TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)->get(route('customers.show', $customer)))->assertOk()->original->getData();
        $txn = new \App\Models\CustomerGoldTransaction;
        $txn->forceFill(['created_at' => now(), 'type' => 'deposit', 'fine_gold' => 2]);
        $data['transactions'] = collect([$txn]);
        $html = TenantContext::runFor($shop->id, fn () => view('customers.show', $data)->render());
        $this->assertNumbers($html, 'customers-show-data-table--gold', [1]);
    }

    public function test_payment_row_numbers_match_mobile_order_without_changing_receipt_targets(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $this->withoutVite()->actingAs($owner);
        $this->get('/customers')->assertOk();
        $plan = new InstallmentPlan(['total_amount' => 12000, 'down_payment' => 2000, 'principal_amount' => 10000,
            'total_payable' => 10000, 'remaining_amount' => 8000, 'emi_amount' => 1000, 'emis_paid' => 2,
            'total_emis' => 10, 'status' => 'active', 'next_due_date' => now()->addMonth()]);
        $plan->forceFill(['id' => 777, 'shop_id' => $shop->id, 'invoice_id' => 333]);
        $plan->setRelation('customer', $this->createCustomer($shop->id));
        $invoice = new Invoice;
        $invoice->forceFill(['id' => 333, 'invoice_number' => 'INV-EXAMPLE']);
        $plan->setRelation('invoice', $invoice);
        $payments = collect([9001, 4402])->map(function ($id, $index) {
            $payment = new \App\Models\InstallmentPayment;
            $payment->forceFill(['id' => $id, 'payment_date' => today()->subDays($index), 'amount' => 1000, 'payment_method' => 'cash']);
            $payment->setRelation('paymentMethod', null);

            return $payment;
        });
        $plan->setRelation('payments', $payments);
        $html = TenantContext::runFor($shop->id, fn () => view('installments.show', [
            'plan' => $plan, 'summary' => app(\App\Services\InstallmentService::class)->summary($plan), 'paymentMethods' => collect(),
        ])->render());
        $this->assertNumbers($html, 'installments-show-table', [1, 2]);
        $this->assertMobileNumbers($html, 'installments-show-mobile-payments', [1, 2]);
        $this->assertStringContainsString(route('installments.receipt', [$plan, 9001]), $html);
        $this->assertStringContainsString(route('installments.receipt', [$plan, 4402]), $html);
    }

    private function xpath(string $html): \DOMXPath
    {
        $dom = new \DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?>'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new \DOMXPath($dom);
    }

    private function assertNumbers(string $html, string $tableClass, array $expected): void
    {
        $xp = $this->xpath($html);
        $table = '//table[contains(concat(" ",normalize-space(@class)," ")," '.$tableClass.' ")]';
        $this->assertSame('Row number', $xp->evaluate('string('.$table.'/thead/tr/th[1]/@aria-label)'));
        $cells = $xp->query($table.'/tbody/tr[not(td[@colspan])]/td[1]');
        $numbers = [];
        foreach ($cells as $cell) {
            $numbers[] = (int) trim($cell->textContent);
        }
        $this->assertSame($expected, $numbers);
    }

    private function assertMobileNumbers(string $html, string $containerClass, array $expected): void
    {
        $xp = $this->xpath($html);
        $numbers = [];
        foreach ($xp->query('//*[contains(concat(" ",normalize-space(@class)," ")," '.$containerClass.' ")]//*[contains(concat(" ",normalize-space(@class)," ")," jf-row-number ")]') as $number) {
            $numbers[] = (int) preg_replace('/\D/', '', $number->textContent);
        }
        $this->assertSame($expected, $numbers);
    }
}
