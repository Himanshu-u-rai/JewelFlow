<?php

namespace Tests\Feature\Vault;

use App\Models\MetalMovement;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

class VaultLedgerLotUiTest extends TestCase
{
    use CreatesTestTenant, RefreshDatabase;

    public function test_ledger_preserves_filters_pagination_values_and_shop_isolation(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $lot = $this->createMetalLot($shop->id, 100);
        for ($i = 1; $i <= 51; $i++) {
            MetalMovement::record(['shop_id' => $shop->id, 'to_lot_id' => $lot->id,
                'fine_weight' => 1.234567, 'type' => 'purchase', 'reference_type' => 'metal_lot',
                'reference_id' => $lot->id, 'user_id' => $owner->id]);
        }
        [, $other] = $this->createRetailerTenant();
        MetalMovement::record(['shop_id' => $other->id, 'fine_weight' => 999,
            'type' => 'buyback', 'reference_type' => 'foreign_private', 'reference_id' => 1]);
        $this->withoutVite();
        $response = TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)
            ->get('/vault/ledger?type=purchase&from='.now()->toDateString().'&page=2'))->assertOk();
        $response->assertDontSee('foreign_private');
        $xp = $this->xpath($response->getContent());
        $this->assertSame(1, $xp->query('//*[@id="vault-ledger-table"]//tbody/tr')->length);
        $this->assertSame('1.235 g', trim($xp->evaluate('string(//td[@data-label="Fine weight"])')));
        $this->assertSame(1, $xp->query('//dialog[@id="vault-ledger-filters"]//form[@method="GET"]')->length);
        $this->assertSame('purchase', $xp->evaluate('string(//select[@name="type"]/option[@selected]/@value)'));
        $this->assertGreaterThan(0, $xp->query('//footer//a[contains(@href,"type=purchase") and contains(@href,"from=")]')->length);
        $this->get('/vault/ledger?type=unmatched')->assertOk()->assertSee('No movements match your filters.');
    }

    public function test_bullion_form_retains_all_fields_and_validation_recovery_without_posting(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $this->withoutVite();
        $response = TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)
            ->withSession(['_old_input' => ['metal_type' => 'silver', 'source' => 'buyback',
                'gross_weight' => '12.345', 'purity' => '958', 'cost_per_gram' => '100.25',
                'payment_mode' => 'bank', 'notes' => 'Retained <notes>']])
            ->get(route('vault.lots.create')))->assertOk();
        $xp = $this->xpath($response->getContent());
        $this->assertSame(1, $xp->query('//form[@method="POST" and contains(@action,"/vault/lots")]')->length);
        foreach (['metal_type', 'source', 'gross_weight', 'purity', 'cost_per_gram', 'payment_mode', 'vendor_id', 'notes'] as $name) {
            $this->assertSame(1, $xp->query('//*[@name="'.$name.'"]')->length, $name);
        }
        $this->assertSame('Retained <notes>', $xp->evaluate('string(//textarea[@name="notes"])'));
        $this->assertSame('bank', $xp->evaluate('string(//select[@name="payment_mode"]/option[@selected]/@value)'));
        $this->assertSame(1, $xp->query('//aside[@aria-labelledby="vault-lot-summary-title"]')->length);
        $this->grantOnlyPermissions($owner, ['vault.view']);
        $owner->unsetRelation('role');
        $this->get(route('vault.lots.create'))->assertForbidden();
        $this->get(route('vault.ledger'))->assertOk();
    }

    private function xpath(string $html): \DOMXPath
    {
        $dom = new \DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?>'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        return new \DOMXPath($dom);
    }
}
