<?php

namespace Tests\Feature\Historical;

use App\Http\Requests\Historical\StoreManualHistoricalRequest;
use App\Models\Historical\HistoricalSalesDocument;
use App\Models\Historical\HistoricalSalesPayment;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Batch 3 correction — the real browser flow for manual entry is
 * GET /manual → POST /manual/preview → POST /manual, so the referer on the
 * store() request is always the preview URL, never the create form. A
 * FormRequest validation failure with no explicit redirect target falls back
 * to UrlGenerator::previous(), which reads that referer and lands on
 * GET /manual/preview — a route that only exists as previewExpired(), and
 * that redirect carries no withInput()/withErrors(), silently discarding the
 * operator's typed bill and the real validation reason.
 *
 * HistoricalManualFastEntryWiringTest's validation-failure tests POST
 * directly with no referer set, so previous() falls back to '/' and those
 * tests never exercise this hop. These tests set the referer explicitly with
 * ->from() to prove the real flow.
 */
class HistoricalManualValidationRedirectTest extends TestCase
{
    use CreatesTestTenant;
    use RefreshDatabase;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    private function invalidPayload(string $intent): array
    {
        return [
            'intent' => $intent,
            'original_document_number' => 'VR-9001',
            'document_series' => 'A',
            // Missing on purpose — 'required' rule on document_date is the
            // trigger for this test's validation failure.
            'document_date' => null,
            'source_system' => 'Manual QA',
            'tax_mode' => HistoricalSalesDocument::TAX_MODE_NOT_APPLICABLE,
            'zero_tax_confirmed' => '1',
            'customer_name' => 'Redirect Regression Customer',
            'customer_mobile' => '9800000001',
            'taxable_amount' => 1000,
            'tax_total' => 0,
            'grand_total' => 1000,
            'paid_amount' => 1000,
            'outstanding_amount' => 0,
            'lines' => [[
                'line_item_name' => 'QA Gold Item',
                'line_quantity' => 1,
                'line_total' => 1000,
                'line_stone_value_mode' => 'manual',
            ]],
            'payments' => [[
                'mode' => HistoricalSalesPayment::MODE_CASH,
                'amount' => 1000,
                'reference' => 'VR-PAY-0001',
            ]],
        ];
    }

    private function assertRealFlowValidationFailurePreservesTheBill(string $intent): void
    {
        [$owner, $shop] = $this->createRetailerTenant();

        $notificationsBefore = (int) DB::table('shop_notifications')->count();
        $auditBefore = (int) DB::table('audit_logs')->count();

        $response = $this->actingAs($owner)
            ->from(route('historical.manual.preview'))
            ->post(route('historical.manual.store'), $this->invalidPayload($intent));

        $response->assertRedirect(route('historical.manual.create'));
        $response->assertSessionHasErrors('document_date');
        $response->assertSessionHasInput('original_document_number', 'VR-9001');
        $response->assertSessionHasInput('customer_name', 'Redirect Regression Customer');
        $response->assertSessionHasInput('customer_mobile', '9800000001');
        $response->assertSessionHasInput('intent', $intent);
        // prepareForValidation() title-cases the item name before validation —
        // pre-existing normalization, unrelated to this fix.
        $response->assertSessionHasInput('lines.0.line_item_name', 'Qa Gold Item');
        $response->assertSessionHasInput('lines.0.line_stone_value_mode', 'manual');
        $response->assertSessionHasInput('payments.0.reference', 'VR-PAY-0001');
        $response->assertSessionMissing('historical_carry_forward');

        TenantContext::runFor($shop->id, function (): void {
            $this->assertSame(0, HistoricalSalesDocument::query()->count());
            $this->assertSame(0, HistoricalSalesPayment::query()->count());
        });

        $this->assertSame($notificationsBefore, (int) DB::table('shop_notifications')->count(),
            'A rejected manual bill produced a notification.');
        $this->assertSame($auditBefore, (int) DB::table('audit_logs')->count(),
            'A rejected manual bill wrote an audit entry.');
    }

    public function test_save_draft_validation_failure_via_real_preview_referer_redirects_to_create(): void
    {
        $this->assertRealFlowValidationFailurePreservesTheBill(StoreManualHistoricalRequest::INTENT_DRAFT);
    }

    public function test_publish_validation_failure_via_real_preview_referer_redirects_to_create(): void
    {
        $this->assertRealFlowValidationFailurePreservesTheBill(StoreManualHistoricalRequest::INTENT_PUBLISH);
    }

    public function test_save_draft_and_new_validation_failure_via_real_preview_referer_redirects_to_create(): void
    {
        $this->assertRealFlowValidationFailurePreservesTheBill(StoreManualHistoricalRequest::INTENT_DRAFT_AND_NEW);
    }

    public function test_publish_and_new_validation_failure_via_real_preview_referer_redirects_to_create(): void
    {
        $this->assertRealFlowValidationFailurePreservesTheBill(StoreManualHistoricalRequest::INTENT_PUBLISH_AND_NEW);
    }

    /**
     * The controller's `$this->authorize('historical.publish')` in store()
     * only runs once the FormRequest's own validation has already passed —
     * it is the sole gate for a direct publish (StoreManualHistoricalRequest
     * ::authorize() always returns true). Publish & New must be refused by
     * that same gate, even with a fully valid payload and even though the
     * user can reach the route, and must leave no write and no fast-entry
     * carry-forward state behind for a publish that never happened.
     */
    public function test_publish_and_new_is_forbidden_without_the_publish_permission_and_writes_nothing(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $this->grantOnlyPermissions($owner, ['historical.view', 'historical.import']);

        $payload = $this->invalidPayload(StoreManualHistoricalRequest::INTENT_PUBLISH_AND_NEW);
        $payload['document_date'] = now()->toDateString();

        $response = $this->actingAs($owner)
            ->from(route('historical.manual.preview'))
            ->post(route('historical.manual.store'), $payload);

        $response->assertForbidden();
        $response->assertSessionMissing('historical_carry_forward');

        TenantContext::runFor($shop->id, function (): void {
            $this->assertSame(0, HistoricalSalesDocument::query()->count());
            $this->assertSame(0, HistoricalSalesPayment::query()->count());
        });
    }
}
