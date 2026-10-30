<?php

namespace Tests\Feature;

use App\Models\QuickBill;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * An empty or ₹0 quick bill must never be booked from the app. Blank lines
 * are dropped before saving, so a bill of only blank lines has no items; an
 * issued bill also needs a value and some payment. The app checks the same
 * rules first (src/lib/quick-bill-submit-check.ts); this proves the API holds
 * them even if a client skips its checks.
 */
class MobileQuickBillEmptyBillTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    public function test_a_bill_of_only_blank_lines_is_refused_as_draft_or_issue(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        Sanctum::actingAs($user);

        foreach (['draft', 'issue'] as $action) {
            $payload = $this->payload($action);
            $payload['items'] = [['description' => '   ', 'rate' => 1000, 'gross_weight' => 1]];

            $this->postJson('/api/mobile/quick-bills', $payload)
                ->assertStatus(422)
                ->assertJsonValidationErrors('items');
        }

        $this->assertSame(0, QuickBill::withoutGlobalScopes()->where('shop_id', $shop->id)->count());
    }

    public function test_a_zero_value_bill_cannot_be_issued(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        Sanctum::actingAs($user);

        $payload = $this->payload('issue');
        $payload['items'][0]['rate'] = 0;
        $payload['payments'] = [];

        $this->postJson('/api/mobile/quick-bills', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors('items');

        $this->assertSame(0, QuickBill::withoutGlobalScopes()->where('shop_id', $shop->id)->count());
    }

    public function test_an_unpaid_bill_cannot_be_issued_but_a_partly_paid_one_can(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        Sanctum::actingAs($user);

        $unpaid = $this->payload('issue');
        $unpaid['payments'] = [];
        $this->postJson('/api/mobile/quick-bills', $unpaid)
            ->assertStatus(422)
            ->assertJsonValidationErrors('payments');

        $partly = $this->payload('issue');
        $partly['payments'][0]['amount'] = 400;
        $this->postJson('/api/mobile/quick-bills', $partly)->assertCreated();

        $this->assertSame(1, QuickBill::withoutGlobalScopes()->where('shop_id', $shop->id)->count());
    }

    private function payload(string $action): array
    {
        return [
            'bill_date' => now()->toDateString(),
            'pricing_mode' => 'no_gst',
            'gst_rate' => 0,
            'round_off' => 0,
            'save_action' => $action,
            'customer_name' => 'Synthetic Walk-in',
            'items' => [[
                'description' => 'Synthetic quick bill item',
                'pcs' => 1,
                'gross_weight' => 1,
                'stone_weight' => 0,
                'net_weight' => 1,
                'rate' => 1000,
            ]],
            'payments' => [[
                'payment_mode' => 'cash',
                'amount' => 1000,
            ]],
        ];
    }
}
