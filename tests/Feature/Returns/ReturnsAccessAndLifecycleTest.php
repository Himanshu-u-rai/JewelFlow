<?php

namespace Tests\Feature\Returns;

use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Permission;
use App\Models\Role;
use App\Models\ReturnLineItem;
use App\Models\ReturnOrder;
use App\Models\Shop;
use App\Models\ShopPreferences;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Returns / Exchanges — end-to-end lifecycle, tenant isolation & permission
 * gating (Module 9). Complements RefundPolicyMathTest (refund math),
 * InvoiceItemReturnGuardTest (column immutability), StoreCreditReconnectionTest
 * (non-negative ledger) and the mobile ReturnsApiTest with the untested web
 * surface: a real HTTP full return through the controller, double-return
 * prevention, over-refund ceiling, cross-shop isolation, and the returns.*
 * permission gates.
 */
class ReturnsAccessAndLifecycleTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestTenant;

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

    /** A user in $shop holding exactly the given permission names. */
    private function userWithPerms(Shop $shop, array $perms, string $mobile): User
    {
        return TenantContext::runFor($shop->id, function () use ($shop, $perms, $mobile) {
            $role = (new Role())->forceFill(['name' => 'r' . $mobile, 'display_name' => 'R', 'shop_id' => $shop->id]);
            $role->save();
            $role->permissions()->sync(Permission::whereIn('name', $perms)->pluck('id'));

            return User::create([
                'name' => 'U' . $mobile, 'mobile_number' => $mobile, 'password' => Hash::make('password'),
                'realm' => 'erp', 'is_active' => true, 'shop_id' => $shop->id, 'role_id' => $role->id,
            ]);
        });
    }

    /** Stamp a configured, refund-everything return policy so the create/store gate passes. */
    private function configureReturnPolicy(Shop $shop): void
    {
        TenantContext::runFor($shop->id, function () use ($shop) {
            $prefs = ShopPreferences::firstOrNew(['shop_id' => $shop->id]);
            $prefs->forceFill([
                'shop_id' => $shop->id,
                'refund_making_charges' => true,
                'refund_stone_charges' => true,
                'refund_gst' => true,
                'wear_loss_pct' => 0,
                'restocking_fee_pct' => 0,
                'return_settlement_mode' => 'cash_or_credit',
                'return_policy_configured_at' => now(),
            ])->save();
        });
    }

    /** Sell an item on the manufacturer path → returns [owner, shop, finalized Invoice, line]. */
    private function soldInvoice(): array
    {
        [$user, $shop] = $this->createManufacturerTenant();
        $lot = $this->createMetalLot($shop->id);
        $customer = $this->createCustomer($shop->id);
        $item = $this->createItem($shop->id, $lot->id);

        $preview = $this->actingAs($user)->postJson('/api/price-preview', [
            'item_id' => $item->id, 'customer_id' => $customer->id,
            'gold_rate' => 6000, 'making' => 500, 'stone' => 200, 'discount' => 0, 'round_off' => 0,
        ]);
        $total = (float) $preview->json('total');

        $sell = $this->actingAs($user)->postJson('/pos/sell', [
            'customer_id' => $customer->id, 'item_id' => $item->id,
            'gold_rate' => 6000, 'making' => 500, 'stone' => 200, 'discount' => 0, 'round_off' => 0,
            'payments' => [['mode' => 'cash', 'amount' => $total]],
        ])->assertOk();

        $invoice = TenantContext::runFor($shop->id, fn () => Invoice::findOrFail($sell->json('invoice_id')));
        $line = TenantContext::runFor($shop->id, fn () => InvoiceItem::where('invoice_id', $invoice->id)->firstOrFail());

        return [$user, $shop, $invoice, $line];
    }

    private function returnPayload(InvoiceItem $line): array
    {
        return [
            'reason' => 'Customer changed mind',
            'lines' => [[
                'invoice_item_id' => $line->id,
                'condition' => ReturnLineItem::CONDITION_GOOD,
                'disposition' => \App\Models\ReturnedItemDisposition::DISPOSITION_RESTOCKED,
            ]],
            'refund_settlement' => 'cash',
        ];
    }

    // ── End-to-end lifecycle ───────────────────────────────────────────────

    public function test_full_return_creates_order_credit_note_and_marks_line_returned(): void
    {
        [$owner, $shop, $invoice, $line] = $this->soldInvoice();
        $this->configureReturnPolicy($shop);

        TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)
            ->post(self::ERP . '/invoices/' . $invoice->id . '/returns', $this->returnPayload($line)))
            ->assertRedirect();

        $order = ReturnOrder::withoutGlobalScopes()->where('invoice_id', $invoice->id)->first();
        $this->assertNotNull($order, 'return order created');
        $this->assertSame($shop->id, $order->shop_id);

        $cn = CreditNote::withoutGlobalScopes()->where('return_order_id', $order->id)->first();
        $this->assertNotNull($cn, 'credit note issued');
        // Over-refund ceiling: the credit note can never exceed the invoice total.
        $this->assertLessThanOrEqual((float) $invoice->total + 0.001, (float) $cn->total, 'credit note must not exceed invoice');

        $this->assertNotNull($line->fresh()->returned_at, 'returned line is stamped');
    }

    public function test_double_return_of_same_line_is_blocked(): void
    {
        [$owner, $shop, $invoice, $line] = $this->soldInvoice();
        $this->configureReturnPolicy($shop);

        TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)
            ->post(self::ERP . '/invoices/' . $invoice->id . '/returns', $this->returnPayload($line)))
            ->assertRedirect();

        $orderIds = ReturnOrder::withoutGlobalScopes()->where('invoice_id', $invoice->id)->pluck('id');

        // Second attempt on the already-returned line is refused (LogicException
        // caught by the controller → back with an error), creating no 2nd CN.
        TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)
            ->post(self::ERP . '/invoices/' . $invoice->id . '/returns', $this->returnPayload($line)))
            ->assertSessionHasErrors();

        $this->assertSame(1, CreditNote::withoutGlobalScopes()->whereIn('return_order_id', $orderIds)->count(),
            'no duplicate credit note from a double return');
        $this->assertSame(1, ReturnLineItem::withoutGlobalScopes()->where('invoice_item_id', $line->id)->count(),
            'the line is returned exactly once');
    }

    public function test_return_show_and_index_render_for_owner(): void
    {
        [$owner, $shop, $invoice, $line] = $this->soldInvoice();
        $this->configureReturnPolicy($shop);

        TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)
            ->post(self::ERP . '/invoices/' . $invoice->id . '/returns', $this->returnPayload($line)))
            ->assertRedirect();
        $order = ReturnOrder::withoutGlobalScopes()->where('invoice_id', $invoice->id)->firstOrFail();

        TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)->get(self::ERP . '/returns'))->assertOk();
        TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)->get(self::ERP . '/returns/' . $order->id))->assertOk();
    }

    // ── Tenant isolation ───────────────────────────────────────────────────

    public function test_cannot_create_return_for_another_shops_invoice(): void
    {
        [, $shopB, $invoiceB, $lineB] = $this->soldInvoice();
        [, $shopA] = $this->createRetailerTenant();
        $userA = $this->userWithPerms($shopA, ['returns.create', 'returns.view'], '9814400010');

        TenantContext::runFor($shopA->id, fn () => $this->actingAs($userA)
            ->post(self::ERP . '/invoices/' . $invoiceB->id . '/returns', $this->returnPayload($lineB)))
            ->assertNotFound();
    }

    public function test_cannot_view_another_shops_return(): void
    {
        [$ownerB, $shopB, $invoiceB, $lineB] = $this->soldInvoice();
        $this->configureReturnPolicy($shopB);
        TenantContext::runFor($shopB->id, fn () => $this->actingAs($ownerB)
            ->post(self::ERP . '/invoices/' . $invoiceB->id . '/returns', $this->returnPayload($lineB)))
            ->assertRedirect();
        $orderB = ReturnOrder::withoutGlobalScopes()->where('invoice_id', $invoiceB->id)->firstOrFail();

        [, $shopA] = $this->createRetailerTenant();
        $userA = $this->userWithPerms($shopA, ['returns.view'], '9814400011');

        $res = TenantContext::runFor($shopA->id, fn () => $this->actingAs($userA)
            ->get(self::ERP . '/returns/' . $orderB->id));
        $this->assertContains($res->getStatusCode(), [403, 404], 'cross-shop return must not be viewable');
    }

    // ── Permission gating ──────────────────────────────────────────────────

    public function test_user_without_returns_create_cannot_start_return(): void
    {
        [, $shop, $invoice] = $this->soldInvoice();
        $noPerm = $this->userWithPerms($shop, ['returns.view'], '9814400020'); // no returns.create

        TenantContext::runFor($shop->id, fn () => $this->actingAs($noPerm)
            ->get(self::ERP . '/invoices/' . $invoice->id . '/returns/create'))->assertForbidden();
    }

    public function test_user_without_returns_view_cannot_see_index(): void
    {
        [, $shop] = $this->createRetailerTenant();
        $noPerm = $this->userWithPerms($shop, ['sales.pos'], '9814400021'); // no returns.*

        TenantContext::runFor($shop->id, fn () => $this->actingAs($noPerm)
            ->get(self::ERP . '/returns'))->assertForbidden();
    }

    public function test_user_without_returns_approve_cannot_reach_control_center(): void
    {
        [, $shop] = $this->createRetailerTenant();
        $noApprove = $this->userWithPerms($shop, ['returns.view', 'returns.create'], '9814400022');

        TenantContext::runFor($shop->id, fn () => $this->actingAs($noApprove)
            ->get(self::ERP . '/returns/control-center'))->assertForbidden();
    }

    public function test_guest_cannot_reach_returns(): void
    {
        $res = $this->get(self::ERP . '/returns');
        $res->assertRedirect();
        $this->assertStringContainsString('/login', (string) $res->headers->get('Location'));
    }
}
