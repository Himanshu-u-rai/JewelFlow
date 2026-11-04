<?php

namespace Tests\Feature\Tables;

use App\Models\JobOrder;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

class VaultWorkspaceUiTest extends TestCase
{
    use CreatesTestTenant, RefreshDatabase;

    public function test_every_purity_including_other_materials_is_rendered_once_in_visible_groups(): void
    {
        [$owner] = $this->createRetailerTenant();
        $this->actingAs($owner)->withoutVite();
        $rows = collect(range(1, 42))->map(fn ($i) => [
            'metal_type' => $i <= 22 ? 'gold' : 'Synthetic other <metal>',
            'purity' => $i, 'lots_count' => 1,
            'in_vault_fine' => 12.345, 'with_karigar_fine' => 2.125, 'total_fine' => 14.470,
        ]);
        $xp = $this->xpath($this->renderWorkspace($rows));
        $this->assertSame(22, $xp->query('//*[@id="vault-primary-balances"]//tbody/tr')->length);
        $this->assertSame(20, $xp->query('//*[@id="vault-other-balances"]//tbody/tr')->length);
        $this->assertSame(1, $xp->query('//details[@data-vault-other-materials and @open]')->length);
        $this->assertSame(0, $xp->query('//*[@id="vault-other-balances"]//metal')->length, 'Material labels are escaped.');
        $this->assertStringContainsString('Synthetic other <metal>', $xp->evaluate('string(//*[@id="vault-other-balances"])'));
        $this->assertSame('518.490 g', trim($xp->evaluate('string(//*[@data-vault-summary="in-vault"])')));
        $this->assertSame('89.250 g', trim($xp->evaluate('string(//*[@data-vault-summary="karigar"])')));
        $this->assertSame('607.740 g', trim($xp->evaluate('string(//*[@data-vault-summary="total"])')));
    }

    public function test_other_only_and_empty_balances_do_not_leave_a_blank_primary_section(): void
    {
        [$owner] = $this->createRetailerTenant();
        $this->actingAs($owner)->withoutVite();
        $other = collect([['metal_type' => null, 'purity' => 0, 'lots_count' => 1,
            'in_vault_fine' => 1, 'with_karigar_fine' => 0, 'total_fine' => 1]]);
        $xp = $this->xpath($this->renderWorkspace($other));
        $this->assertSame(0, $xp->query('//*[@id="vault-primary-balances"]')->length);
        $this->assertSame(1, $xp->query('//*[@id="vault-other-balances"]//tbody/tr')->length);
        $this->assertStringContainsString('Unknown material', $xp->evaluate('string(//*[@id="vault-other-balances"])'));
        $xp = $this->xpath($this->renderWorkspace(collect()));
        $this->assertSame(0, $xp->query('//*[@id="vault-primary-balances" or @id="vault-other-balances"]')->length);
        $this->assertStringContainsString('No purity balances yet', $xp->evaluate('string(//main)'));
    }

