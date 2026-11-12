<?php

namespace Tests\Feature\Schemes;

use App\Models\Scheme;
use App\Models\SchemeEnrollment;
use App\Models\SchemeLedgerEntry;
use App\Models\SchemePayment;
use App\Support\TenantContext;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * UI audit F08: the enrollment page used a stretched details card, a teal
 * payment button, placeholder-only fields and an unindexed ledger. This pins
 * the refreshed layout; it changes no payment/refund/cancel behaviour.
 */
class EnrollmentShowLayoutTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestTenant;

    private const ERP = 'https://jewelflows.com';

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    private function page(): string
    {
        [$owner, $shop] = $this->createRetailerTenant();

        $enrollment = TenantContext::runFor($shop->id, function () use ($shop) {
            $customer = $this->createCustomer($shop->id);

            $scheme = new Scheme();
            $scheme->forceFill([
                'shop_id' => $shop->id, 'name' => 'Layout Gold Savings', 'type' => 'gold_savings',
                'start_date' => now()->subMonths(2)->toDateString(), 'total_installments' => 11, 'is_active' => true,
            ])->save();

            $e = new SchemeEnrollment();
            $e->forceFill([
                'shop_id' => $shop->id, 'scheme_id' => $scheme->id, 'customer_id' => $customer->id,
                'start_date' => now()->subMonths(2)->toDateString(),
                'maturity_date' => now()->addMonths(9)->toDateString(),
                'monthly_amount' => 1000, 'total_paid' => 2000, 'installments_paid' => 2,
                'total_installments' => 11, 'bonus_amount' => 1000, 'status' => 'active',
            ])->save();

            SchemePayment::record([
                'shop_id' => $shop->id, 'enrollment_id' => $e->id, 'amount' => 2000,
                'payment_date' => now()->toDateString(), 'payment_method' => 'cash', 'receipt_number' => 'R-1',
            ]);
            SchemeLedgerEntry::record([
                'shop_id' => $shop->id, 'scheme_enrollment_id' => $e->id, 'entry_type' => 'contribution',
                'direction' => 'credit', 'amount' => 2000, 'balance_after' => 2000,
            ]);

            return $e;
        });

        $response = TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)
            ->get(self::ERP . '/scheme-enrollments/' . $enrollment->id));
        $response->assertOk();

        return $response->getContent();
    }

    private function xpath(string $html): DOMXPath
    {
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        libxml_clear_errors();

        return new DOMXPath($dom);
    }

    public function test_page_has_no_teal_and_payment_uses_standard_primary_button(): void
    {
        $xp = $this->xpath($this->page());
        $main = $xp->query('//*[contains(@class,"enrollment-show-page")]')->item(0);
        $this->assertNotNull($main, 'page wrapper .enrollment-show-page missing');

        $content = $main->ownerDocument->saveHTML($main);
        $this->assertStringNotContainsString('teal-', $content);
        $this->assertStringNotContainsString('#0d9488', strtolower($content));

        $buttons = $xp->query('//*[contains(@class,"enrollment-show-page")]//form[contains(@action,"/pay")]//button[@type="submit"]');
        $this->assertSame(1, $buttons->length);
        $this->assertStringContainsString('btn-primary', $buttons->item(0)->getAttribute('class'));
    }

    public function test_history_tables_have_a_row_number_column(): void
    {
        $xp = $this->xpath($this->page());

        $tables = $xp->query('//*[contains(@class,"enrollment-show-page")]//table');
        $this->assertGreaterThanOrEqual(2, $tables->length, 'payment history and ledger tables expected');

        foreach ($tables as $table) {
            $first = $xp->query('.//thead//th[1]', $table)->item(0);
            $this->assertNotNull($first);
            $this->assertSame('Row number', $first->getAttribute('aria-label'));
            $this->assertStringContainsString('jf-table-index', $first->getAttribute('class'));
        }
    }

    public function test_details_and_payment_grid_does_not_stretch(): void
    {
        $xp = $this->xpath($this->page());

        $this->assertSame(1, $xp->query('//*[contains(@class,"enrollment-show-grid")]')->length);
        $this->assertGreaterThanOrEqual(1, $xp->query('//style[contains(.,"align-items: start")]')->length,
            'page-scoped style must set align-items: start on the grid');
    }

    public function test_every_field_has_a_label_or_aria_label(): void
    {
        $xp = $this->xpath($this->page());

        $fields = $xp->query('//*[contains(@class,"enrollment-show-page")]//*[self::input or self::select or self::textarea][not(@type="hidden")]');
        $this->assertGreaterThan(0, $fields->length);

        foreach ($fields as $field) {
            $name = $field->getAttribute('name');
            $id = $field->getAttribute('id');
            $labelled = $field->getAttribute('aria-label') !== ''
                || ($id !== '' && $xp->query('//label[@for="' . $id . '"]')->length > 0);
            $this->assertTrue($labelled, "field [{$name}] has no label or aria-label");
        }
    }

    public function test_payment_fields_have_visible_captions(): void
    {
        $xp = $this->xpath($this->page());

        foreach (['enrollment-pay-amount', 'enrollment-pay-method', 'enrollment-pay-receipt'] as $id) {
            $this->assertGreaterThan(0, $xp->query('//label[@for="' . $id . '"]')->length, "visible label for #{$id} missing");
        }
    }
}
