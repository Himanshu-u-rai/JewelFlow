<?php

namespace Tests\Feature\Repair;

use App\Models\Repair;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

class RepairWorkspaceTest extends TestCase
{
    use CreatesTestTenant, RefreshDatabase;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        $this->withoutVite();
    }

    public function test_intake_is_a_native_open_accordion_without_changing_form_submission_or_view_permissions(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $response = TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)->get(route('repairs.index')))->assertOk();
        $dom = new \DOMDocument;
        @$dom->loadHTML($response->getContent());
        $xp = new \DOMXPath($dom);
        $this->assertSame(1, $xp->query('//details[@data-repair-intake and @open]/summary')->length);
        $this->assertSame(1, $xp->query('//details//form[@method="POST" and @enctype="multipart/form-data" and @action="'.route('repairs.store').'"]//input[@name="_token"]')->length);
        foreach (['customer_id', 'item_description', 'gross_weight', 'image', 'description'] as $name) {
            $this->assertSame(1, $xp->query('//details//*[@name="'.$name.'"]')->length);
        }
        $this->grantOnlyPermissions($owner, ['repairs.view']);
        TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)->get(route('repairs.index')))
            ->assertOk()->assertDontSee('<details', false);
    }

    public function test_fifteen_row_web_pages_have_stable_continuing_indices_without_changing_json_page_size(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $customer = $this->createCustomer($shop->id);
        $this->travelTo(now()->startOfSecond());
        $ids = TenantContext::runFor($shop->id, function () use ($customer) {
            $ids = [];
            for ($i = 1; $i <= 31; $i++) {
                $ids[] = Repair::create(['customer_id' => $customer->id, 'item_description' => 'Synthetic repair '.$i,
                    'gross_weight' => 2, 'status' => 'received'])->id;
            }
            return $ids;
        });
        $seen = [];
        foreach ([1 => range(1, 15), 2 => range(16, 30), 3 => [31]] as $page => $indices) {
            $response = TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)->get(route('repairs.index', ['page' => $page])))->assertOk();
            $paginator = $response->viewData('repairs');
            $this->assertSame(15, $paginator->perPage());
            $this->assertSame($indices, range($paginator->firstItem(), $paginator->lastItem()));
            $seen = [...$seen, ...$paginator->pluck('id')->all()];
        }
        $this->assertSame(array_reverse($ids), $seen, 'Same-second intakes must not repeat or disappear across pages');
        TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)->getJson(route('repairs.index')))
            ->assertOk()->assertJsonPath('per_page', 25)->assertJsonPath('total', 31)->assertJsonCount(25, 'data');
        $this->travelBack();
    }
}
