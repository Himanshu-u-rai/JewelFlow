<?php

namespace Tests\Feature\Historical;

use App\Models\ShopPaymentMethod;
use App\Support\TenantContext;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Batch 3, Tranche 3 — the payment-row UI markup contract
 * (HISTORICAL-BATCH-3-UX-CONTRACT-V2 §7/§8). Backend persistence, tenant
 * validation and settlement math are already covered end-to-end by
 * `HistoricalManualPaymentWiringTest`; this file proves the manual-entry
 * form and its preview screen actually render the controls that post the
 * field names that request validates — using the same honest-DOM-assertion
 * pattern as `HistoricalMobileUiTest`.
 */
class HistoricalManualPaymentRowsUiTest extends TestCase
{
    use CreatesTestTenant;
    use RefreshDatabase;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    private function xpath(string $html): DOMXPath
    {
        $previous = libxml_use_internal_errors(true);
        $document = new DOMDocument;
        $loaded = $document->loadHTML($html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $this->assertTrue($loaded, 'Rendered HTML could not be parsed.');

        return new DOMXPath($document);
    }

    private function makeShopPaymentMethod(int $shopId, array $attrs = []): ShopPaymentMethod
    {
        return TenantContext::runFor($shopId, function () use ($shopId, $attrs): ShopPaymentMethod {
            $method = new ShopPaymentMethod;
            $method->forceFill(array_merge([
                'shop_id' => $shopId,
                'type' => ShopPaymentMethod::TYPE_BANK,
                'name' => 'HDFC Current',
                'is_active' => true,
                'sort_order' => 1,
            ], $attrs))->save();

            return $method;
        });
    }

    public function test_manual_entry_form_renders_a_payment_row_with_bindings_for_every_field(): void
    {
        [$owner] = $this->createRetailerTenant();

        $manual = $this->actingAs($owner)->get(route('historical.manual.create'))->assertOk();
        $html = $manual->getContent();
        $xpath = $this->xpath($html);
        $row = "//form[@data-historical-form='manual']//*[@data-historical-payment-row]";

        $this->assertGreaterThan(0, $xpath->query($row)?->length, 'No payment row rendered on the manual entry form.');

        foreach (['mode', 'amount', 'account_choice', 'account_label_snapshot', 'reference', 'payment_date', 'note'] as $field) {
            $this->assertGreaterThan(
                0,
                $xpath->query("{$row}//*[@x-model='payment.{$field}']")?->length,
                "No control bound to payment.{$field}."
            );
        }

        foreach (['mode', 'amount', 'shop_payment_method_id', 'account_label_snapshot', 'reference', 'payment_date', 'note'] as $field) {
            $this->assertStringContainsString("`payments[\${i}][{$field}]`", $html, "Missing name binding for payments[i][{$field}].");
        }

        $this->assertStringContainsString('@click="addPayment()"', $html);
        $this->assertStringContainsString('@click="removePayment(i)"', $html);
    }

    public function test_manual_entry_form_lists_only_this_shops_active_payment_methods(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        [, $otherShop] = $this->createRetailerTenant();

        $active = $this->makeShopPaymentMethod($shop->id, ['name' => 'HDFC Current']);
        $inactive = $this->makeShopPaymentMethod($shop->id, ['name' => 'Closed Account', 'is_active' => false]);
        $foreign = $this->makeShopPaymentMethod($otherShop->id, ['name' => 'Foreign Bank']);

        $manual = $this->actingAs($owner)->get(route('historical.manual.create'))->assertOk();
        $html = $manual->getContent();
        $xpath = $this->xpath($html);
        $accountSelect = "//form[@data-historical-form='manual']//*[@data-historical-payment-row]//select[@x-model='payment.account_choice']";

        $this->assertSame(1, $xpath->query("{$accountSelect}/option[@value='{$active->id}']")?->length);
        $this->assertSame(0, $xpath->query("{$accountSelect}/option[@value='{$inactive->id}']")?->length);
        $this->assertSame(0, $xpath->query("{$accountSelect}/option[@value='{$foreign->id}']")?->length);
        $this->assertStringNotContainsString('Closed Account', $html);
        $this->assertStringNotContainsString('Foreign Bank', $html);
    }

    public function test_manual_entry_form_offers_a_custom_free_text_account_fallback(): void
    {
        [$owner] = $this->createRetailerTenant();

        $manual = $this->actingAs($owner)->get(route('historical.manual.create'))->assertOk();
        $xpath = $this->xpath($manual->getContent());
        $accountSelect = "//form[@data-historical-form='manual']//*[@data-historical-payment-row]//select[@x-model='payment.account_choice']";

        $this->assertSame(1, $xpath->query("{$accountSelect}/option[@value='__custom']")?->length);
    }

    public function test_manual_entry_form_shows_a_derived_payment_status_indicator(): void
    {
        [$owner] = $this->createRetailerTenant();

        $manual = $this->actingAs($owner)->get(route('historical.manual.create'))->assertOk();
        $html = $manual->getContent();
        $xpath = $this->xpath($html);

        $node = $xpath->query("//form[@data-historical-form='manual']//*[@data-historical-payment-status]")?->item(0);
        $this->assertNotNull($node, 'No derived payment-status indicator rendered.');
        $this->assertStringContainsString('x-text="paymentStatusLabel"', $html);
    }

    public function test_preview_screen_preserves_the_same_payment_row_bindings(): void
    {
        [$owner] = $this->createRetailerTenant();

        $preview = $this->actingAs($owner)->post(route('historical.manual.preview'), [
            'document_date' => '2023-06-15',
            'source_system' => 'Manual',
            'grand_total' => 1000,
            'tax_mode' => \App\Models\Historical\HistoricalSalesDocument::TAX_MODE_UNKNOWN,
            'payments' => [['mode' => 'cash', 'amount' => 1000]],
        ])->assertOk();

        $html = $preview->getContent();
        $xpath = $this->xpath($html);
        $row = "//form[@data-historical-form='manual-preview']//*[@data-historical-payment-row]";

        $this->assertGreaterThan(0, $xpath->query($row)?->length);
        foreach (['mode', 'amount', 'account_choice', 'reference', 'payment_date', 'note'] as $field) {
            $this->assertGreaterThan(0, $xpath->query("{$row}//*[@x-model='payment.{$field}']")?->length);
        }
    }
}
