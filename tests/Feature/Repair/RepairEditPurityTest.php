<?php

namespace Tests\Feature\Repair;

use App\Models\Repair;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * The repair edit form picks the stored purity out of the metal's presets
 * (Repair::PURITY_OPTIONS: '22', '925', ...). The column keeps two decimals, so
 * the form was handed "22.00", matched no preset, and showed a 22K repair as
 * "Custom 22.00". The app already reads the stored value as a plain number
 * (String(Number(...)) in its edit form); the web form now does the same.
 */
class RepairEditPurityTest extends TestCase
{
    use CreatesTestTenant;
    use RefreshDatabase;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        $this->withoutVite();
    }

    public function test_a_stored_preset_purity_reaches_the_form_as_that_preset(): void
    {
        $this->assertSame('22', $this->formPurity('gold', 22));
        $this->assertSame('925', $this->formPurity('silver', 925));
    }

    public function test_a_stored_custom_purity_reaches_the_form_without_trailing_zeros(): void
    {
        $this->assertSame('22.5', $this->formPurity('gold', 22.5));
        $this->assertSame('', $this->formPurity('other', null));
    }

    /** The value of the edit form's hidden purity field, which its script matches against the presets. */
    private function formPurity(string $metal, int|float|null $purity): string
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $customer = $this->createCustomer($shop->id);

        $html = TenantContext::runFor($shop->id, function () use ($owner, $shop, $customer, $metal, $purity) {
            $repair = Repair::create([
                'shop_id' => $shop->id, 'customer_id' => $customer->id, 'item_description' => 'Chain',
                'metal_type' => $metal, 'gross_weight' => 8.5, 'purity' => $purity, 'status' => 'received',
            ]);

            return $this->actingAs($owner)->get(route('repairs.edit', $repair))->assertOk()->getContent();
        });

        $this->assertSame(1, preg_match('~<input type="hidden" name="purity" id="purity" value="([^"]*)">~', $html, $m),
            'the edit form has no hidden purity field');

        return $m[1];
    }
}
