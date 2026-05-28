<?php

namespace Tests\Feature\Material;

use App\Http\Requests\Items\StoreItemMobileRequest;
use App\Http\Requests\Items\StoreItemWebRequest;
use App\Services\MetalRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Mobile Contract Stabilization — M4 parity.
 *
 * Feeds the SAME input through both web and mobile StoreItemRequest
 * descendants and asserts the errors are identical for the shared
 * concerns (enabled metal, purity-required-for-accounting-truth,
 * barcode-uniqueness-per-shop). Any future drift between web and
 * mobile validation for these concerns must trip this test.
 */
class SharedValidationParityTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    /**
     * Resolve a FormRequest with the given input and authenticated user,
     * then run its rules() through the validator and return the resulting
     * error keys (sorted, unique). We compare error KEYS — not messages —
     * because messages are allowed to differ in tone between platforms.
     *
     * @param  class-string  $requestClass
     * @return string[]
     */
    private function errorKeys(string $requestClass, array $input, \App\Models\User $user): array
    {
        /** @var \Illuminate\Foundation\Http\FormRequest $request */
        $request = $requestClass::create('/', 'POST', $input);
        $request->setUserResolver(fn () => $user);

        $validator = Validator::make($input, $request->rules());

        return array_values(array_unique($validator->errors()->keys()));
    }

    public function test_web_and_mobile_reject_disabled_metal_identically(): void
    {
        [$user] = $this->createRetailerTenant();
        // platinum is Tier 2 and NOT opted-in by default.

        $input = [
            'barcode' => 'PARITY-001',
            'category' => 'Ring',
            'metal_type' => 'platinum',
            'gross_weight' => 5,
            'purity' => 950,
        ];

        $webErrors = $this->errorKeys(StoreItemWebRequest::class, $input, $user);
        $mobileErrors = $this->errorKeys(StoreItemMobileRequest::class, $input, $user);

        $this->assertContains('metal_type', $webErrors);
        $this->assertContains('metal_type', $mobileErrors);
        $this->assertSame($webErrors, $mobileErrors);
    }

    public function test_web_and_mobile_require_purity_for_accounting_truth_metals(): void
    {
        [$user] = $this->createRetailerTenant();

        $input = [
            'barcode' => 'PARITY-002',
            'category' => 'Ring',
            'metal_type' => 'gold',
            'gross_weight' => 5,
            // purity intentionally omitted
        ];

        $webErrors = $this->errorKeys(StoreItemWebRequest::class, $input, $user);
        $mobileErrors = $this->errorKeys(StoreItemMobileRequest::class, $input, $user);

        $this->assertContains('purity', $webErrors);
        $this->assertContains('purity', $mobileErrors);
        $this->assertSame($webErrors, $mobileErrors);
    }

    public function test_web_and_mobile_allow_purity_omission_for_non_accounting_metal(): void
    {
        [$user, $shop] = $this->createRetailerTenant();

        // Opt the shop into platinum so the metal itself validates.
        DB::table('shop_enabled_metals')->updateOrInsert(
            ['shop_id' => $shop->id, 'metal_type' => 'platinum'],
            ['enabled' => DB::raw('TRUE'), 'updated_at' => now(), 'created_at' => now()]
        );
        MetalRegistry::clearShopCache($shop->id);

        if (! MetalRegistry::isSupported('platinum')) {
            $this->markTestSkipped('Platinum is not in the configured Tier 2 set for this environment.');
        }

        $input = [
            'barcode' => 'PARITY-003',
            'category' => 'Ring',
            'metal_type' => 'platinum',
            'gross_weight' => 5,
            // purity omitted — must be allowed because platinum is purity_spec, not accounting_truth.
        ];

        $webErrors = $this->errorKeys(StoreItemWebRequest::class, $input, $user);
        $mobileErrors = $this->errorKeys(StoreItemMobileRequest::class, $input, $user);

        $this->assertNotContains('purity', $webErrors);
        $this->assertNotContains('purity', $mobileErrors);
        $this->assertSame($webErrors, $mobileErrors);
    }

    public function test_web_and_mobile_reject_duplicate_barcode_within_shop(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $this->createItem($shop->id, null, ['barcode' => 'DUP-BC-1']);

        $input = [
            'barcode' => 'DUP-BC-1',
            'category' => 'Ring',
            'metal_type' => 'gold',
            'gross_weight' => 5,
            'purity' => 22,
        ];

        $webErrors = $this->errorKeys(StoreItemWebRequest::class, $input, $user);
        $mobileErrors = $this->errorKeys(StoreItemMobileRequest::class, $input, $user);

        $this->assertContains('barcode', $webErrors);
        $this->assertContains('barcode', $mobileErrors);
        $this->assertSame($webErrors, $mobileErrors);
    }

    public function test_web_and_mobile_accept_valid_input_identically(): void
    {
        [$user] = $this->createRetailerTenant();

        $input = [
            'barcode' => 'PARITY-OK',
            'category' => 'Ring',
            'metal_type' => 'gold',
            'gross_weight' => 5,
            'purity' => 22,
        ];

        $webErrors = $this->errorKeys(StoreItemWebRequest::class, $input, $user);
        $mobileErrors = $this->errorKeys(StoreItemMobileRequest::class, $input, $user);

        $this->assertSame([], $webErrors);
        $this->assertSame([], $mobileErrors);
    }
}
