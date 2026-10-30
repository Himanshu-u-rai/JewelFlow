<?php

namespace Tests\Feature\Mobile\V1;

use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\ReturnOrder;
use App\Models\ShopPreferences;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Approving a return on the app = the web's "Review Pending Return" page: the
 * same review details (why approval is needed, items, estimated refund,
 * settlement, who submitted), the same optional per-line refund adjustments on
 * approve, and Reject with a reason. One code path with the web. Retail shops.
 */
class ReturnApprovalReviewTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    private const ERP = 'https://jewelflows.com';

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        $this->withoutMiddleware([
            \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
            \Illuminate\Routing\Middleware\ThrottleRequests::class,
        ]);
    }

    /** A retail sale of one ₹10,000 item, returned by staff, waiting for approval. */
    private function pendingReturn(): array
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $role = \App\Models\Role::withoutTenant()->findOrFail($owner->role_id);
        foreach (['sales.view', 'sales.create', 'returns.view', 'returns.create', 'returns.approve'] as $p) {
            $role->givePermission($p);
        }
        $owner = $owner->fresh();
        $this->seedRetailerPricing($shop, $owner);
        $customer = $this->createCustomer($shop->id);
        $item = $this->createItem($shop->id, null, ['selling_price' => 10000, 'source' => 'purchase', 'barcode' => 'RET-REVIEW-1']);

        $sale = $this->actingAs($owner)->postJson('/pos/sell', [
            'customer_id' => $customer->id, 'item_ids' => [$item->id], 'discount' => 0, 'round_off' => 0,
            'payments' => [['mode' => 'cash', 'amount' => 10300]],
        ])->assertOk();
        $line = TenantContext::runFor($shop->id, fn () => InvoiceItem::where('invoice_id', $sale->json('invoice_id'))->firstOrFail());

        TenantContext::runFor($shop->id, function () use ($shop) {
            ShopPreferences::firstOrNew(['shop_id' => $shop->id])->forceFill([
                'shop_id' => $shop->id, 'refund_making_charges' => true, 'refund_stone_charges' => true,
                'refund_gst' => true, 'wear_loss_pct' => 0, 'restocking_fee_pct' => 0,
                'return_settlement_mode' => 'cash_or_credit', 'return_policy_configured_at' => now(),
                'approval_threshold_amount' => 1, // every return needs approval
            ])->save();
        });

        Sanctum::actingAs($owner);
        $created = $this->withHeaders(['X-Idempotency-Key' => 'ret-' . uniqid()])->postJson('/api/mobile/v1/returns', [
            'invoice_id' => $line->invoice_id, 'reason' => 'Customer changed mind', 'refund_settlement' => 'cash',
            'lines' => [['invoice_item_id' => $line->id, 'condition' => 'good_condition']],
        ])->assertCreated();
        $this->assertSame(ReturnOrder::STATUS_PENDING_APPROVAL, $created->json('data.status'));
        // Route-model binding of {returnOrder} needs the tenant set under PHPUnit.
        TenantContext::set((int) $shop->id);
        $id = ReturnOrder::withoutGlobalScopes()->where('invoice_id', $line->invoice_id)->firstOrFail()->id;

        return [$owner, $shop, (int) $id, $line];
    }

    private function act(string $what, int $id, array $body = [])
    {
        // Each request clears the tenant; route-model binding needs it set again.
        TenantContext::set((int) ReturnOrder::withoutGlobalScopes()->findOrFail($id)->shop_id);

        return $this->withHeaders(['X-Idempotency-Key' => "ret-{$what}-" . uniqid()])
            ->postJson("/api/mobile/v1/returns/{$id}/{$what}", $body);
    }

    public function test_the_app_gets_the_web_review_details_of_a_pending_return(): void
    {
        [$owner, , $id, $line] = $this->pendingReturn();

        $review = $this->getJson("/api/mobile/v1/returns/{$id}")->assertOk()->json('data.review');

        $this->assertNotEmpty($review['approval_reason']);
        $this->assertGreaterThan(0, $review['estimated_refund']);
        $this->assertSame('cash', $review['settlement']);
        $this->assertSame($owner->name, $review['submitted_by']);
        $row = $review['lines'][0];
        $this->assertSame($line->id, $row['invoice_item_id']);
        $this->assertSame('RET-REVIEW-1', $row['barcode']);
        $this->assertSame('good_condition', $row['condition']);
        $this->assertGreaterThan(0, $row['refund_total']);
        $this->assertEqualsWithDelta(10300, $row['max_refundable'], 0.01);
    }

    public function test_approve_takes_the_web_refund_adjustments_for_a_line(): void
    {
        [, $shop, $id, $line] = $this->pendingReturn();

        // A reason is required for any adjustment, as on the web.
        $this->act('approve', $id, ['lines' => [['invoice_item_id' => $line->id, 'override_manual_total' => 9000, 'override_reason' => 'no']]])
            ->assertStatus(422);

        $this->act('approve', $id, ['lines' => [[
            'invoice_item_id' => $line->id, 'override_manual_total' => 9000, 'override_reason' => 'Agreed with the customer',
        ]]])->assertOk()->assertJsonPath('data.status', ReturnOrder::STATUS_SETTLED);

        // Approval settles into a new return record; the shop's one credit note is on it.
        // Every item on the bill came back: the adjustment used to be dropped here
        // (createFullReturn shortcut) and the customer got the full ₹10,300.
        $cn = TenantContext::runFor($shop->id, fn () => CreditNote::where('shop_id', $shop->id)->sole());
        $this->assertEqualsWithDelta(9000, (float) $cn->total, 0.01);
        TenantContext::runFor($shop->id, function () use ($shop, $line) {
            $this->assertSame(Invoice::STATUS_CANCELLED, Invoice::findOrFail($line->invoice_id)->status);
            $out = \App\Models\CashTransaction::where('shop_id', $shop->id)->where('type', 'out')->sum('amount');
            $this->assertEqualsWithDelta(9000, (float) $out, 0.01); // the cash refund is the adjusted amount
            $this->assertSame(1, \App\Models\AuditLog::where('action', 'return_refund_overridden')->count());
        });
    }

    public function test_reject_with_a_reason_as_on_the_web(): void
    {
        [, $shop, $id] = $this->pendingReturn();

        $this->act('reject', $id, ['rejection_reason' => 'no'])->assertStatus(422);
        $this->act('reject', $id, ['rejection_reason' => 'Item was worn and scratched'])
            ->assertOk()->assertJsonPath('data.status', ReturnOrder::STATUS_CANCELLED)
            ->assertJsonPath('data.cancellation_reason', 'Rejected by approver: Item was worn and scratched');

        $this->act('reject', $id, ['rejection_reason' => 'Item was worn and scratched'])->assertStatus(409);
        $this->assertSame(0, TenantContext::runFor($shop->id, fn () => CreditNote::where('shop_id', $shop->id)->count()));
    }

    public function test_reject_needs_the_approve_permission(): void
    {
        [, $shop, $id] = $this->pendingReturn();
        $clerk = TenantContext::runFor($shop->id, function () use ($shop) {
            $role = (new \App\Models\Role())->forceFill(['name' => 'clerk', 'display_name' => 'Clerk', 'shop_id' => $shop->id]);
            $role->save();
            $role->permissions()->sync(\App\Models\Permission::whereIn('name', ['sales.view'])->pluck('id'));

            return \App\Models\User::factory()->create(['shop_id' => $shop->id, 'role_id' => $role->id, 'is_active' => true]);
        });
        Sanctum::actingAs($clerk);

        $this->act('reject', $id, ['rejection_reason' => 'Item was worn and scratched'])->assertForbidden();
    }

    public function test_the_web_review_page_shows_the_same_items_refund_and_limits(): void
    {
        [$owner, $shop, $id, $line] = $this->pendingReturn();

        TenantContext::runFor($shop->id, fn () => $this->actingAs($owner, 'web')->get(self::ERP . "/returns/{$id}/approve-review"))
            ->assertOk()
            ->assertSee('RET-REVIEW-1')                       // was "#<id>": barcodes were never stored
            ->assertSee('₹10,300.00', false)                  // estimated refund, was "Computed at approval"
            ->assertSee('max="10300"', false)                 // manual refund limit, was 0
            ->assertSee('name="lines[0][invoice_item_id]" value="' . $line->id . '"', false) // the adjustment says which line
            ->assertSee('Approval required because');
    }

    public function test_the_web_approve_still_takes_line_adjustments(): void
    {
        [$owner, $shop, $id, $line] = $this->pendingReturn();
        $order = ReturnOrder::withoutGlobalScopes()->findOrFail($id);

        // 'web': the app calls above made Sanctum the default guard.
        TenantContext::runFor($shop->id, fn () => $this->actingAs($owner, 'web')->post(self::ERP . "/returns/{$order->id}/approve", [
            'lines' => [['invoice_item_id' => $line->id, 'override_manual_total' => 8000, 'override_reason' => 'Agreed with the customer']],
        ]))->assertRedirect()->assertSessionMissing('error');

        $this->assertEqualsWithDelta(8000, (float) TenantContext::runFor($shop->id, fn () => CreditNote::where('shop_id', $shop->id)->sole()->total), 0.01);
    }
}
