<?php

namespace Tests\Feature\Tables;

use App\Models\MetalLot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * A refused Issue Bullion submission (validation, or the vault refusing more gold than a lot holds) used to
 * come back as an empty form with no message: the page never printed $errors and the Alpine form did not
 * read old input. The reason is now shown and what was entered is restored.
 */
class JobOrderRefusedIssueTest extends TestCase
{
    use CreatesTestTenant, RefreshDatabase;

    private $user;
    private int $shopId;
    private int $karigarId;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        // as in JobOrderControllerSourceTest: the harness owner role has no synced permissions
        $this->withoutMiddleware([
            \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
            \Illuminate\Auth\Middleware\Authorize::class,
        ]);
        [$user, $shop] = $this->createRetailerTenant();
        $this->user = $user;
        $this->shopId = $shop->id;
        $this->karigarId = (int) DB::table('karigars')->insertGetId(['shop_id' => $this->shopId, 'name' => 'Refused Test Karigar', 'default_wastage_percent' => 2, 'is_active' => DB::raw('true'), 'created_at' => now(), 'updated_at' => now()]);
    }

    private function vaultLot(float $fine): MetalLot
    {
        $lot = new MetalLot();
        $lot->forceFill(['shop_id' => $this->shopId, 'source' => 'purchase', 'metal_type' => 'gold', 'purity' => 22.00, 'fine_weight_total' => $fine, 'fine_weight_remaining' => $fine, 'cost_per_fine_gram' => 5000]);
        $lot->save();

        return $lot;
    }

    private function restoredState(string $html): ?array
    {
        if (! preg_match('~<script type="application/json" id="job-order-restore">(.*?)</script>~s', $html, $m)) {
            return null;
        }

        return json_decode(html_entity_decode($m[1]), true);
    }

    public function test_a_refused_vault_issue_shows_the_reason_and_keeps_what_was_entered(): void
    {
        $lot = $this->vaultLot(5);

        // exactly what the form posts for one vault line
        $this->actingAs($this->user)->from(route('job-orders.create'))->post(route('job-orders.store'), [
            'karigar_id' => $this->karigarId, 'allowed_wastage_percent' => '3.5', 'issue_date' => '2026-11-05', 'expected_return_date' => '2026-11-20',
            'metal_type' => 'gold', 'purity' => '22', 'notes' => 'Refused job notes', 'metal_source' => 'vault',
            'issuances' => [['metal_lot_id' => $lot->id, 'gross_weight' => '654.545', 'fine_weight' => '600', 'purity' => '22']],
            'advance_amount' => '150', 'advance_mode' => 'upi', 'advance_payment_method_id' => '',
        ])->assertRedirect(route('job-orders.create'))->assertSessionHasErrors('metal_source');

        $reason = session('errors')->first('metal_source');
        $html = $this->get(route('job-orders.create'))->assertOk()->assertSee($reason)->getContent();

        $this->assertStringContainsString('Refused job notes</textarea>', $html);
        $this->assertStringContainsString('value="2026-11-05"', $html);
        $this->assertStringContainsString('value="2026-11-20"', $html);
        $this->assertStringContainsString('value="150"', $html);

        $state = $this->restoredState($html);
        $this->assertNotNull($state, 'the entered values are handed to the form');
        $this->assertSame((string) $this->karigarId, $state['karigarId']);
        $this->assertSame('Refused Test Karigar', $state['karigarName']);
        $this->assertSame('3.5', $state['allowedWastage']);
        $this->assertSame('vault', $state['metalSource']);
        $this->assertSame('upi', $state['advanceMode']);
        $this->assertSame((string) $lot->id, $state['lines'][0]['metal_lot_id']);
        $this->assertSame('600', $state['lines'][0]['fine_weight']);
        $this->assertStringStartsWith('Lot #', $state['lines'][0]['lotName']);
        $this->assertEquals(5, $state['lines'][0]['lotAvailable']);
    }

    public function test_a_fresh_form_has_no_restored_state(): void
    {
        $html = $this->actingAs($this->user)->get(route('job-orders.create'))->assertOk()->getContent();

        $this->assertNull($this->restoredState($html));
    }
}
