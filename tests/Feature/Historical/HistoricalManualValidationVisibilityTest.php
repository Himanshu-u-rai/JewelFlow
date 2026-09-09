<?php

namespace Tests\Feature\Historical;

use App\Models\Historical\HistoricalSalesDocument;
use App\Models\Historical\HistoricalSalesPayment;
use App\Support\TenantContext;
use Illuminate\Support\Js;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * The companion to HistoricalManualValidationRedirectTest, which asserts the
 * SESSION carries the errors. It does, and always did — but `<x-app-alerts />`
 * dropped them on render, so the operator saw a form with their data intact and
 * no reason. A session assertion cannot see that; only rendering the page can.
 *
 * These tests walk the real browser sequence end to end:
 *   POST bad → follow the redirect → READ the page → correct → POST good.
 * A 422/JSON assertion would pass against the broken component.
 */
class HistoricalManualValidationVisibilityTest extends TestCase
{
    use CreatesTestTenant;
    use RefreshDatabase;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    /**
     * A complete, valid bill. Individual tests break one field at a time so the
     * failure under test is unambiguous.
     */
    private function validBill(): array
    {
        return [
            'intent' => 'draft',
            'original_document_number' => 'VIS-1001',
            'document_date' => now()->subYear()->toDateString(),
            'source_system' => 'Manual QA',
            'tax_mode' => HistoricalSalesDocument::TAX_MODE_NOT_APPLICABLE,
            'zero_tax_confirmed' => '1',
            'customer_name' => 'Visibility Regression Customer',
            'customer_mobile' => '9800000002',
            'taxable_amount' => 99200,
            'tax_total' => 0,
            'grand_total' => 99200,
            'paid_amount' => 99200,
            'outstanding_amount' => 0,
            'lines' => [[
                'line_item_name' => 'Gold Earrings',
                'line_quantity' => 2,
                'line_metal_type' => 'gold',
                'line_purity_value' => 22,
                'line_gross_weight' => 16,
                'line_stone_weight' => 1,
                'line_net_weight' => 15,
                'line_rate' => 6200,
                'line_rate_basis' => 'as_printed',
                'line_billable_weight_basis' => 'net',
                'line_stone_value' => 6200,
                'line_stone_value_mode' => 'manual',
                'line_total' => 99200,
            ]],
            'payments' => [[
                'mode' => HistoricalSalesPayment::MODE_CASH,
                'amount' => 99200,
            ]],
        ];
    }

    /**
     * The line grid is Alpine-seeded through `@js(old('lines'))`, and
     * `Js::from()` escapes quotes as `\u0022` — so a plain `"key":"value"`
     * needle never matches. Build the needle with the same encoder rather than
     * hard-coding the escaping scheme, which would silently rot if Laravel
     * changed it.
     */
    private function seededPair(string $key, string $value): string
    {
        return trim(
            str_replace(['JSON.parse(\'{', '}\')'], '', (string) Js::from([$key => $value]))
        );
    }

    /** GET the entry form the way the browser does after the redirect. */
    private function followToForm(): string
    {
        return $this->get(route('historical.manual.create'))
            ->assertOk()
            ->getContent();
    }

    /**
     * The headline regression: a missing required field must be READABLE on the
     * form the operator is returned to.
     */
    public function test_a_missing_document_date_is_visible_on_the_form_after_the_redirect(): void
    {
        [$owner] = $this->createRetailerTenant();

        $bill = $this->validBill();
        $bill['document_date'] = null;

        $this->actingAs($owner)
            ->from(route('historical.manual.preview'))
            ->post(route('historical.manual.store'), $bill)
            ->assertRedirect(route('historical.manual.create'));

        $html = $this->actingAs($owner)->followToForm();

        $this->assertStringContainsString('Please fix the following:', $html,
            'The alert block did not render — errors are invisible to the operator.');
        $this->assertStringContainsString('The document date field is required.', $html);
    }

    /**
     * A row-level failure must say WHICH row. "lines.0.line gross weight" names
     * the field but not the item; attributes() turns it into "item 1".
     */
    public function test_a_row_level_failure_names_the_item_and_the_field(): void
    {
        [$owner] = $this->createRetailerTenant();

        $bill = $this->validBill();
        $bill['lines'][] = $bill['lines'][0];
        $bill['lines'][1]['line_gross_weight'] = 'not a number';

        $this->actingAs($owner)
            ->from(route('historical.manual.preview'))
            ->post(route('historical.manual.store'), $bill)
            ->assertRedirect(route('historical.manual.create'));

        $html = $this->actingAs($owner)->followToForm();

        $this->assertStringContainsString('item 2 gross weight', $html,
            'The message must identify the offending item, not just the field path.');
        $this->assertStringNotContainsString('lines.1.line_gross_weight', $html,
            'The raw field path leaked into the operator-facing message.');
    }

