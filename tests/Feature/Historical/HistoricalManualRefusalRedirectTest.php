<?php

namespace Tests\Feature\Historical;

use App\Http\Requests\Historical\StoreManualHistoricalRequest;
use App\Models\Historical\HistoricalSalesDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * A refused save must return the operator to the entry form holding their typed
 * bill and the real reason.
 *
 * The refusal paths used to answer with back(), whose target is the referer —
 * and the referer is the POST-only preview URL. A redirect is a GET, so it
 * landed on previewExpired(), which redirected a second time. That second hop
 * consumed the flashed input and replaced the reason with "preview has
 * expired", so a correct refusal destroyed several minutes of typing and told
 * the operator nothing actionable.
 */
class HistoricalManualRefusalRedirectTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    /** @return array<string, mixed> a bill that is valid apart from the refusal under test. */
    private function manualPayload(array $override = []): array
    {
        return array_merge([
            'original_document_number' => 'REFUSED-0001',
            'document_date'            => '2023-06-15',
            'source_system'            => 'Manual',
            'customer_name'            => 'Asha Traders',
            'grand_total'              => 18000,
            'tax_mode'                 => HistoricalSalesDocument::TAX_MODE_UNKNOWN,
        ], $override);
    }

    /**
     * Publishing an unacknowledged warning is a business refusal, so it is the
     * cheapest way to reach a refusal path from the preview screen.
     */
    public function test_a_refused_publish_returns_to_the_form_and_keeps_the_typed_bill(): void
    {
        [$owner] = $this->createRetailerTenant();

        $response = $this->actingAs($owner)
            // The operator is on the preview screen — this is what made back() wrong.
            ->from(route('historical.manual.preview'))
            ->post(route('historical.manual.store'), $this->manualPayload([
                'intent' => StoreManualHistoricalRequest::INTENT_PUBLISH,
                // acknowledge_warnings deliberately absent.
            ]));

        $response->assertRedirect(route('historical.manual.create'));

        // The typed bill survives, so the operator does not retype it.
        $response->assertSessionHasInput('original_document_number', 'REFUSED-0001');
        $response->assertSessionHasInput('customer_name', 'Asha Traders');

        // And they are told what actually happened.
        $this->assertNotSame(
            'That preview has expired. Enter the bill again and recalculate the preview.',
            session('error'),
            'The refusal reason was replaced by the stale-preview message.'
        );
        $this->assertNotEmpty(session('error'));
    }

    /**
     * previewExpired() still has to exist: a genuine Back/reload/bookmark GET on
     * the POST-only preview URL carries no bill and must not 405 in the operator's
     * face. Fixing the refusal redirect must not remove that.
     */
    public function test_a_bare_get_on_the_preview_url_still_bounces_to_the_form(): void
    {
        [$owner] = $this->createRetailerTenant();

        $this->actingAs($owner)
            ->get(route('historical.manual.preview.expired'))
            ->assertRedirect(route('historical.manual.create'))
            ->assertSessionHas('error', 'That preview has expired. Enter the bill again and recalculate the preview.');
    }
}
