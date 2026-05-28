<?php

namespace Tests\Feature\Material;

use App\Rules\Inventory\UniqueBarcodeForShop;
use App\Rules\Material\IsEnabledMetal;
use App\Rules\Material\PurityRequiredForAccountingTruth;
use App\Services\MetalRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Mobile Contract Stabilization — M4 unit coverage for the shared
 * validation rules used by both web and mobile item-creation flows.
 */
class SharedValidationRulesTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    private function validate(string $attribute, mixed $value, $rule): array
    {
        $v = Validator::make([$attribute => $value], [$attribute => [$rule]]);

        return $v->errors()->get($attribute);
    }

    // ─── IsEnabledMetal ───────────────────────────────────────────────

    public function test_is_enabled_metal_accepts_a_shops_enabled_metal(): void
    {
        [, $shop] = $this->createRetailerTenant();
        $rule = new IsEnabledMetal($shop->id);

        $this->assertSame([], $this->validate('metal_type', 'gold', $rule));
    }

    public function test_is_enabled_metal_rejects_a_metal_not_opted_in(): void
    {
        [, $shop] = $this->createRetailerTenant();
        // platinum is Tier 2 and NOT enabled by default.
        $rule = new IsEnabledMetal($shop->id);

        $errors = $this->validate('metal_type', 'platinum', $rule);
        $this->assertNotEmpty($errors);
    }

    public function test_is_enabled_metal_rejects_unknown_strings(): void
    {
        [, $shop] = $this->createRetailerTenant();
        $rule = new IsEnabledMetal($shop->id);

        $this->assertNotEmpty($this->validate('metal_type', 'unobtanium', $rule));
        // Empty values are NOT validated by custom non-implicit rules; the
        // `required` validator covers that case in the FormRequest.
    }

    public function test_is_enabled_metal_source_does_not_hardcode_metal_names(): void
    {
        // Constitutional guard: this rule must consult MetalRegistry exclusively.
        $path = base_path('app/Rules/Material/IsEnabledMetal.php');
        $source = file_get_contents($path);

        foreach (['gold', 'silver', 'platinum', 'copper'] as $literal) {
            $this->assertStringNotContainsString(
                "'{$literal}'",
                $source,
                "IsEnabledMetal must not hardcode the metal literal '{$literal}'."
            );
            $this->assertStringNotContainsString(
                "\"{$literal}\"",
                $source,
                "IsEnabledMetal must not hardcode the metal literal \"{$literal}\"."
            );
        }
    }

    // ─── PurityRequiredForAccountingTruth ─────────────────────────────

    public function test_purity_required_for_gold(): void
    {
        $rule = new PurityRequiredForAccountingTruth('gold');

        $errors = $this->validate('purity', null, $rule);
        $this->assertNotEmpty($errors);
    }

    public function test_purity_required_for_silver(): void
    {
        $rule = new PurityRequiredForAccountingTruth('silver');

        $errors = $this->validate('purity', null, $rule);
        $this->assertNotEmpty($errors);
    }

    public function test_purity_optional_for_platinum(): void
    {
        if (! MetalRegistry::isSupported('platinum')) {
            $this->markTestSkipped('Platinum not configured as Tier 2.');
        }
        $rule = new PurityRequiredForAccountingTruth('platinum');

        // null purity for a non-accounting metal must NOT raise an error here.
        $this->assertSame([], $this->validate('purity', null, $rule));
    }

    public function test_purity_optional_for_copper(): void
    {
        if (! MetalRegistry::isSupported('copper')) {
            $this->markTestSkipped('Copper not configured as Tier 2.');
        }
        $rule = new PurityRequiredForAccountingTruth('copper');

        $this->assertSame([], $this->validate('purity', null, $rule));
    }

    public function test_purity_rule_resolves_closure_lazily(): void
    {
        $metal = 'gold';
        $rule = new PurityRequiredForAccountingTruth(fn () => $metal);

        $this->assertNotEmpty($this->validate('purity', null, $rule));

        $metal = MetalRegistry::isSupported('platinum') ? 'platinum' : 'silver';
        $rule = new PurityRequiredForAccountingTruth(fn () => $metal);
        if ($metal === 'platinum') {
            $this->assertSame([], $this->validate('purity', null, $rule));
        }
    }

    // ─── UniqueBarcodeForShop ─────────────────────────────────────────

    public function test_unique_barcode_rejects_duplicate_within_shop(): void
    {
        [, $shop] = $this->createRetailerTenant();
        $this->createItem($shop->id, null, ['barcode' => 'BC-DUP-1']);

        $rule = new UniqueBarcodeForShop($shop->id);
        $errors = $this->validate('barcode', 'BC-DUP-1', $rule);

        $this->assertNotEmpty($errors);
    }

    public function test_unique_barcode_accepts_same_barcode_across_shops(): void
    {
        [, $shopA] = $this->createRetailerTenant();
        [, $shopB] = $this->createRetailerTenant();

        $this->createItem($shopA->id, null, ['barcode' => 'BC-CROSS-1']);

        // Same barcode in shop B should be allowed.
        $rule = new UniqueBarcodeForShop($shopB->id);
        $this->assertSame([], $this->validate('barcode', 'BC-CROSS-1', $rule));
    }

    public function test_unique_barcode_allows_current_item_own_barcode_on_update(): void
    {
        [, $shop] = $this->createRetailerTenant();
        $item = $this->createItem($shop->id, null, ['barcode' => 'BC-SELF-1']);

        $rule = new UniqueBarcodeForShop($shop->id, ignoreItemId: $item->id);
        $this->assertSame([], $this->validate('barcode', 'BC-SELF-1', $rule));
    }
}
