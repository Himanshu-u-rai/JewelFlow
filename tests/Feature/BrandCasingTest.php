<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Regression for the guest-surface brand-casing defect: auth/onboarding pages
 * rendered the mis-cased "Jewelflows" instead of the canonical "JewelFlows".
 *
 * The guest layout now derives its brand from config('app.name'), so these
 * tests inject the QA/production app name explicitly (the test env sets a
 * different APP_NAME) and assert the rendered output — plus source assertions
 * for the auth-gated onboarding page and the DB-heavy installment receipt,
 * which are not cheaply renderable without mutating data.
 */
class BrandCasingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Simulate the deployed app name; the guest layout reads config('app.name').
        config(['app.name' => 'JewelFlows']);
    }

    public function test_login_page_renders_canonical_brand(): void
    {
        $response = $this->get('/login');

        $response->assertOk();
        $response->assertSee('JewelFlows');              // config-driven title / heading / footer
        $response->assertSee('New to JewelFlows?');      // literal prose
        $response->assertSee('Jewel<span>Flows</span>', false); // stylized wordmark
        $response->assertDontSee('Jewelflows');          // no mis-cased plain text
        $response->assertDontSee('Jewel<span>flows</span>', false); // no mis-cased wordmark
    }

    public function test_register_page_renders_canonical_brand(): void
    {
        $response = $this->get('/register');

        $response->assertOk();
        $response->assertSee('JewelFlows');
        $response->assertSee('Set up your JewelFlows account in a minute.');
        $response->assertDontSee('Jewelflows');
        $response->assertDontSee('Jewel<span>flows</span>', false);
    }

    public function test_choose_type_source_uses_canonical_brand(): void
    {
        $source = file_get_contents(resource_path('views/shops/choose-type.blade.php'));

        $this->assertStringNotContainsString('Jewelflows', $source);
        $this->assertStringNotContainsString('Jewel<span>flows</span>', $source);
        $this->assertStringContainsString('Jewel<span>Flows</span>', $source);
        $this->assertStringContainsString("config('app.name', 'JewelFlows')", $source);
    }

    public function test_installment_receipt_fallback_uses_canonical_brand(): void
    {
        $source = file_get_contents(resource_path('views/installments/receipt.blade.php'));

        $this->assertStringNotContainsString("'Jewelflows'", $source);
        $this->assertStringContainsString("config('app.name', 'JewelFlows')", $source);
    }
}
