<?php

namespace Tests\Feature\StoreCredit;

use App\Models\Customer;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * UI audit F11: the store-credit adjustment page is a compact form with a
 * persistent audited-action note, the standard orange primary, one Cancel,
 * and a normal-sized amount input. Behaviour (route, fields) is unchanged.
 */
class AdjustPageLayoutTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestTenant;

    private const ERP = 'https://jewelflows.com';

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    private function html(): string
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $cust = TenantContext::runFor($shop->id, fn () => Customer::create([
            'shop_id' => $shop->id, 'first_name' => 'Layout', 'last_name' => 'Cust', 'mobile' => '9825500070',
        ]));

        $res = TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)
            ->get(self::ERP . '/customers/' . $cust->id . '/store-credit/adjust'));
        $res->assertOk();

        return $res->getContent();
    }

    public function test_page_layout_and_controls(): void
    {
        $html = $this->html();

        // Primary submit: standard orange primary, no green.
        $this->assertMatchesRegularExpression('/<button[^>]*type="submit"[^>]*class="[^"]*\bbtn-primary\b[^"]*"[^>]*>\s*Record Adjustment/s', $html);
        $this->assertStringNotContainsString('bg-green', $html);
        $this->assertStringNotContainsString('bg-emerald', $html);

        // Exactly one Cancel on the page (links); the layout's shared confirm dialog has its own Cancel button.
        $this->assertSame(1, preg_match_all('#<a\b[^>]*>\s*Cancel\s*</a>#', $html));

        // Persistent audited-action note (in the page, not a toast).
        $this->assertStringContainsString('Owner-only action', $html);
        $this->assertStringContainsString('audited', $html);
        $this->assertStringContainsString('role="note"', $html);

        // Amount input no longer oversized.
        $this->assertDoesNotMatchRegularExpression('/id="store-credit-amount"[^>]*class="[^"]*\btext-(2|3|4)xl\b/', $html);
        $this->assertDoesNotMatchRegularExpression('/class="[^"]*\btext-(2|3|4)xl\b[^"]*"[^>]*id="store-credit-amount"/', $html);

        // Labels intact and field names unchanged.
        $this->assertStringContainsString('for="store-credit-amount"', $html);
        $this->assertStringContainsString('for="store-credit-notes"', $html);
        $this->assertStringContainsString('name="direction" value="credit"', $html);
        $this->assertStringContainsString('name="direction" value="debit"', $html);
        $this->assertStringContainsString('name="amount"', $html);
        $this->assertStringContainsString('name="notes"', $html);
        $this->assertStringContainsString('Current balance', $html);
    }

    public function test_balance_is_a_small_fact_not_a_big_card(): void
    {
        $src = file_get_contents(resource_path('views/store-credit/adjust.blade.php'));
        $this->assertStringNotContainsString('text-2xl', $src);
        $this->assertStringNotContainsString('bg-emerald', $src);
        $this->assertStringNotContainsString('bg-green', $src);
    }
}