    /** Payment rows get the same treatment as line rows. */
    public function test_a_payment_row_failure_names_the_payment_and_the_field(): void
    {
        [$owner] = $this->createRetailerTenant();

        $bill = $this->validBill();
        $bill['payments'][0]['amount'] = -5;

        $this->actingAs($owner)
            ->from(route('historical.manual.preview'))
            ->post(route('historical.manual.store'), $bill)
            ->assertRedirect(route('historical.manual.create'));

        $this->assertStringContainsString('payment 1 amount', $this->actingAs($owner)->followToForm());
    }

    /**
     * Visible errors are worthless if the retype cost is the whole bill. The
     * returned form must still hold the typed values, the calculation modes and
     * the rate basis — the three things the operator cannot cheaply redo.
     */
    public function test_the_returned_form_still_holds_values_modes_and_rate_basis(): void
    {
        [$owner] = $this->createRetailerTenant();

        $bill = $this->validBill();
        $bill['document_date'] = null;

        $this->actingAs($owner)
            ->from(route('historical.manual.preview'))
            ->post(route('historical.manual.store'), $bill)
            ->assertRedirect(route('historical.manual.create'));

        $html = $this->actingAs($owner)->followToForm();

        // Document level, rendered straight into value="".
        $this->assertStringContainsString('VIS-1001', $html);
        $this->assertStringContainsString('Visibility Regression Customer', $html);

        // Row level: the grid is Alpine-seeded from old('lines'), so these
        // appear inside the @js() payload rather than as value="" attributes.
        $this->assertStringContainsString($this->seededPair('line_rate_basis', 'as_printed'), $html,
            'The rate basis was lost on the bounce.');
        $this->assertStringContainsString($this->seededPair('line_stone_value_mode', 'manual'), $html,
            'A manual calculation mode silently reverted to auto on the bounce.');
        $this->assertStringContainsString($this->seededPair('line_item_name', 'Gold Earrings'), $html);
    }

    /**
     * The whole point: the operator can actually recover. Fail, read the reason,
     * fix that one field, resubmit, and the bill saves.
     */
    public function test_the_operator_can_correct_the_error_and_save_successfully(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();

        $bill = $this->validBill();
        $bill['document_date'] = null;

        $this->actingAs($owner)
            ->from(route('historical.manual.preview'))
            ->post(route('historical.manual.store'), $bill)
            ->assertRedirect(route('historical.manual.create'));

        $this->assertStringContainsString(
            'The document date field is required.',
            $this->actingAs($owner)->followToForm()
        );

        TenantContext::runFor($shop->id, function (): void {
            $this->assertSame(0, HistoricalSalesDocument::query()->count(), 'The rejected bill was written anyway.');
        });

        // The correction: one field, everything else as before.
        $corrected = $this->validBill();

        $this->actingAs($owner)
            ->from(route('historical.manual.preview'))
            ->post(route('historical.manual.store'), $corrected)
            ->assertRedirect();

        TenantContext::runFor($shop->id, function (): void {
            $document = HistoricalSalesDocument::query()->sole();

            $this->assertSame('VIS-1001', $document->original_document_number);
            $this->assertSame(HistoricalSalesDocument::STATUS_DRAFT, $document->status);
        });

        // And the form is clean again — no stale alert from the failed attempt.
        $this->assertStringNotContainsString(
            'The document date field is required.',
            $this->actingAs($owner)->followToForm()
        );
    }

    /**
     * The preview screen renders the same shared component, and it also carries
     * its own `$blockingFindings` list. Both must be able to coexist — the
     * rename exists so the framework bag is never shadowed by the findings.
     */
    public function test_the_preview_screen_renders_the_shared_alert_component(): void
    {
        [$owner] = $this->createRetailerTenant();

        $html = $this->actingAs($owner)
            ->post(route('historical.manual.preview'), $this->validBill())
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-historical-preview-review', $html);
        // The findings variable was renamed off `$errors`; if it were still
        // shadowing, this page would have thrown on the component's type check.
        $this->assertStringNotContainsString('Please fix the following:', $html);
    }
}
