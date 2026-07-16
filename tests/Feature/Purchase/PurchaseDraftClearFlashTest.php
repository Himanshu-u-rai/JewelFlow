<?php

namespace Tests\Feature\Purchase;

use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Purchase wizard draft-clear signal (defect D7).
 *
 * The wizard keeps a localStorage draft. It must be cleared ONLY after a
 * confirmed server-side save — surfaced as the `purchase_draft_saved` session
 * flash on the store→show redirect. A validation failure must NOT set it, so an
 * in-progress draft survives to be restored.
 */
class PurchaseDraftClearFlashTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestTenant;

    private const ERP = 'https://jewelflows.com';

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        $this->withoutMiddleware([
            \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
            \Illuminate\Routing\Middleware\ThrottleRequests::class,
        ]);
    }

    public function test_successful_save_flashes_draft_saved_signal(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();

        $res = TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)
            ->post(self::ERP . '/inventory/purchases', [
                'purchase_date' => now()->toDateString(),
            ]));

        $res->assertRedirect();
        $res->assertSessionHas('purchase_draft_saved', true);
    }

    public function test_validation_failure_does_not_flash_draft_saved_signal(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();

        // Missing required purchase_date → validation failure, no redirect to show.
        $res = TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)
            ->post(self::ERP . '/inventory/purchases', []));

        $res->assertSessionHasErrors('purchase_date');
        $res->assertSessionMissing('purchase_draft_saved');
    }
}