    public function test_real_index_keeps_one_lot_list_with_continuous_indices_and_all_balance_fields(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        for ($i = 1; $i <= 24; $i++) {
            $this->createMetalLot($shop->id, 12.345);
        }
        $this->withoutVite();
        $response = TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)->get(route('vault.index')))->assertOk();
        $xp = $this->xpath($response->getContent());
        $this->assertSame(1, $xp->query('//table[@id="vault-lots-table"]')->length);
        $this->assertSame(array_map('strval', range(1, 24)), array_map(fn ($n) => trim($n->textContent), iterator_to_array($xp->query('//*[@id="vault-lots-table"]//td[@class="jf-table-index"]'))));
        foreach (['Source', 'Vendor', 'Metal', 'Purity', 'Total fine', 'Remaining fine', 'Issued', 'Status'] as $field) {
            $this->assertSame(24, $xp->query('//*[@id="vault-lots-table"]//td[@data-label="'.$field.'"]')->length);
        }
        $this->assertSame(24, $xp->query('//*[@id="vault-lots-table"]//a[contains(@href,"/vault/lots/")]')->length);
        $this->assertSame(3, $xp->query('//*[@aria-label="Vault sections"]//button[@aria-controls and @aria-pressed]')->length);
    }

    public function test_view_only_users_get_ledger_but_not_mutation_or_inaccessible_job_links(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $this->grantOnlyPermissions($owner, ['vault.view']);
        $owner->unsetRelation('role');
        $this->withoutVite();
        $html = TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)->get(route('vault.index')))->assertOk()->getContent();
        $xp = $this->xpath($html);
        $this->assertGreaterThan(0, $xp->query('//a[@href="'.route('vault.ledger').'"]')->length);
        foreach (['vault.lots.create', 'job-orders.create', 'job-orders.index'] as $route) {
            $this->assertSame(0, $xp->query('//*[contains(@class,"vault-page")]//a[@href="'.route($route).'"]')->length, $route);
        }
    }

    public function test_populated_jobs_and_movements_preserve_fields_indices_and_scoped_navigation(): void
    {
        [$owner] = $this->createRetailerTenant();
        $this->actingAs($owner)->withoutVite();
        $job = (new JobOrder)->forceFill(['id' => 101, 'job_order_number' => 'Synthetic job 101',
            'metal_type' => 'silver', 'purity' => 925, 'issued_fine_weight' => 20,
            'returned_fine_weight' => 3, 'leftover_returned_fine_weight' => 2,
            'actual_wastage_fine' => 1, 'status' => 'partial_return']);
        $job->setRelation('karigar', null);
        $movement = (object) ['created_at' => now(), 'type' => 'job_return', 'from_lot_id' => 101,
            'to_lot_id' => 102, 'fine_weight' => 3.125, 'reference_type' => 'job_order', 'reference_id' => 101];
        $extra = ['openJobs' => collect([$job]), 'recentMovements' => collect([$movement])];
        $xp = $this->xpath($this->renderWorkspace(collect(), $extra));
        $this->assertSame(1, $xp->query('//*[@id="vault-jobs-table"]//tbody/tr')->length);
        $this->assertSame('14.000 g', trim($xp->evaluate('string(//*[@id="vault-jobs-table"]//td[@data-label="Outstanding fine"])')));
        $this->assertSame('925', trim($xp->evaluate('string(//*[@id="vault-jobs-table"]//td[@data-label="Purity"])')));
        $this->assertSame('3.125 g', trim($xp->evaluate('string(//*[@id="vault-movements-table"]//td[@data-label="Fine weight"])')));
        $this->assertSame('job_order#101', trim($xp->evaluate('string(//*[@id="vault-movements-table"]//td[@data-label="Reference"])')));
        foreach (['jobs', 'movements'] as $table) {
            $this->assertSame('1', trim($xp->evaluate('string(//*[@id="vault-'.$table.'-table"]//td[@class="jf-table-index"])')));
        }
        $this->assertSame(1, $xp->query('//*[@id="vault-jobs-table"]//a')->length);
        $this->grantOnlyPermissions($owner, ['vault.view', 'vault.manage']);
        $owner->unsetRelation('role');
        $xp = $this->xpath($this->renderWorkspace(collect(), $extra));
        $this->assertSame(0, $xp->query('//*[@id="vault-jobs-table"]//a')->length);
        $this->assertSame(0, $xp->query('//*[contains(@class,"vault-page")]//a[@href="'.route('job-orders.create').'"]')->length);
        $this->assertGreaterThan(0, $xp->query('//*[contains(@class,"vault-page")]//a[@href="'.route('vault.lots.create').'"]')->length);
    }

    private function renderWorkspace($balances, array $extra = []): string
    {
        [$primaryBalances, $otherBalances] = $balances->partition(fn ($r) => $r['metal_type'] === 'gold');

        return TenantContext::runFor(auth()->user()->shop_id, fn () => view('vault.index', array_merge(['balances' => $balances, 'primaryBalances' => $primaryBalances,
            'otherBalances' => $otherBalances, 'lots' => collect(), 'openJobs' => collect(),
            'recentMovements' => collect()], $extra))->render());
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
