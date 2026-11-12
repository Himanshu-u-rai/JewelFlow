<?php

namespace Tests\Feature\Tables;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Karigar;
use App\Models\KarigarInvoice;
use App\Models\KarigarInvoiceLine;
use App\Models\KarigarPayment;
use App\Models\MetalMovement;
use App\Models\ReturnLineItem;
use App\Models\Scheme;
use App\Models\SchemeEnrollment;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Audit F14: screen record tables carry the agreed "#" row-number first column.
 */
class RecordTableRowNumbersTest extends TestCase
{
    use CreatesTestTenant, RefreshDatabase;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    public function test_scheme_detail_enrollments_are_numbered_in_table_and_mobile_cards(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $scheme = new Scheme;
        $scheme->forceFill(['shop_id' => $shop->id, 'name' => 'Numbered GHS', 'type' => 'gold_savings',
            'start_date' => now()->subYear()->toDateString(), 'total_installments' => 11])->save();
        foreach ([1, 2] as $i) {
            $customer = $this->createCustomer($shop->id);
            $e = new SchemeEnrollment;
            $e->forceFill(['shop_id' => $shop->id, 'scheme_id' => $scheme->id, 'customer_id' => $customer->id,
                'start_date' => now()->toDateString(), 'monthly_amount' => 1000, 'total_paid' => 0,
                'installments_paid' => 0, 'total_installments' => 11, 'status' => 'active'])->save();
        }
        $this->withoutVite();
        $html = TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)->get(route('schemes.show', $scheme)))
            ->assertOk()->getContent();

        $this->assertNumbers($html, 'schemes-enrollments-table', [1, 2]);
        $this->assertSame(2, $this->xpath($html)->query('//*[contains(@class,"schemes-enrollments-mobile")]//*[contains(@class,"jf-row-number")]')->length);
    }

    public function test_karigar_invoice_line_and_payment_tables_are_numbered(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $karigar = new Karigar;
        $karigar->forceFill(['shop_id' => $shop->id, 'name' => 'Ramesh'])->save();
        $invoice = new KarigarInvoice;
        $invoice->forceFill([
            'shop_id' => $shop->id, 'karigar_id' => $karigar->id, 'mode' => 'job_work',
            'karigar_invoice_number' => 'KI-'.uniqid(), 'karigar_invoice_date' => now()->toDateString(),
            'total_after_tax' => 3000, 'amount_paid' => 3000, 'payment_status' => KarigarInvoice::PAYMENT_PAID,
        ])->save();
        TenantContext::runFor($shop->id, function () use ($shop, $invoice) {
            foreach (['Ring', 'Chain'] as $description) {
                $line = new KarigarInvoiceLine;
                $line->forceFill(['shop_id' => $shop->id, 'karigar_invoice_id' => $invoice->id,
                    'description' => $description, 'pieces' => 1, 'net_weight' => 1, 'rate_per_gram' => 100,
                    'metal_amount' => 100, 'making_charge' => 0, 'wastage_charge' => 0, 'extra_amount' => 0,
                    'line_total' => 100])->save();
            }
            foreach ([1500, 1500] as $amount) {
                KarigarPayment::record(['shop_id' => $shop->id, 'karigar_id' => $invoice->karigar_id,
                    'karigar_invoice_id' => $invoice->id, 'amount' => $amount, 'mode' => 'cash',
                    'paid_on' => now()->toDateString()]);
            }
        });
        $this->withoutVite();
        $html = TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)->get(route('karigar-invoices.show', $invoice)))
            ->assertOk()->getContent();

        $this->assertNumbers($html, 'ki-show-lines-table', [1, 2]);
        $this->assertNumbers($html, 'ki-show-payments-table', [1, 2]);
        $xp = $this->xpath($html);
        $this->assertSame(2, $xp->query('//*[contains(@class,"ki-show-lines-mobile")]//*[contains(@class,"jf-row-number")]')->length);
        $this->assertSame(2, $xp->query('//*[contains(@class,"ki-show-payments-mobile")]//*[contains(@class,"jf-row-number")]')->length);
    }

    public function test_vault_ledger_continues_numbers_across_pages_and_empty_state_spans_all_columns(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $lot = $this->createMetalLot($shop->id, 100);
        for ($i = 1; $i <= 51; $i++) {
            MetalMovement::record(['shop_id' => $shop->id, 'to_lot_id' => $lot->id, 'fine_weight' => 1,
                'type' => 'purchase', 'reference_type' => 'metal_lot', 'reference_id' => $lot->id, 'user_id' => $owner->id]);
        }
        $this->withoutVite();
        $html = TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)->get('/vault/ledger?page=2'))->assertOk()->getContent();
        $this->assertNumbers($html, 'vault-ledger-table', [51]);

        $html = $this->get('/vault/ledger?type=unmatched')->assertOk()->getContent();
        $this->assertEmptyColspanMatchesHeader($html, 'vault-ledger-table');
    }

    public function test_returns_register_is_numbered_in_table_and_mobile_cards(): void
    {
        [$user, $shop] = $this->createManufacturerTenant();
        $this->withoutMiddleware([
            \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
            \Illuminate\Routing\Middleware\ThrottleRequests::class,
        ]);
        $lot = $this->createMetalLot($shop->id);
        $customer = $this->createCustomer($shop->id);
        $item = $this->createItem($shop->id, $lot->id);
        $total = (float) $this->actingAs($user)->postJson('/api/price-preview', [
            'item_id' => $item->id, 'customer_id' => $customer->id,
            'gold_rate' => 6000, 'making' => 500, 'stone' => 200, 'discount' => 0, 'round_off' => 0,
        ])->json('total');
        $sell = $this->actingAs($user)->postJson('/pos/sell', [
            'customer_id' => $customer->id, 'item_id' => $item->id,
            'gold_rate' => 6000, 'making' => 500, 'stone' => 200, 'discount' => 0, 'round_off' => 0,
            'payments' => [['mode' => 'cash', 'amount' => $total]],
        ])->assertOk();
        $invoice = TenantContext::runFor($shop->id, fn () => Invoice::findOrFail($sell->json('invoice_id')));
        $line = TenantContext::runFor($shop->id, fn () => InvoiceItem::where('invoice_id', $invoice->id)->firstOrFail());
        // Returns are refused until the shop's return policy is configured (as ReturnsAccessAndLifecycleTest does).
        TenantContext::runFor($shop->id, fn () => \App\Models\ShopPreferences::firstOrNew(['shop_id' => $shop->id])->forceFill([
            'shop_id' => $shop->id, 'refund_making_charges' => true, 'refund_stone_charges' => true, 'refund_gst' => true,
            'wear_loss_pct' => 0, 'restocking_fee_pct' => 0, 'return_settlement_mode' => 'cash_or_credit',
            'return_policy_configured_at' => now(),
        ])->save());
        TenantContext::runFor($shop->id, fn () => $this->actingAs($user)
            ->post('https://jewelflows.com/invoices/'.$invoice->id.'/returns', [
                'reason' => 'Customer changed mind',
                'lines' => [[
                    'invoice_item_id' => $line->id,
                    'condition' => ReturnLineItem::CONDITION_GOOD,
                    'disposition' => \App\Models\ReturnedItemDisposition::DISPOSITION_RESTOCKED,
                ]],
                'refund_settlement' => 'cash',
            ]))->assertRedirect();

        $this->withoutVite();
        $html = TenantContext::runFor($shop->id, fn () => $this->actingAs($user)->get('https://jewelflows.com/returns'))
            ->assertOk()->getContent();

        $this->assertNumbers($html, 'returns-data-table', [1]);
        $this->assertSame(1, $this->xpath($html)->query('//*[contains(@class,"returns-mobile-cards")]//*[contains(@class,"jf-row-number")]')->length);
    }

    /** Settings billing/pricing views are only reachable through heavy subscription data, so assert their markup. */
    public function test_settings_billing_and_pricing_tables_start_with_the_row_number_column(): void
    {
        $expected = [
            'settings.blade.php' => 3,
            'partials/settings/pricing-tab.blade.php' => 3,
        ];
        foreach ($expected as $file => $count) {
            $source = file_get_contents(resource_path('views/'.$file));
            preg_match_all('/<table\b[^>]*jf-table[^>]*>\s*<thead[^>]*>\s*<tr[^>]*>\s*<th\b([^>]*)>/s', $source, $m);
            $numbered = array_filter($m[1], fn ($attrs) => str_contains($attrs, 'jf-table-index') && str_contains($attrs, 'aria-label="Row number"'));
            $this->assertCount($count, $numbered, $file.' record tables lacking the row-number header');
        }
        $this->assertStringContainsString('colspan="9" class="sub-billing-empty"', file_get_contents(resource_path('views/settings.blade.php')));
    }

    private function assertNumbers(string $html, string $tableClass, array $expected): void
    {
        $xp = $this->xpath($html);
        $table = '//table[contains(concat(" ",normalize-space(@class)," ")," '.$tableClass.' ") or @id="'.$tableClass.'"]';
        $this->assertSame('Row number', $xp->evaluate('string('.$table.'/thead/tr/th[1]/@aria-label)'));
        $numbers = [];
        foreach ($xp->query($table.'/tbody/tr[not(td[@colspan])]/td[1]') as $cell) {
            $numbers[] = (int) trim($cell->textContent);
        }
        $this->assertSame($expected, $numbers);
    }

    private function assertEmptyColspanMatchesHeader(string $html, string $tableId): void
    {
        $xp = $this->xpath($html);
        $headers = $xp->query('//table[@id="'.$tableId.'"]/thead/tr/th')->length;
        $this->assertSame('Row number', $xp->evaluate('string(//table[@id="'.$tableId.'"]/thead/tr/th[1]/@aria-label)'));
        $this->assertSame((string) $headers, $xp->evaluate('string(//table[@id="'.$tableId.'"]/tbody/tr/td[@colspan]/@colspan)'));
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
}
